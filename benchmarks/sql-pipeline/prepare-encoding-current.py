"""Prepare immutable baseline/current snapshots and separate instrumented copies."""
import argparse
import hashlib
import json
import pathlib
import shutil
import textwrap

parser = argparse.ArgumentParser()
parser.add_argument('--baseline', required=True)
parser.add_argument('--current', required=True)
parser.add_argument('--output', required=True)
args = parser.parse_args()
output = pathlib.Path(args.output).resolve()
assert not output.exists()
output.mkdir(parents=True)
query = 'src/Manticore/Laravel/Database/ManticoreQueryBuilder.php'
for label, source in [('baseline', args.baseline), ('current', args.current)]:
    target = output / label
    shutil.copytree(source, target)
    timed = output / (label + '_timed')
    shutil.copytree(target, timed)
    file = timed / query
    text = file.read_text()
    start = text.index('    protected function encodeWriteRows(')
    end = text.index('    private function encodeInsertRows(', start)
    method = text[start:end]
    opening = method.index('\n    {\n') + len('\n    {\n')
    closing = method.rfind('    }\n')
    replacement = method[:opening] + '        $codecStarted = hrtime(true);\n        try {\n'
    replacement += textwrap.indent(method[opening:closing], '    ')
    replacement += """        } finally {
            $GLOBALS['encoding_plan_ns'] = ($GLOBALS['encoding_plan_ns'] ?? 0) + hrtime(true) - $codecStarted;
        }
    }

"""
    file.write_text(text[:start] + replacement + text[end:], encoding='utf-8', newline='\n')
manifest = {target.name: {str(p.relative_to(target)): hashlib.sha256(p.read_bytes()).hexdigest()
    for p in sorted(target.rglob('*')) if p.is_file()} for target in sorted(output.iterdir()) if target.is_dir()}
(output / 'manifest.json').write_text(json.dumps(manifest, indent=2))
print(json.dumps({'ok': True, 'variants': list(manifest)}))
