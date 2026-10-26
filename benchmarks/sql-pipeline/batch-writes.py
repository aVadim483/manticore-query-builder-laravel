"""Compare native bulk compilation with the previous 4.x and an inherited Grammar."""
import argparse
import gzip
import hashlib
import json
import pathlib
import secrets
import statistics
import subprocess
import time

parser = argparse.ArgumentParser()
parser.add_argument('--v3', required=True)
parser.add_argument('--v4', required=True)
parser.add_argument('--before', required=True)
parser.add_argument('--after', required=True)
parser.add_argument('--output', required=True)
parser.add_argument('--samples', type=int, default=40)
args = parser.parse_args()
assert args.samples >= 10
folder = pathlib.Path(__file__).resolve().parent
run_id = secrets.token_hex(6)
output = pathlib.Path(args.output)
report = {'created_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
          'run_id': run_id, 'samples': args.samples, 'warmup': 5, 'environment': {},
          'manifests': {}, 'cases': {}}
for label, source in [('before', args.before), ('after', args.after)]:
    root = pathlib.Path(source)
    report['manifests'][label] = {str(p.relative_to(root)): hashlib.sha256(p.read_bytes()).hexdigest()
        for p in sorted(root.rglob('*')) if p.is_file()}

class Worker:
    def __init__(self, label, size):
        self.label = label
        self.client = 'v3' if label == 'v3' else 'v4'
        root = pathlib.Path(args.v3 if label == 'v3' else args.v4)
        source = '-' if label == 'v3' else (args.before if label == 'before' else args.after)
        self.table = f'pipe_{run_id}_{label}{size}'
        config = {'documents': size, 'read_table': 'unused', 'vector': [0.0], 'write_size': size,
            'native_builder': label != 'fallback', 'write_tables': {c: self.table for c in ['v3', 'v4', 'pdo']}}
        config_path = output.with_name(f'{run_id}-{label}-{size}.json')
        config_path.write_text(json.dumps(config))
        self.log = open(config_path.with_suffix('.stderr.log'), 'w')
        self.process = subprocess.Popen([str(root / 'bin/php'), '-d', 'xdebug.mode=off',
            '-d', 'memory_limit=768M', str(folder / 'worker.php'), str(root), self.client,
            str(config_path), source], cwd=root, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
            stderr=self.log, text=True, bufsize=1)
        line = self.process.stdout.readline()
        if not line:
            raise RuntimeError('Worker failed: ' + label)
        self.environment = json.loads(line)
        assert self.environment['ready'] and not self.environment['xdebug']
        if self.client == 'v4':
            core = pathlib.Path(source) / 'src/Manticore/Laravel/Database/ManticoreQueryBuilder.php'
            assert pathlib.Path(self.environment['source']).resolve() == core.resolve()
            assert self.environment['source_sha256'] == hashlib.sha256(core.read_bytes()).hexdigest()
            if label != 'fallback':
                assert self.environment['grammar_class'] == 'avadim\\Manticore\\Laravel\\Database\\ManticoreGrammar'

    def call(self, message):
        self.process.stdin.write(json.dumps(message) + '\n')
        self.process.stdin.flush()
        line = self.process.stdout.readline()
        if not line:
            raise RuntimeError('Worker exited: ' + self.label)
        value = json.loads(line)
        if not value.get('ok'):
            raise RuntimeError(self.label + ': ' + str(value))
        return value

    def close(self):
        if self.process.poll() is None:
            self.process.stdin.write('{"op":"quit"}\n')
            self.process.stdin.flush()
            self.process.wait(timeout=15)
        self.log.close()

def summarize(values, build):
    if build:
        times = sorted(t for v in values for t in v['times_ms'])
        return {'median_ms': statistics.median(times), 'samples': len(times)}
    metrics = {key: statistics.median(v['metrics'][key] for v in values)
               for key in values[0]['metrics'] if key != 'compilations'}
    times = sorted(v['metrics']['total_ms'] for v in values)
    metrics['p95_ms'] = times[(len(times) * 95 + 99) // 100 - 1]
    return metrics

for size in [1, 100, 500, 1000]:
    workers = {}
    try:
        for label in ['v3', 'before', 'after', 'fallback']:
            workers[label] = Worker(label, size)
            report['environment'][f'{label}_{size}'] = workers[label].environment
        for verb in ['insert', 'replace']:
            scenario = f'write_{verb}500'
            captures = {label: worker.call({'op': 'capture', 'scenario': scenario}) for label, worker in workers.items()}
            digests = {v['digest'] for v in captures.values()}
            assert len(digests) == 1, 'Data mismatch during capture'
            signatures = {}
            for label in ['before', 'after', 'fallback']:
                signatures[label] = [(r['sql'].replace(workers[label].table, 'scratch'), r['bindings'], r['execute_params'])
                                     for r in captures[label]['trace']]
            assert signatures['before'] == signatures['after'] == signatures['fallback'], 'SQL/binding types changed'
            # Preserve hashes/counts rather than repeating synthetic payloads in the report.
            slim_captures = {}
            for label, capture in captures.items():
                slim_captures[label] = {k: v for k, v in capture.items() if k != 'trace'}
                slim_captures[label]['trace'] = [{k: v for k, v in r.items() if k not in ['sql', 'literal_sql', 'bindings', 'execute_params']}
                    | {'sql_sha256': hashlib.sha256(r['sql'].encode()).hexdigest(), 'sql_prefix': r['sql'][:500]}
                    for r in capture['trace']]
            case = {'captures': slim_captures, 'phases': {}, 'matching_digest': next(iter(digests))}
            report['cases'][f'{verb}_{size}'] = case
            for phase in ['build', 'live', 'plain']:
                count = 10 if phase == 'build' else args.samples
                samples = {label: [] for label in workers}
                for index in range(count + 5):
                    labels = list(workers)
                    order = labels[index % len(labels):] + labels[:index % len(labels)]
                    for label in order:
                        value = workers[label].call({'op': phase, 'scenario': scenario, 'iterations': 10})
                        if phase != 'build':
                            assert value['digest'] in digests, 'Stored data differs: ' + label
                        if index >= 5:
                            samples[label].append(value)
                case['phases'][phase] = {'summary': {label: summarize(values, phase == 'build') for label, values in samples.items()},
                                         'samples': samples}
                output.write_text(json.dumps(report, indent=2))
                print(f'{verb}_{size}', phase, json.dumps(case['phases'][phase]['summary']), flush=True)
    finally:
        for worker in workers.values():
            worker.close()
report['ok'] = True
output.write_text(json.dumps(report, indent=2))
