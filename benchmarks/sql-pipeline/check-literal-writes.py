"""Compare successful writes, event/error contracts and transactions for both prototypes."""
import argparse
import json
import pathlib
import subprocess

parser = argparse.ArgumentParser()
parser.add_argument('--app', required=True)
parser.add_argument('--source', required=True)
parser.add_argument('--fused', required=True)
parser.add_argument('--offsets', required=True)
parser.add_argument('--ports', nargs='+', type=int, default=[19306, 19506])
parser.add_argument('--output', required=True)
args = parser.parse_args()
script = pathlib.Path(__file__).resolve().parent / 'check-literal-writes.php'
report = {'servers': {}}
for port in args.ports:
    values = {}
    for label, source in [('bindings', args.source), ('fused', args.fused), ('offsets', args.offsets)]:
        raw = subprocess.check_output([str(pathlib.Path(args.app) / 'bin/php'), str(script), args.app, source, str(port)], text=True)
        value = json.loads(raw)
        assert value['ok'] and len(value['cases']) == 47
        values[label] = value
    differences = {}
    for label in ['fused', 'offsets']:
        items = []
        for name, baseline in values['bindings']['cases'].items():
            candidate = values[label]['cases'][name]
            for key in baseline:
                if key == 'methods':
                    continue  # prepare vs exec is the intended experiment.
                if key == 'wire_hash' and not baseline['status']['ok']:
                    continue
                if baseline[key] != candidate[key]:
                    items.append({'case': name, 'field': key, 'before': baseline[key], 'after': candidate[key]})
        differences[label] = items
    report['servers'][str(port)] = {'results': values, 'differences': differences}
    pathlib.Path(args.output).write_text(json.dumps(report, indent=2, ensure_ascii=False))
    print(port, json.dumps(differences), flush=True)
report['ok'] = True
pathlib.Path(args.output).write_text(json.dumps(report, indent=2, ensure_ascii=False))
