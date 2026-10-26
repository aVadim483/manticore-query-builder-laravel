"""Run edge-case probes in separate PHP processes for each isolated source variant."""
import argparse
import hashlib
import json
import pathlib
import subprocess

parser = argparse.ArgumentParser()
parser.add_argument('--app', required=True)
parser.add_argument('--source', required=True)
parser.add_argument('--variants', required=True)
parser.add_argument('--output', required=True)
args = parser.parse_args()
folder = pathlib.Path(__file__).resolve().parent
variants = pathlib.Path(args.variants)
labels = ['base', *json.loads((variants / 'manifest.json').read_text())]
report = {'variants': {}}
for label in labels:
    source = pathlib.Path(args.source) if label == 'base' else variants / label
    raw = subprocess.check_output([str(pathlib.Path(args.app) / 'bin/php'),
        '-d', 'xdebug.mode=off', str(folder / 'check-variants.php'), args.app, str(source)], text=True)
    value = json.loads(raw)
    assert value['ok'] and value['clean_bindings_cases'] == 1000 and value['vector_cases'] == 1000
    assert all(write['ok'] for write in value['writes'].values()), (label, value['writes'])
    report['variants'][label] = value
    print(label, json.dumps({'writes': value['writes'], 'cold_schema_listener': value['cold_schema_listener']}), flush=True)
base = report['variants']['base']
for label, value in report['variants'].items():
    assert {k: v['digest'] for k, v in value['writes'].items()} == {k: v['digest'] for k, v in base['writes'].items()}, label
    assert value['scalar_null'] == base['scalar_null'], (label, 'scalar NULL acceptance changed')
    value['cold_schema_listener_matches_base'] = value['cold_schema_listener'] == base['cold_schema_listener']
    value['encoding_hook_matches_base'] = all(value['writes'][k]['encoder_hook_calls'] == v['encoder_hook_calls'] for k, v in base['writes'].items())
report['harness_manifest'] = {str(p.relative_to(folder)): hashlib.sha256(p.read_bytes()).hexdigest()
                              for p in sorted(folder.glob('*')) if p.is_file()}
report['ok'] = True
pathlib.Path(args.output).write_text(json.dumps(report, indent=2, ensure_ascii=False))
