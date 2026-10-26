"""Measure complete literal compilation/execution against bindings using real PDO."""
import argparse
import hashlib
import json
import pathlib
import secrets
import statistics
import subprocess
import time

parser = argparse.ArgumentParser()
parser.add_argument('--v3', required=True)
parser.add_argument('--app', required=True)
parser.add_argument('--source', required=True)
parser.add_argument('--candidate', required=True)
parser.add_argument('--output', required=True)
parser.add_argument('--samples', type=int, default=50)
parser.add_argument('--sizes', nargs='+', type=int, default=[1, 100, 500, 1000])
args = parser.parse_args()
assert args.samples >= 5 and all(size > 0 for size in args.sizes)
folder = pathlib.Path(__file__).resolve().parent
output = pathlib.Path(args.output)
run_id = secrets.token_hex(6)
report = {'created_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'samples': args.samples,
    'run_id': run_id, 'warmup': 5, 'environment': {}, 'manifests': {}, 'cases': {}}
for label, source in [('bindings', args.source), ('literal', args.candidate)]:
    root = pathlib.Path(source)
    report['manifests'][label] = {str(p.relative_to(root)): hashlib.sha256(p.read_bytes()).hexdigest()
        for p in sorted(root.rglob('*')) if p.is_file()}

class Worker:
    def __init__(self, label, size, plain):
        self.label = label
        self.plain = plain
        self.client = 'v3' if label == 'v3' else 'v4'
        app = pathlib.Path(args.v3 if label == 'v3' else args.app)
        source = '-' if label == 'v3' else (args.source if label == 'bindings' else args.candidate)
        self.table = f'pipe_{run_id}_{label}{size}' + ('_plain' if plain else '_trace')
        config = {'documents': size, 'read_table': 'unused', 'vector': [0.0], 'write_size': size,
            'native_builder': True, 'plain_pdo': plain, 'write_tables': {c: self.table for c in ['v3', 'v4', 'pdo']}}
        file = output.with_name(self.table + '.config.json')
        file.write_text(json.dumps(config))
        self.log = open(file.with_suffix('.stderr.log'), 'w')
        self.process = subprocess.Popen([str(app / 'bin/php'), '-d', 'xdebug.mode=off', '-d', 'memory_limit=768M',
            str(folder / 'worker.php'), str(app), self.client, str(file), source], cwd=app,
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=self.log, text=True, bufsize=1)
        line = self.process.stdout.readline()
        if not line:
            raise RuntimeError('Worker startup failed: ' + self.table)
        self.ready = json.loads(line)
        assert self.ready['ready'] and not self.ready['xdebug']
        if plain:
            assert self.ready['pdo_class'] == 'PDO' and self.ready['pdo_statement_class'] == 'PDOStatement'
        if label != 'v3':
            core = pathlib.Path(source) / 'src/Manticore/Laravel/Database/ManticoreQueryBuilder.php'
            assert pathlib.Path(self.ready['source']).resolve() == core.resolve()
            assert self.ready['source_sha256'] == hashlib.sha256(core.read_bytes()).hexdigest()
            assert self.ready['grammar_class'] == 'avadim\\Manticore\\Laravel\\Database\\ManticoreGrammar'

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

for size in args.sizes:
    workers = {}
    try:
        for plain in [False, True]:
            for label in ['v3', 'bindings', 'literal']:
                worker = Worker(label, size, plain)
                workers[(label, plain)] = worker
                report['environment'][worker.table] = worker.ready
        for verb in ['insert', 'replace']:
            scenario = f'write_{verb}500'
            captures = {label: workers[(label, False)].call({'op': 'capture', 'scenario': scenario})
                        for label in ['v3', 'bindings', 'literal']}
            digests = {v['digest'] for v in captures.values()}
            assert len(digests) == 1, 'Capture data differs'
            normalized_wire = {}
            for label in ['bindings', 'literal']:
                normalized_wire[label] = [r['literal_sql'].replace(workers[(label, False)].table, 'scratch') for r in captures[label]['trace']]
            assert normalized_wire['bindings'] == normalized_wire['literal'], 'Actual escaped SQL differs'
            case = {'captures': {}, 'phases': {}, 'matching_digest': next(iter(digests))}
            for label, capture in captures.items():
                case['captures'][label] = {k: v for k, v in capture.items() if k != 'trace'}
                case['captures'][label]['trace'] = [{k: v for k, v in r.items() if k not in ['sql', 'literal_sql', 'bindings', 'execute_params']}
                    | {'sql_sha256': hashlib.sha256(r['sql'].encode()).hexdigest(), 'sql_prefix': r['sql'][:400]}
                    for r in capture['trace']]
            report['cases'][f'{verb}_{size}'] = case
            for phase in ['live', 'plain']:
                samples = {label: [] for label in ['v3', 'bindings', 'literal']}
                for index in range(args.samples + 5):
                    labels = list(samples)
                    for label in labels[index % 3:] + labels[:index % 3]:
                        value = workers[(label, phase == 'plain')].call({'op': phase, 'scenario': scenario})
                        assert value['digest'] in digests, 'Written data differs'
                        if index >= 5:
                            samples[label].append(value)
                summary = {}
                for label, values in samples.items():
                    metrics = {key: statistics.median(v['metrics'][key] for v in values)
                        for key in values[0]['metrics'] if key != 'compilations'}
                    times = sorted(v['metrics']['total_ms'] for v in values)
                    metrics['p95_ms'] = times[(len(times) * 95 + 99) // 100 - 1]
                    summary[label] = metrics
                case['phases'][phase] = {'summary': summary, 'samples': samples}
                output.write_text(json.dumps(report, indent=2))
                print(f'{verb}_{size}', phase, json.dumps(summary), flush=True)
    finally:
        for worker in workers.values():
            worker.close()
report['ok'] = True
output.write_text(json.dumps(report, indent=2))
