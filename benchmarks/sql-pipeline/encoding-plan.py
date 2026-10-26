"""Measure per-column codec plans, including plan construction and unchanged SQL execution."""
import argparse
import hashlib
import json
import pathlib
import secrets
import statistics
import subprocess
import time

parser = argparse.ArgumentParser()
parser.add_argument('--app', required=True)
parser.add_argument('--variants', required=True)
parser.add_argument('--output', required=True)
parser.add_argument('--samples', type=int, default=60)
parser.add_argument('--profile-samples', type=int, default=20)
parser.add_argument('--sizes', nargs='+', type=int, default=[100, 500, 1000])
parser.add_argument('--labels', nargs='+', default=['baseline', 'closures', 'kinds'])
args = parser.parse_args()
assert args.samples >= 10 and args.profile_samples >= 5
labels = args.labels
assert labels[0] == 'baseline' and len(labels) >= 2 and len(set(labels)) == len(labels)
folder = pathlib.Path(__file__).resolve().parent
variants = pathlib.Path(args.variants)
output = pathlib.Path(args.output)
run_id = secrets.token_hex(6)
manifest = json.loads((variants / 'manifest.json').read_text())
report = {'created_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'run_id': run_id, 'labels': labels,
    'samples': args.samples, 'profile_samples': args.profile_samples, 'warmup': 5,
    'manifests': manifest, 'environment': {}, 'cases': {}}

class Worker:
    def __init__(self, label, fixture, size, mode):
        self.label = label
        self.table = f'pipe_{run_id}_{label}_{fixture}_{size}_{mode}'
        source_label = label + ('_timed' if mode == 'profile' else '')
        source = variants / source_label
        config = {'documents': size, 'read_table': 'unused', 'vector': [0.0], 'write_size': size,
            'native_builder': True, 'plain_pdo': mode != 'capture', 'codec_profile': mode == 'profile',
            'write_fixture': fixture, 'write_tables': {c: self.table for c in ['v3', 'v4', 'pdo']}}
        file = output.with_name(self.table + '.config.json')
        file.write_text(json.dumps(config))
        self.log = open(file.with_suffix('.stderr.log'), 'w')
        self.process = subprocess.Popen([str(pathlib.Path(args.app) / 'bin/php'), '-d', 'xdebug.mode=off',
            '-d', 'memory_limit=768M', str(folder / 'worker.php'), args.app, 'v4', str(file), str(source)],
            cwd=args.app, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=self.log, text=True, bufsize=1)
        line = self.process.stdout.readline()
        if not line:
            raise RuntimeError('Startup failed: ' + self.table)
        self.ready = json.loads(line)
        assert self.ready['ready'] and not self.ready['xdebug']
        core = 'src/Manticore/Laravel/Database/ManticoreQueryBuilder.php'
        codec = 'src/Manticore/Laravel/Database/AttributeCodec.php'
        assert pathlib.Path(self.ready['source']).resolve() == (source / core).resolve()
        assert self.ready['source_sha256'] == manifest[source_label][core]
        assert pathlib.Path(self.ready['codec_source']).resolve() == (source / codec).resolve()
        if mode != 'capture':
            assert self.ready['pdo_class'] == 'PDO' and self.ready['pdo_statement_class'] == 'PDOStatement'
        report['environment'][self.table] = self.ready

    def call(self, message):
        self.process.stdin.write(json.dumps(message) + '\n')
        self.process.stdin.flush()
        line = self.process.stdout.readline()
        if not line:
            raise RuntimeError('Worker exited: ' + self.table)
        value = json.loads(line)
        if not value.get('ok'):
            raise RuntimeError(self.table + ': ' + str(value))
        return value

    def close(self):
        if self.process.poll() is None:
            self.process.stdin.write('{"op":"quit"}\n')
            self.process.stdin.flush()
            self.process.wait(timeout=15)
        self.log.close()

def summarize(values):
    metrics = {key: statistics.median(v['metrics'][key] for v in values) for key in values[0]['metrics']}
    times = sorted(v['metrics']['total_ms'] for v in values)
    metrics['p95_ms'] = times[(len(times) * 95 + 99) // 100 - 1]
    return metrics

for fixture in ['structured', 'scalar']:
    for size in args.sizes:
        captures = {}
        for label in labels:
            worker = Worker(label, fixture, size, 'capture')
            try:
                captures[label] = {verb: worker.call({'op': 'capture', 'scenario': f'write_{verb}500'})
                                   for verb in ['insert', 'replace']}
                for value in captures[label].values():
                    for record in value['trace']:
                        record['sql'] = record['sql'].replace(worker.table, 'scratch')
            finally:
                worker.close()
        for verb in ['insert', 'replace']:
            signatures = {label: [(r['sql'], r['bindings'], r['execute_params']) for r in value[verb]['trace']]
                          for label, value in captures.items()}
            assert all(value == signatures['baseline'] for value in signatures.values()), 'SQL/bindings changed'
            assert len({value[verb]['digest'] for value in captures.values()}) == 1, 'Captured data differs'
            hashes = {label: hashlib.sha256(json.dumps(value, sort_keys=True).encode()).hexdigest()
                      for label, value in signatures.items()}
            report['cases'][f'{fixture}_{verb}_{size}'] = {
                'digest': captures['baseline'][verb]['digest'], 'sql_bindings_sha256': hashes, 'phases': {}}
        for mode in ['plain', 'profile']:
            workers = {}
            try:
                for label in labels:
                    workers[label] = Worker(label, fixture, size, mode)
                for verb in ['insert', 'replace']:
                    name = f'{fixture}_{verb}_{size}'
                    case = report['cases'][name]
                    values = {label: [] for label in workers}
                    count = args.samples if mode == 'plain' else args.profile_samples
                    for index in range(count + 5):
                        order = list(workers)
                        for label in order[index % len(order):] + order[:index % len(order)]:
                            value = workers[label].call({'op': 'plain', 'scenario': f'write_{verb}500'})
                            assert value['digest'] == case['digest'], 'Written data differs: ' + name + ' ' + label
                            if mode == 'profile':
                                assert value['metrics']['encode_ms'] > 0, 'Codec instrumentation missing'
                            if index >= 5:
                                values[label].append(value)
                    summary = {label: summarize(samples) for label, samples in values.items()}
                    paired = {label: statistics.median(b['metrics']['total_ms']-a['metrics']['total_ms']
                        for a, b in zip(values['baseline'], values[label])) for label in labels[1:]}
                    case['phases'][mode] = {'summary': summary, 'paired_difference_ms': paired, 'samples': values}
                    output.write_text(json.dumps(report, indent=2))
                    print(name, mode, json.dumps(summary), json.dumps(paired), flush=True)
            finally:
                for worker in workers.values():
                    worker.close()
report['ok'] = True
output.write_text(json.dumps(report, indent=2))
