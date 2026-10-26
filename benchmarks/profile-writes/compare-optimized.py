"""Compare released packages with an isolated source snapshot (no vendor edits)."""
import argparse
import hashlib
import json
import pathlib
import statistics
import subprocess
import time

parser = argparse.ArgumentParser()
parser.add_argument('--v3', required=True)
parser.add_argument('--v4', required=True)
parser.add_argument('--source', required=True)
parser.add_argument('--output', required=True)
parser.add_argument('--samples', type=int, default=30)
parser.add_argument('--profile', action='store_true')
args = parser.parse_args()
if args.samples < 10:
    parser.error('At least ten measured samples are required')
folder = pathlib.Path(__file__).resolve().parent
snapshot = pathlib.Path(args.source)
manifest = {str(p.relative_to(snapshot)): hashlib.sha256(p.read_bytes()).hexdigest()
            for p in sorted(snapshot.rglob('*')) if p.is_file()}
report = {'created_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
          'profile': args.profile, 'warmup': 5, 'sample_count': args.samples,
          'source_manifest': manifest, 'environment': {}, 'cases': {}}
workers = {}

def call(client, request):
    process = workers[client]
    process.stdin.write(json.dumps(request) + '\n')
    process.stdin.flush()
    line = process.stdout.readline()
    if not line:
        raise RuntimeError(client + ' exited unexpectedly')
    result = json.loads(line)
    if not result.get('ok'):
        raise RuntimeError(client + ': ' + str(result))
    return result

try:
    for client, root in [('v3', args.v3), ('v4', args.v4), ('optimized', args.v4)]:
        command = [str(pathlib.Path(root) / 'bin/php'), '-d', 'xdebug.mode=off',
                   '-d', 'memory_limit=512M', str(folder / 'worker.php'), root,
                   'v3' if client == 'v3' else 'v4']
        if client == 'optimized':
            command.append(str(snapshot))
        process = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                                   text=True, bufsize=1)
        workers[client] = process
        report['environment'][client] = json.loads(process.stdout.readline())
        assert report['environment'][client]['ready']
    assert report['environment']['v3']['package'].lstrip('v') == '3.0.0'
    assert report['environment']['v4']['package'].lstrip('v') == '4.0.0'
    loaded = report['environment']['optimized']
    expected = snapshot / 'src/Manticore/Laravel/Database/ManticoreQueryBuilder.php'
    assert pathlib.Path(loaded['loaded_source']).resolve() == expected.resolve()
    assert loaded['loaded_source_sha256'] == hashlib.sha256(expected.read_bytes()).hexdigest()
    for size in [1, 100, 500, 1000]:
        for verb in ['insert', 'replace']:
            clients = list(workers)
            samples = {c: [] for c in clients}
            digests = set()
            for i in range(args.samples + 5):
                order = clients[i % len(clients):] + clients[:i % len(clients)]
                for client in order:
                    result = call(client, {'op': 'measure', 'variant': 'normal',
                                          'size': size, 'verb': verb,
                                          'profile': args.profile, 'prototype': False})
                    digests.add(result['digest'])
                    assert len(digests) == 1, f'Data mismatch: {verb} {size}'
                    if i >= 5:
                        samples[client].append(result)
            summary = {}
            for client, values in samples.items():
                stages = {stage: {key: statistics.median(v['stages'][stage][key] for v in values)
                                  for key in ['inclusive_ms', 'exclusive_ms', 'calls']}
                          for stage in values[0]['stages']}
                timings = sorted(v['total_ms'] for v in values)
                summary[client] = {'median_ms': statistics.median(timings),
                                   'min_ms': timings[0], 'max_ms': timings[-1], 'stages': stages}
            name = f'{verb}_{size}'
            report['cases'][name] = {'summary': summary, 'samples': samples,
                                     'digest': next(iter(digests))}
            pathlib.Path(args.output).write_text(json.dumps(report, indent=2))
            print(name, json.dumps(summary), flush=True)
    report['ok'] = True
finally:
    for process in workers.values():
        if process.poll() is None:
            process.stdin.write('{"op":"quit"}\n')
            process.stdin.flush()
            process.wait(timeout=15)
    pathlib.Path(args.output).write_text(json.dumps(report, indent=2))
