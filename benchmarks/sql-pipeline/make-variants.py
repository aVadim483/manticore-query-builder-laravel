"""Generate reversible benchmark-only source variants, never patch installed vendor."""
import argparse
import hashlib
import json
import pathlib
import shutil

parser = argparse.ArgumentParser()
parser.add_argument('--source', required=True)
parser.add_argument('--output', required=True)
args = parser.parse_args()
source = pathlib.Path(args.source).resolve()
output = pathlib.Path(args.output).resolve()
if output == source or source in output.parents:
    parser.error('Output must be outside the source snapshot')
query = 'src/Manticore/Laravel/Database/ManticoreQueryBuilder.php'
grammar = 'src/Manticore/Laravel/Database/ManticoreGrammar.php'
connection = 'src/Manticore/Laravel/Database/ManticoreConnection.php'

def replace_once(text, old, new):
    assert text.count(old) == 1, 'Patch no longer matches the source: ' + old[:100]
    return text.replace(old, new, 1)

def single_compile(text):
    text = replace_once(text,
        '$this->toSql(); // Validate before DESCRIBE can open a connection.',
        '$compiledSql = $this->toSql(); // Validate before DESCRIBE can open a connection.')
    text = replace_once(text, 'return parent::runSelect();',
        'return $this->connection->select($compiledSql, $this->getBindings(), !$this->useWritePdo, $this->fetchUsing ?? []);')
    text = replace_once(text,
        '$this->toSql();\n            $this->prepareReadCasts();\n            $result = $this->connection->search($this->toSql(),',
        '$compiledSql = $this->toSql();\n            $this->prepareReadCasts();\n            $result = $this->connection->search($compiledSql,')
    return text

def vector_json(text):
    return replace_once(text,
        "$vector = implode(', ', array_map(fn ($value) => json_encode($value, JSON_THROW_ON_ERROR), $where['vector']));",
        "$vector = str_replace(',', ', ', substr(json_encode($where['vector'], JSON_THROW_ON_ERROR), 1, -1));")

def fused_rows(text):
    start = text.index('    private function encodeInsertRows(array $values): array')
    end = text.index('    public function insert(array $values)', start)
    body = '''    private function encodeInsertRows(array $values): array
    {
        if (!$values) return [];
        $this->applyBeforeQueryCallbacks();
        $table = $this->plainTable();
        $schema = $table !== null && $this->connection->getConfig('cast_attributes') !== false
            ? $this->connection->getTableSchema($table) : [];
        $multiple = array_is_list($values) && is_array($values[0]);
        $rows = [];
        foreach ($multiple ? $values : [$values] as $row) {
            $encoded = [];
            foreach ($row as $column => $value) {
                if (isset($schema[$column])) {
                    $value = AttributeCodec::encode($schema[$column]['type'], $value);
                } elseif (is_array($value) || (is_object($value) && !$value instanceof ExpressionContract)) {
                    throw new InvalidArgumentException('A structured value requires a known JSON, MVA or vector column.');
                }
                if (is_float($value)) {
                    if (!is_finite($value)) throw new InvalidArgumentException('Manticore parameters must be finite numbers.');
                    $value = new Expression(json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
                }
                $encoded[$column] = $value;
            }
            $rows[] = $encoded;
        }
        return $multiple ? $rows : $rows[0];
    }

'''
    return text[:start] + body + text[end:]

def loop_bindings(text):
    return replace_once(text, '    private function encodeInsertRows(array $values): array', '''    public function cleanBindings(array $bindings)
    {
        $clean = [];
        foreach ($bindings as $binding) {
            if (!$binding instanceof ExpressionContract) $clean[] = $this->castBinding($binding);
        }
        return $clean;
    }

    private function encodeInsertRows(array $values): array''')

def update_validation(text):
    return replace_once(text, '$this->grammar->compileUpdate($this, $values);',
                        'QueryCapabilities::write($this);')

def execute_array(text):
    return replace_once(text, '    public function insertAndGetId(string $sql, array $bindings = []): int|string', '''    // Benchmark only: execute(array) binds scalar values as strings in PDO.
    public function insert($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($sql, $parameters) {
            if ($this->pretending()) return true;
            $this->recordsHaveBeenModified();
            return $this->getPdo()->prepare($sql)->execute($this->prepareBindings($parameters));
        });
    }

    public function insertAndGetId(string $sql, array $bindings = []): int|string''')

patches = {'single_compile': (query, single_compile), 'vector_json': (grammar, vector_json),
           'fused_rows': (query, fused_rows), 'loop_bindings': (query, loop_bindings),
           'update_validation': (query, update_validation), 'execute_array': (connection, execute_array)}
manifest = {}
for variant in [*patches, 'combined_safe', 'combined']:
    target = output / variant
    if target.exists():
        raise RuntimeError('Variant already exists: ' + str(target))
    shutil.copytree(source, target)
    names = patches if variant == 'combined' else ([name for name in patches if name != 'execute_array'] if variant == 'combined_safe' else [variant])
    for name in names:
        relative, patch = patches[name]
        file = target / relative
        file.write_text(patch(file.read_text()), encoding='utf-8', newline='\n')
    manifest[variant] = {str(p.relative_to(target)): hashlib.sha256(p.read_bytes()).hexdigest()
                         for p in sorted(target.rglob('*')) if p.is_file()}
output.mkdir(parents=True, exist_ok=True)
(output / 'manifest.json').write_text(json.dumps(manifest, indent=2))
print(json.dumps({'ok': True, 'variants': list(manifest)}))
