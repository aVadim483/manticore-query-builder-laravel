"""Compare complete write contracts and codec edge cases against unchanged baseline."""
import argparse
import json
import pathlib
import subprocess

parser = argparse.ArgumentParser()
parser.add_argument('--app', required=True)
parser.add_argument('--variants', required=True)
parser.add_argument('--ports', nargs='+', type=int, default=[19306, 19506])
parser.add_argument('--output', required=True)
parser.add_argument('--labels', nargs='+', default=['baseline', 'closures', 'kinds'])
args = parser.parse_args()
labels = args.labels
assert labels[0] == 'baseline' and len(labels) >= 2 and len(set(labels)) == len(labels)
folder = pathlib.Path(__file__).resolve().parent
variants = pathlib.Path(args.variants)
php = str(pathlib.Path(args.app) / 'bin/php')
report = {'servers': {}, 'codec': {}}
codec_cases = [('closures', 'closures'), ('kinds', 'kinds')] if labels == ['baseline', 'closures', 'kinds'] else [
    ('baseline', 'reference'), ('current', 'reference'), ('current', 'closures')]
for label, mode in codec_cases:
    value = json.loads(subprocess.check_output(
        [php, str(folder / 'check-encoding-plan.php'), args.app, str(variants / label), mode], text=True))
    assert value['ok']
    report['codec'][label if mode == label else label + '_' + mode] = value
assert len({v['outcomes_sha256'] for v in report['codec'].values()}) == 1, 'Codec behavior differs'
for port in args.ports:
    values = {}
    for label in labels:
        raw = subprocess.check_output(
            [php, str(folder / 'check-literal-writes.php'), args.app, str(variants / label), str(port)], text=True)
        value = json.loads(raw)
        assert value['ok'] and len(value['cases']) == 47
        values[label] = value
    differences = {}
    for label in labels[1:]:
        differences[label] = [
            {'case': name, 'field': key, 'before': baseline[key], 'after': values[label]['cases'][name][key]}
            for name, baseline in values['baseline']['cases'].items()
            for key in baseline if baseline[key] != values[label]['cases'][name][key]
        ]
    report['servers'][str(port)] = {'results': values, 'differences': differences}
    pathlib.Path(args.output).write_text(json.dumps(report, indent=2, ensure_ascii=False))
    print(port, json.dumps(differences), flush=True)
report['ok'] = not any(items for server in report['servers'].values() for items in server['differences'].values())
pathlib.Path(args.output).write_text(json.dumps(report, indent=2, ensure_ascii=False))
assert report['ok'], 'Write contract changed'
