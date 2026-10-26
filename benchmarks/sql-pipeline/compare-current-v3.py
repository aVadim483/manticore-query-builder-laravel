"""Compare published 3.x and final native 4.x writes, using real PDO for primary totals."""
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
parser.add_argument('--v4', required=True)
parser.add_argument('--source', required=True)
parser.add_argument('--output', required=True)
parser.add_argument('--samples', type=int, default=60)
parser.add_argument('--profile-samples', type=int, default=20)
parser.add_argument('--sizes', nargs='+', type=int, default=[1, 100, 500, 1000])
args = parser.parse_args()
assert args.samples >= 10 and args.profile_samples >= 5
folder = pathlib.Path(__file__).resolve().parent
output = pathlib.Path(args.output)
run_id = secrets.token_hex(6)
apps = {'v3': pathlib.Path(args.v3), 'v4': pathlib.Path(args.v4)}
source = pathlib.Path(args.source)

def manifest(root):
    return {str(p.relative_to(root)): hashlib.sha256(p.read_bytes()).hexdigest()
            for p in sorted(root.rglob('*')) if p.is_file()}

def packages(app):
    lock = json.loads((app / 'composer.lock').read_text())
    return {p['name']: p['version'] for p in lock['packages']}

deps = {label: packages(app) for label, app in apps.items()}
shared = set(deps['v3']) & set(deps['v4']) - {'avadim/manticore-query-builder-laravel'}
assert all(deps['v3'][p] == deps['v4'][p] for p in shared), 'Different shared dependencies'
report = {'created_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'run_id': run_id,
    'samples': args.samples, 'profile_samples': args.profile_samples, 'build_rounds': 10,
    'build_iterations': 10, 'warmup': 5, 'environment': {}, 'cases': {},
    'shared_dependencies': {p: deps['v3'][p] for p in sorted(shared)}, 'dependencies': deps,
    'manifests': {'current': manifest(source),
        'v3_driver': manifest(apps['v3'] / 'vendor/avadim/manticore-query-builder-laravel'),
        'v3_standalone': manifest(apps['v3'] / 'vendor/avadim/manticore-query-builder-php')}}

class Worker:
    def __init__(self, label, fixture, size, mode):
        self.label = label
        self.table = f'pipe_{run_id}_{label}_{fixture}_{size}_{mode}'
        config = {'documents': size, 'read_table': 'unused', 'vector': [0.0], 'write_size': size,
            'native_builder': True, 'plain_pdo': mode == 'plain', 'write_fixture': fixture,
            'write_tables': {c: self.table for c in ['v3', 'v4', 'pdo']}}
        file = output.with_name(self.table + '.config.json')
        file.write_text(json.dumps(config))
        self.log = open(file.with_suffix('.stderr.log'), 'w')
        self.process = subprocess.Popen([str(apps[label] / 'bin/php'), '-d', 'xdebug.mode=off',
            '-d', 'memory_limit=768M', str(folder / 'worker.php'), str(apps[label]), label,
            str(file), str(source) if label == 'v4' else '-'], cwd=apps[label],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=self.log, text=True, bufsize=1)
        line = self.process.stdout.readline()
        if not line:
            raise RuntimeError('Startup failed: ' + self.table)
        self.ready = json.loads(line)
        assert self.ready['ready'] and not self.ready['xdebug']
        if mode == 'plain':
            assert self.ready['pdo_class'] == 'PDO' and self.ready['pdo_statement_class'] == 'PDOStatement'
        if label == 'v4':
            core = 'src/Manticore/Laravel/Database/ManticoreQueryBuilder.php'
            assert pathlib.Path(self.ready['source']).resolve() == (source / core).resolve()
            assert self.ready['source_sha256'] == report['manifests']['current'][core]
            assert self.ready['grammar_class'] == 'avadim\\Manticore\\Laravel\\Database\\ManticoreGrammar'
            assert pathlib.Path(self.ready['connection_source']).is_relative_to(source)
        else:
            assert self.ready['package'].lstrip('v') == '3.0.0'
            assert self.ready['standalone_version'].lstrip('v') == '2.6.0'
            assert pathlib.Path(self.ready['connection_source']).is_relative_to(
                apps[label] / 'vendor/avadim/manticore-query-builder-laravel')
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

def summarize(values, mode):
    if mode == 'build':
        times = [t for v in values for t in v['times_ms']]
        return {'before_pdo_ms': statistics.median(times), 'count': len(times)}
    metrics = {k: statistics.median(v['metrics'][k] for v in values)
               for k in values[0]['metrics'] if k != 'compilations'}
    times = sorted(v['metrics']['total_ms'] for v in values)
    metrics['p95_ms'] = times[(len(times) * 95 + 99) // 100 - 1]
    return metrics

def slim_capture(value, table):
    result = {k: v for k, v in value.items() if k != 'trace'}
    result['trace'] = []
    for r in value['trace']:
        result['trace'].append({k: v for k, v in r.items()
            if k not in ['sql', 'literal_sql', 'bindings', 'execute_params']} | {
            'sql_sha256': hashlib.sha256(r['sql'].replace(table, 'scratch').encode()).hexdigest(),
            'wire_sha256': hashlib.sha256(r['literal_sql'].replace(table, 'scratch').encode()).hexdigest(),
            'sql_prefix': r['sql'][:200]})
    return result

for fixture in ['structured', 'scalar']:
    for size in args.sizes:
        for mode in ['profile', 'plain']:
            workers = {}
            try:
                for label in ['v3', 'v4']:
                    workers[label] = Worker(label, fixture, size, mode)
                for verb in ['insert', 'replace']:
                    name = f'{fixture}_{verb}_{size}'
                    scenario = f'write_{verb}500'
                    if mode == 'profile':
                        captures = {label: w.call({'op': 'capture', 'scenario': scenario}) for label, w in workers.items()}
                        assert len({v['digest'] for v in captures.values()}) == 1, 'Written data differs'
                        report['cases'][name] = {'digest': captures['v3']['digest'],
                            'captures': {label: slim_capture(v, workers[label].table) for label, v in captures.items()},
                            'phases': {}}
                    case = report['cases'][name]
                    for phase in (['build', 'live'] if mode == 'profile' else ['plain']):
                        count = 10 if phase == 'build' else args.profile_samples if phase == 'live' else args.samples
                        samples = {label: [] for label in workers}
                        for index in range(count + 5):
                            order = ['v3', 'v4'] if index % 2 == 0 else ['v4', 'v3']
                            for label in order:
                                v = workers[label].call({'op': phase, 'scenario': scenario, 'iterations': 10})
                                if phase != 'build':
                                    assert v['digest'] == case['digest'], 'Written data differs: ' + name
                                if index >= 5:
                                    samples[label].append(v)
                        summaries = {label: summarize(v, phase) for label, v in samples.items()}
                        paired = None if phase == 'build' else statistics.median(
                            b['metrics']['total_ms'] - a['metrics']['total_ms']
                            for a, b in zip(samples['v3'], samples['v4']))
                        case['phases'][phase] = {'summary': summaries, 'paired_difference_ms': paired, 'samples': samples}
                        output.write_text(json.dumps(report, indent=2))
                        print(name, phase, json.dumps(summaries), 'paired', paired, flush=True)
            finally:
                for worker in workers.values():
                    worker.close()
report['ok'] = True
output.write_text(json.dumps(report, indent=2))
