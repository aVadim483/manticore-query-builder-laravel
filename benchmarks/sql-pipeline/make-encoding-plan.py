"""Generate per-batch column codec plans, without changing row traversal or SQL building."""
import argparse
import hashlib
import json
import pathlib
import shutil
import textwrap

parser = argparse.ArgumentParser()
parser.add_argument('--source', required=True)
parser.add_argument('--output', required=True)
args = parser.parse_args()
source, output = pathlib.Path(args.source).resolve(), pathlib.Path(args.output).resolve()
assert source != output and source not in output.parents and not output.exists()
query = 'src/Manticore/Laravel/Database/ManticoreQueryBuilder.php'
codec = 'src/Manticore/Laravel/Database/AttributeCodec.php'
scalar = """if (is_array($value) || is_object($value)) {
    throw new InvalidArgumentException('Structured values require a JSON, MVA or vector column.');
}
return $value;"""
bodies = {
    'Scalar': scalar,
    'Timestamp': """if ($value instanceof DateTimeInterface) return $value->getTimestamp();
""" + scalar,
    'Json': """if (is_string($value)) {
    json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    return $value;
}
return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);""",
}
list_start = """if (!is_array($value) || !array_is_list($value)) {
    throw new InvalidArgumentException('MVA and vector values must be lists.');
}
$numbers = [];
foreach ($value as $number) {
"""
list_end = """
}
return new Expression('('.implode(', ', $numbers).')');"""
bodies['Mva'] = list_start + """    if (!is_int($number) && !(is_string($number) && preg_match('/^-?[0-9]+$/D', $number))) {
        throw new InvalidArgumentException('MVA values must be integers.');
    }
    $numbers[] = (string)$number;""" + list_end
bodies['Vector'] = list_start + """    if ((!is_int($number) && !is_float($number)) || !is_finite((float)$number)) {
        throw new InvalidArgumentException('Vectors require finite numeric values.');
    }
    $numbers[] = json_encode($number, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);""" + list_end
guard = "if ($value instanceof ExpressionContract) return $value;\n"
unknown = """if (is_array($value) || (is_object($value) && !$value instanceof ExpressionContract)) {
    throw new InvalidArgumentException('A structured value requires a known JSON, MVA or vector column.');
}
return $value;"""

def once(text, old, new):
    assert text.count(old) == 1, 'Unexpected source: ' + old[:100]
    return text.replace(old, new, 1)

def timer(text):
    start = text.index('    protected function encodeWriteRows(')
    end = text.index('    private function encodeInsertRows(', start)
    method = text[start:end]
    opening = method.index('\n    {\n') + len('\n    {\n')
    closing = method.rfind('    }\n')
    body = method[opening:closing]
    replacement = method[:opening] + '        $codecStarted = hrtime(true);\n        try {\n'
    replacement += textwrap.indent(body, '    ')
    replacement += """        } finally {
            $GLOBALS['encoding_plan_ns'] = ($GLOBALS['encoding_plan_ns'] ?? 0) + hrtime(true) - $codecStarted;
        }
    }

"""
    return text[:start] + replacement + text[end:]

output.mkdir(parents=True)
manifest = {}
for variant in ['baseline', 'closures', 'kinds']:
    target = output / variant
    shutil.copytree(source, target)
    if variant != 'baseline':
        text = (target / codec).read_text()
        if variant == 'closures':
            additions = r"""    // Experimental prepared handlers: selected once per column per batch.
    public static function encoder(string $type): \Closure
    {
        return match ($type) {
            'json' => self::plannedJson(...),
            'timestamp' => self::plannedTimestamp(...),
            'float_vector' => self::plannedVector(...),
            'multi', 'multi64', 'mva', 'mva64' => self::plannedMva(...),
            default => self::plannedScalar(...),
        };
    }

"""
            for name, body in bodies.items():
                additions += f'    private static function planned{name}(mixed $value): mixed\n    {{\n'
                additions += textwrap.indent(guard + body, '        ') + '\n    }\n\n'
            plan = """$encoders = [];
foreach ($rows as &$row) {
    foreach ($row as $column => &$value) {
        $encode = $encoders[$column] ??= isset($schema[$column])
            ? AttributeCodec::encoder($schema[$column]['type'])
            : static function (mixed $value): mixed {
""" + textwrap.indent(unknown, '                ') + """
            };
        $value = $encode($value);
    }
    unset($value);
}
unset($row);"""
        else:
            additions = """    public static function encodingKind(string $type): int
    {
        return match ($type) {
            'json' => 1, 'timestamp' => 2, 'float_vector' => 3,
            'multi', 'multi64', 'mva', 'mva64' => 4, default => 0,
        };
    }

    public static function encodeKind(int $kind, mixed $value): mixed
    {
        if ($value instanceof ExpressionContract) return $value;
        switch ($kind) {
"""
            for kind, name in [(1, 'Json'), (2, 'Timestamp'), (3, 'Vector'), (4, 'Mva')]:
                additions += f'            case {kind}:\n' + textwrap.indent(bodies[name], '                ') + '\n'
            additions += '        }\n' + textwrap.indent(scalar, '        ') + '\n    }\n\n'
            plan = """$kinds = [];
foreach ($rows as &$row) {
    foreach ($row as $column => &$value) {
        $kind = $kinds[$column] ??= isset($schema[$column])
            ? AttributeCodec::encodingKind($schema[$column]['type']) : -1;
        if ($kind === -1) {
""" + textwrap.indent(unknown, '            ').replace('            return $value;', '') + """
        } else {
            $value = AttributeCodec::encodeKind($kind, $value);
        }
    }
    unset($value);
}
unset($row);"""
        text = once(text, '    public static function decode(string $type, mixed $value): mixed', additions + '    public static function decode(string $type, mixed $value): mixed')
        (target / codec).write_text(text, encoding='utf-8', newline='\n')
        text = (target / query).read_text()
        marker = '        $rows = $multiple ? $values : [$values];\n        foreach ($rows as &$row) {'
        planned = """        $rows = $multiple ? $values : [$values];
        // No cross-query cache; custom connections retain metadata/encoding behavior.
        if ($multiple && count($rows) > 1 && get_class($this->connection) === ManticoreConnection::class) {
""" + textwrap.indent(plan, '            ') + """
            return $rows;
        }
        foreach ($rows as &$row) {"""
        text = once(text, marker, planned)
        (target / query).write_text(text, encoding='utf-8', newline='\n')
    timed = output / (variant + '_timed')
    shutil.copytree(target, timed)
    (timed / query).write_text(timer((timed / query).read_text()), encoding='utf-8', newline='\n')
for target in sorted(p for p in output.iterdir() if p.is_dir()):
    manifest[target.name] = {str(p.relative_to(target)): hashlib.sha256(p.read_bytes()).hexdigest()
        for p in sorted(target.rglob('*')) if p.is_file()}
(output / 'manifest.json').write_text(json.dumps(manifest, indent=2))
print(json.dumps({'ok': True, 'variants': list(manifest)}))
