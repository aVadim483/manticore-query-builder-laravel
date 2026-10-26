"""Verify actual stock builders and compatibility subclasses independently."""
import argparse
import json
import pathlib
import subprocess

parser = argparse.ArgumentParser()
parser.add_argument('--app', required=True)
parser.add_argument('--before', required=True)
parser.add_argument('--after', required=True)
parser.add_argument('--output', required=True)
args = parser.parse_args()
script = pathlib.Path(__file__).resolve().parent / 'check-variants.php'
results = {}
for version, source in [('before', args.before), ('after', args.after)]:
    results[version] = {}
    for mode in ['native', 'subclass']:
        command = [str(pathlib.Path(args.app) / 'bin/php'), str(script), args.app, source]
        if mode == 'native':
            command.append('--native')
        value = json.loads(subprocess.check_output(command, text=True))
        assert value['ok'] and value['clean_bindings_cases'] == 1000 and value['vector_cases'] == 1000
        assert all(write['ok'] for write in value['writes'].values()), value
        results[version][mode] = value
for mode in ['native', 'subclass']:
    before, after = results['before'][mode], results['after'][mode]
    for key in ['writes', 'scalar_null', 'cold_schema_listener']:
        assert before[key] == after[key], (mode, key)
results['ok'] = True
pathlib.Path(args.output).write_text(json.dumps(results, indent=2, ensure_ascii=False))
print(json.dumps({'ok': True, 'modes': ['native', 'subclass'], 'clean_binding_cases': 4000,
                  'vector_cases': 4000, 'write_terminals_per_mode': 4}))
