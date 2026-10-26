"""Separate builder work, actual PDO calls and replay; compare isolated hypotheses."""
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
parser.add_argument('--variants', required=True)
parser.add_argument('--output', required=True)
parser.add_argument('--samples', type=int, default=30)
parser.add_argument('--build-rounds', type=int, default=15)
parser.add_argument('--documents', type=int, default=20000)
parser.add_argument('--only', nargs='*')
args = parser.parse_args()
assert args.samples >= 5 and args.build_rounds >= 3 and args.documents >= 2000
folder = pathlib.Path(__file__).resolve().parent
roots = {'v3': pathlib.Path(args.v3), 'v4': pathlib.Path(args.v4)}
snapshot = pathlib.Path(args.source).resolve()
variants = pathlib.Path(args.variants).resolve()
run_id = secrets.token_hex(6)
output = pathlib.Path(args.output).resolve()
config_path = output.with_suffix('.config.json')
vector = json.loads(subprocess.check_output([str(roots['v4'] / 'bin/php'), '-r',
    "require $argv[1];echo json_encode(comparisonDocument(1)['embedding']);",
    str(folder / '../compare-versions/fixtures.php')], text=True))
config = {'documents': args.documents, 'read_table': f'pipe_{run_id}_read', 'vector': vector}
packages = {client: {p['name']: p for p in json.loads((root / 'composer.lock').read_text())['packages']}
            for client, root in roots.items()}
common = (packages['v3'].keys() & packages['v4'].keys()) - {'avadim/manticore-query-builder-laravel'}
assert all(packages['v3'][p]['version'] == packages['v4'][p]['version'] and
           packages['v3'][p].get('source') == packages['v4'][p].get('source') for p in common)
report = {'run_id': run_id, 'created_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
          'samples': args.samples, 'build_rounds': args.build_rounds, 'warmup': 5,
          'config': config, 'common_dependencies': len(common),
          'base_manifest': {str(p.relative_to(snapshot)): hashlib.sha256(p.read_bytes()).hexdigest()
                            for p in sorted(snapshot.rglob('*')) if p.is_file()},
          'variant_manifests': json.loads((variants / 'manifest.json').read_text()),
          'environment': {}, 'cases': {}}
workers = {}

class Worker:
    def __init__(self, label):
        self.client = 'v3' if label == 'v3' else ('pdo' if label == 'control' else 'v4')
        root = roots['v3' if self.client == 'v3' else 'v4']
        source = '-' if self.client != 'v4' else str(snapshot if label == 'v4' else variants / label)
        own_config = dict(config, count_sql=False,
                          write_tables={c: f'pipe_{run_id}_{label}' for c in ['v3', 'v4', 'pdo']})
        own_path = config_path.with_name(f'{run_id}-{label}.config.json')
        own_path.write_text(json.dumps(own_config))
        self.log = open(config_path.with_name(f'{run_id}-{label}.stderr.log'), 'w')
        self.process = subprocess.Popen([str(root / 'bin/php'), '-d', 'xdebug.mode=off',
            '-d', 'memory_limit=768M', str(folder / 'worker.php'), str(root), self.client,
            str(own_path), source], cwd=root, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
            stderr=self.log, text=True, bufsize=1)
        line = self.process.stdout.readline()
        if not line:
            raise RuntimeError('Startup failed for ' + label)
        self.environment = json.loads(line)
        assert self.environment['ready'] and not self.environment['xdebug']
        if self.client == 'v4':
            expected = pathlib.Path(source) / 'src/Manticore/Laravel/Database/ManticoreQueryBuilder.php'
            assert pathlib.Path(self.environment['source']).resolve() == expected.resolve()
            assert self.environment['source_sha256'] == hashlib.sha256(expected.read_bytes()).hexdigest()

    def call(self, message):
        self.process.stdin.write(json.dumps(message) + '\n')
        self.process.stdin.flush()
        line = self.process.stdout.readline()
        if not line:
            raise RuntimeError('Worker exited: ' + str(message))
        value = json.loads(line)
        if not value.get('ok'):
            raise RuntimeError(str(message) + ': ' + str(value))
        return value

    def close(self):
        if self.process.poll() is None:
            self.process.stdin.write('{"op":"quit"}\n')
            self.process.stdin.flush()
            self.process.wait(timeout=20)
        self.log.close()

