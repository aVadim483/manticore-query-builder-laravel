"""Create an isolated literal-write experiment; installed package sources stay intact."""
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
target = pathlib.Path(args.output).resolve()
assert target != source and source not in target.parents and not target.exists()
shutil.copytree(source, target)
query_file = target / 'src/Manticore/Laravel/Database/ManticoreQueryBuilder.php'
connection_file = target / 'src/Manticore/Laravel/Database/ManticoreConnection.php'

def once(text, old, new):
    assert text.count(old) == 1, 'Unexpected source: ' + old[:100]
    return text.replace(old, new, 1)

text = query_file.read_text()
text = once(text, '&& get_class($this) === self::class && get_class($this->grammar) === ManticoreGrammar::class;',
    '''&& get_class($this) === self::class && get_class($this->grammar) === ManticoreGrammar::class
            && get_class($this->connection) === ManticoreConnection::class
            && $this->connection->canExecuteLiteralInsert();''')
text = once(text, '        $parameters = [];\n        $bindings = [];',
    '        $parameters = [];\n        $literalParameters = [];\n        $bindings = [];\n        $pdo = null;')
text = once(text, '        $columns = array_keys($values[0]);', '''        // Validate the whole batch before quoting can resolve a lazy PDO connection.
        foreach ($values as $row) foreach ($row as $value) {
            if (is_float($value) && !is_finite($value)) {
                throw new InvalidArgumentException('Manticore parameters must be finite numbers.');
            }
        }
        $columns = array_keys($values[0]);''')
text = once(text, '            $slots = [];\n            foreach ($columns as $column)',
    '            $slots = [];\n            $literalSlots = [];\n            foreach ($columns as $column)')
text = once(text, '                    $slots[] = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);',
    '''                    $literal = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                    $slots[] = $literal;
                    $literalSlots[] = $literal;''')
text = text.replace('unset($parameters, $bindings, $slots);', 'unset($parameters, $literalParameters, $bindings, $slots, $literalSlots);')
text = once(text, '''                    $slots[] = $literal;
                } else {
                    $slots[] = '?';
                    $bindings[] = $this->castBinding($value);
                }''', '''                    $slots[] = $literal;
                    $literalSlots[] = $literal;
                } else {
                    $slots[] = '?';
                    $binding = $this->castBinding($value);
                    $bindings[] = $binding;
                    if (is_int($binding)) {
                        $literalSlots[] = (string)$binding;
                    } elseif (is_bool($binding)) {
                        $literalSlots[] = $binding ? '1' : '0';
                    } elseif ($binding === null) {
                        $literalSlots[] = 'NULL';
                    } elseif (is_string($binding)) {
                        $pdo ??= $this->connection->getPdo();
                        $quoted = $pdo->quote($binding);
                        if ($quoted === false) {
                            unset($parameters, $literalParameters, $bindings, $slots, $literalSlots);
                            return $this->compileGenericBatch($values, $replace);
                        }
                        $literalSlots[] = $quoted;
                    } else {
                        unset($parameters, $literalParameters, $bindings, $slots, $literalSlots);
                        return $this->compileGenericBatch($values, $replace);
                    }
                }''')
text = once(text, '''                    $slots[] = $literal;
                    $literalSlots[] = $literal;
                } else {''', r'''                    // Only codec-style numeric tuples are safe without PDO placeholder parsing.
                    // Arbitrary raw expressions retain their original binding/error semantics.
                    if (is_string($literal) && !preg_match('/^\((?:-?[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?(?:, -?[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)*)?\)$/D', $literal)) {
                        unset($parameters, $literalParameters, $bindings, $slots, $literalSlots);
                        return $this->compileGenericBatch($values, $replace);
                    }
                    $slots[] = $literal;
                    $literalSlots[] = $literal;
                } else {''')
text = once(text, "            $parameters[] = '('.implode(', ', $slots).')';",
    "            $parameters[] = '('.implode(', ', $slots).')';\n            $literalParameters[] = '('.implode(', ', $literalSlots).')';")
text = once(text, '        return ["$verb into $table ($columnSql) values ".implode(\', \', $parameters), $bindings];',
    '''        return ["$verb into $table ($columnSql) values ".implode(', ', $parameters), $bindings,
            "$verb into $table ($columnSql) values ".implode(', ', $literalParameters)];''')
text = once(text, '''            [$sql, $bindings] = $this->compileBatch($values, false);

            return $this->connection->insert($sql, $bindings);''', '''            $compiled = $this->compileBatch($values, false);
            [$sql, $bindings] = $compiled;

            return isset($compiled[2]) ? $this->connection->insertLiteral($sql, $bindings, $compiled[2])
                : $this->connection->insert($sql, $bindings);''')
text = once(text, '''        [$sql, $bindings] = $this->compileReplacement($values);

        return $this->connection->insert($sql, $bindings);''', '''        $compiled = $this->compileReplacement($values);
        [$sql, $bindings] = $compiled;

        return isset($compiled[2]) ? $this->connection->insertLiteral($sql, $bindings, $compiled[2])
            : $this->connection->insert($sql, $bindings);''')
query_file.write_text(text, encoding='utf-8', newline='\n')

text = connection_file.read_text()
text = once(text, '    public function insertAndGetId(string $sql, array $bindings = []): int|string',
    '''    // Experimental API, generated only in an isolated benchmark snapshot.
    public function canExecuteLiteralInsert(): bool
    {
        return get_class($this) === self::class && !$this->beforeExecutingCallbacks && !$this->pretending();
    }

    public function insertLiteral(string $query, array $bindings, string $literal): bool
    {
        if (!$this->canExecuteLiteralInsert()) return $this->insert($query, $bindings);
        return $this->run($query, $bindings, function () use ($literal) {
            if ($this->pretending()) return true;
            $this->recordsHaveBeenModified();
            return $this->getPdo()->exec($literal) !== false;
        });
    }

    public function insertAndGetId(string $sql, array $bindings = []): int|string''')
connection_file.write_text(text, encoding='utf-8', newline='\n')
manifest = {str(p.relative_to(target)): hashlib.sha256(p.read_bytes()).hexdigest()
            for p in sorted(target.rglob('*')) if p.is_file()}
print(json.dumps({'ok': True, 'source': str(source), 'candidate': str(target), 'manifest': manifest}, indent=2))
