"""Render escaped SQL inside Laravel run(), using compiler-provided placeholder positions."""
import argparse
import hashlib
import json
import pathlib
import shutil

parser = argparse.ArgumentParser()
parser.add_argument('--source', required=True)
parser.add_argument('--output', required=True)
args = parser.parse_args()
source, target = pathlib.Path(args.source).resolve(), pathlib.Path(args.output).resolve()
assert source != target and source not in target.parents and not target.exists()
shutil.copytree(source, target)

def once(text, old, new):
    assert text.count(old) == 1, 'Unexpected source: ' + old[:100]
    return text.replace(old, new, 1)

file = target / 'src/Manticore/Laravel/Database/ManticoreQueryBuilder.php'
text = file.read_text()
text = once(text, '&& get_class($this) === self::class && get_class($this->grammar) === ManticoreGrammar::class;',
    '''&& get_class($this) === self::class && get_class($this->grammar) === ManticoreGrammar::class
            && get_class($this->connection) === ManticoreConnection::class
            && $this->connection->canExecuteLiteralInsert();''')
text = once(text, '        $parameters = [];\n        $bindings = [];', '''        $parameters = [];
        $bindings = [];
        $offsets = [];
        $parameterBytes = 0;
        $verb = $replace ? 'replace' : 'insert';
        $prefix = "$verb into $table ($columnSql) values ";
        $prefixBytes = strlen($prefix);''')
text = once(text, '            $slots = [];\n            foreach ($columns as $column)', '''            $slots = [];
            $rowOffsets = [];
            $slotOffset = 1;
            foreach ($columns as $column)''')
text = text.replace('unset($parameters, $bindings, $slots);', 'unset($parameters, $bindings, $slots, $offsets, $rowOffsets);')
text = once(text, '''                    $slots[] = $literal;
                } else {
                    $slots[] = '?';
                    $bindings[] = $this->castBinding($value);
                }
            }
            $parameters[] = '('.implode(', ', $slots).')';''', r'''                    if (is_string($literal) && !preg_match('/^\((?:-?[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?(?:, -?[0-9]+(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)*)?\)$/D', $literal)) {
                        unset($parameters, $bindings, $slots, $offsets, $rowOffsets);
                        return $this->compileGenericBatch($values, $replace);
                    }
                    $slots[] = $literal;
                } else {
                    $binding = $this->castBinding($value);
                    if (!is_string($binding) && !is_int($binding) && !is_bool($binding) && $binding !== null) {
                        unset($parameters, $bindings, $slots, $offsets, $rowOffsets);
                        return $this->compileGenericBatch($values, $replace);
                    }
                    $rowOffsets[] = $slotOffset;
                    $slots[] = '?';
                    $bindings[] = $binding;
                }
                $slotOffset += strlen((string)$slots[array_key_last($slots)]) + 2;
            }
            $rowSql = '('.implode(', ', $slots).')';
            $parameters[] = $rowSql;
            foreach ($rowOffsets as $offset) $offsets[] = $prefixBytes + $parameterBytes + $offset;
            $parameterBytes += strlen($rowSql) + 2;''')
text = once(text, '        return ["$verb into $table ($columnSql) values ".implode(\', \', $parameters), $bindings];',
    '        return [$prefix.implode(\', \', $parameters), $bindings, $offsets];')
text = once(text, '''            [$sql, $bindings] = $this->compileBatch($values, false);

            return $this->connection->insert($sql, $bindings);''', '''            $compiled = $this->compileBatch($values, false);
            [$sql, $bindings] = $compiled;
            return isset($compiled[2]) ? $this->connection->insertLiteralOffsets($sql, $bindings, $compiled[2])
                : $this->connection->insert($sql, $bindings);''')
text = once(text, '''        [$sql, $bindings] = $this->compileReplacement($values);

        return $this->connection->insert($sql, $bindings);''', '''        $compiled = $this->compileReplacement($values);
        [$sql, $bindings] = $compiled;
        return isset($compiled[2]) ? $this->connection->insertLiteralOffsets($sql, $bindings, $compiled[2])
            : $this->connection->insert($sql, $bindings);''')
file.write_text(text, encoding='utf-8', newline='\n')
file = target / 'src/Manticore/Laravel/Database/ManticoreConnection.php'
text = once(file.read_text(), '    public function insertAndGetId(string $sql, array $bindings = []): int|string', '''    // Experimental API in a private benchmark snapshot only.
    public function canExecuteLiteralInsert(): bool
    {
        return get_class($this) === self::class && !$this->beforeExecutingCallbacks && !$this->pretending();
    }

    public function insertLiteralOffsets(string $query, array $bindings, array $offsets): bool
    {
        if (!$this->canExecuteLiteralInsert() || count($offsets) !== count($bindings)) return $this->insert($query, $bindings);
        return $this->run($query, $bindings, function ($sql, $parameters) use ($offsets) {
            if ($this->pretending()) return true;
            $pdo = $this->getPdo();
            $parameters = $this->prepareBindings($parameters);
            $this->recordsHaveBeenModified();
            $parts = [];
            $start = 0;
            foreach ($offsets as $index => $offset) {
                $parts[] = substr($sql, $start, $offset - $start);
                $value = $parameters[$index];
                if (is_int($value)) $parts[] = (string)$value;
                elseif ($value === null) $parts[] = 'NULL';
                else {
                    $quoted = $pdo->quote($value);
                    if ($quoted === false) throw new RuntimeException('PDO could not quote the parameter.');
                    $parts[] = $quoted;
                }
                $start = $offset + 1;
            }
            $parts[] = substr($sql, $start);
            return $pdo->exec(implode('', $parts)) !== false;
        });
    }

    public function insertAndGetId(string $sql, array $bindings = []): int|string''')
file.write_text(text, encoding='utf-8', newline='\n')
manifest = {str(p.relative_to(target)): hashlib.sha256(p.read_bytes()).hexdigest()
    for p in sorted(target.rglob('*')) if p.is_file()}
print(json.dumps({'ok': True, 'manifest': manifest}, indent=2))