def checkpoint():
    output.write_text(json.dumps(report, indent=2))

def summarize(values):
    if 'times_ms' in values[0]:
        flat = [t for v in values for t in v['times_ms']]
        return {'median_ms': statistics.median(flat), 'samples': len(flat),
                'compile_median_ms': statistics.median(t for v in values for t in v['compile_times_ms']),
                'cpu_median_ms': statistics.median(t for v in values for t in v['cpu_times_ms']),
                'compilations': values[0]['compilations']}
    keys = values[0]['metrics'].keys() - {'compilations'}
    return {key: statistics.median(v['metrics'][key] for v in values) for key in keys}

cases = {
    'rows_only20': ['single_compile'],
    'match_meta20': ['single_compile'],
    'structured1000': ['single_compile'],
    'knn20': ['single_compile', 'vector_json'],
    'write_insert500': ['fused_rows', 'loop_bindings', 'execute_array'],
    'write_replace500': ['fused_rows', 'loop_bindings', 'execute_array'],
    'write_update': ['loop_bindings', 'update_validation'],
    'write_update_float': ['loop_bindings', 'update_validation'],
}
if args.only:
    cases = {name: cases[name] for name in args.only}
try:
    labels = ['control', 'v3', 'v4', *sorted({label for labels in cases.values() for label in labels}), 'combined_safe', 'combined']
    for label in dict.fromkeys(labels):
        workers[label] = Worker(label)
        report['environment'][label] = workers[label].environment
    assert report['environment']['v3']['package'].lstrip('v') == '3.0.0'
    assert report['environment']['v4']['package'].lstrip('v') == '4.0.0'
    assert len({e['php'] for e in report['environment'].values()}) == 1
    assert len({e['laravel'] for e in report['environment'].values()}) == 1
    setup = workers['control'].call({'op': 'setup'})
    assert setup['count'] == args.documents
    report['server'] = setup['version']
    print('Ready', run_id, args.documents, flush=True)
    for scenario, experiments in cases.items():
        clients = ['v3', 'v4', *experiments, 'combined_safe', 'combined']
        captures = {client: workers[client].call({'op': 'capture', 'scenario': scenario}) for client in clients}
        digests = {value['digest'] for value in captures.values()}
        assert len(digests) == 1, 'Capture result mismatch: ' + scenario
        # Each prototype must retain the same wire SQL and parameter types as current 4.x.
        signature = lambda c: [(r['sql'], r['bindings'], r['execute_params']) for r in captures[c]['trace']]
        for client in clients[2:]:
            # Table names are private per worker, so normalize only the scratch identifier.
            normalize = lambda value, label: json.dumps(value).replace(f'pipe_{run_id}_{label}', 'scratch_table')
            if client in ['execute_array', 'combined'] and scenario.startswith('write_'):
                assert normalize([r['sql'] for r in captures[client]['trace']], client) == normalize([r['sql'] for r in captures['v4']['trace']], 'v4')
            else:
                assert normalize(signature(client), client) == normalize(signature('v4'), 'v4'), 'Wire SQL changed: ' + client
        case = {'capture': captures, 'phases': {}}
        report['cases'][scenario] = case
        for phase in ['build', 'live', 'replay', 'literal', 'plain']:
            available = clients if phase in ['build', 'live', 'plain'] else ['v3', 'v4']
            samples = {c: [] for c in available}
            repetitions = args.build_rounds if phase == 'build' else args.samples
            for i in range(repetitions + 5):
                order = available[i % len(available):] + available[:i % len(available)]
                for client in order:
                    value = workers[client].call({'op': phase, 'scenario': scenario,
                        'iterations': 10 if scenario.startswith('write_') else 50})
                    if phase != 'build':
                        assert value['digest'] in digests, f'Result mismatch: {scenario} {phase} {client}'
                    if i >= 5:
                        samples[client].append(value)
            case['phases'][phase] = {'summary': {c: summarize(v) for c, v in samples.items()}, 'samples': samples}
            checkpoint()
            print(scenario, phase, json.dumps(case['phases'][phase]['summary']), flush=True)
    report['ok'] = True
finally:
    for label, worker in reversed(list(workers.items())):
        try:
            worker.close()
        except Exception as error:
            report.setdefault('cleanup_errors', []).append(str(error))
    checkpoint()
