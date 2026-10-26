"""A/B finite scalar float Expressions vs string bindings, INSERT/REPLACE only."""
import argparse
import gzip
import hashlib
import json
import pathlib
import shutil
import statistics
import subprocess
import time

parser = argparse.ArgumentParser()
parser.add_argument('--app', required=True)
parser.add_argument('--source', required=True)
parser.add_argument('--candidate', required=True)
parser.add_argument('--worker', required=True)
parser.add_argument('--output', required=True)
parser.add_argument('--samples', type=int, default=50)
parser.add_argument('--profile', action='store_true')
args = parser.parse_args()
assert args.samples >= 10
source = pathlib.Path(args.source).resolve()
candidate = pathlib.Path(args.candidate).resolve()
relative = 'src/Manticore/Laravel/Database/ManticoreQueryBuilder.php'
old = '$value = new Expression(json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));'
new = '$value = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);'
expected = (source / relative).read_text()
assert expected.count(old) == 1
expected = expected.replace(old, new, 1)
if not candidate.exists():
    assert source != candidate and source not in candidate.parents
    shutil.copytree(source, candidate)
    (candidate / relative).write_text(expected, encoding='utf-8', newline='\n')
assert (candidate / relative).read_text() == expected, 'Existing candidate is not the expected one-line change'
output = pathlib.Path(args.output)
report = {'created_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
          'samples': args.samples, 'warmup': 5, 'profile': args.profile,
          'source_manifests': {}, 'environment': {}, 'cases': {}}
workers = {}

def call(client, command):
    process = workers[client]
    process.stdin.write(json.dumps(command) + '\n')
    process.stdin.flush()
    line = process.stdout.readline()
    if not line:
        raise RuntimeError(client + ' exited')
    value = json.loads(line)
    if not value.get('ok'):
        raise RuntimeError(client + ': ' + str(value))
    return value

try:
    for label, snapshot in [('expression', source), ('float_string', candidate)]:
        report['source_manifests'][label] = {str(p.relative_to(snapshot)): hashlib.sha256(p.read_bytes()).hexdigest()
            for p in sorted(snapshot.rglob('*')) if p.is_file()}
        command = [str(pathlib.Path(args.app) / 'bin/php'), '-d', 'xdebug.mode=off',
            '-d', 'memory_limit=512M', args.worker, args.app, 'v4', str(snapshot)]
        process = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True, bufsize=1)
        workers[label] = process
        ready = json.loads(process.stdout.readline())
        assert ready['ready']
        assert pathlib.Path(ready['loaded_source']).resolve() == (snapshot / relative).resolve()
        assert ready['loaded_source_sha256'] == report['source_manifests'][label][relative]
        report['environment'][label] = ready
        captured = call(label, {'op': 'capture_numeric', 'size': 500, 'variant': 'normal', 'verb': 'replace'})
        report['environment'][label]['bindings_for_500'] = len(captured['bindings'])
        report['environment'][label]['sql_bytes_for_500'] = len(captured['sql'].encode())
    for size in [1, 100, 500, 1000]:
        for verb in ['insert', 'replace']:
            samples = {client: [] for client in workers}
            digests = set()
            for index in range(args.samples + 5):
                clients = list(workers)
                order = clients[index % 2:] + clients[:index % 2]
                for client in order:
                    value = call(client, {'op': 'measure', 'size': size, 'variant': 'normal',
                        'verb': verb, 'profile': args.profile, 'prototype': False})
                    digests.add(value['digest'])
                    assert len(digests) == 1, f'Stored data differs: {verb} {size}'
                    if index >= 5:
                        samples[client].append(value)
            summary = {}
            for client, values in samples.items():
                times = sorted(v['total_ms'] for v in values)
                stages = {stage: {key: statistics.median(v['stages'][stage][key] for v in values)
                    for key in ['inclusive_ms', 'exclusive_ms', 'calls']} for stage in values[0]['stages']}
                summary[client] = {'median_ms': statistics.median(times),
                    'p95_ms': times[(len(times) * 95 + 99) // 100 - 1], 'stages': stages}
            name = f'{verb}_{size}'
            report['cases'][name] = {'summary': summary, 'samples': samples, 'digest': next(iter(digests))}
            output.write_text(json.dumps(report, indent=2))
            print(name, json.dumps(summary), flush=True)
    report['ok'] = True
finally:
    for process in workers.values():
        if process.poll() is None:
            process.stdin.write('{"op":"quit"}\n')
            process.stdin.flush()
            process.wait(timeout=15)
    output.write_text(json.dumps(report, indent=2))
