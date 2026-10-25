<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

/** @internal Read-before-write helpers; transactionality does not imply isolation. */
final class DocumentWrites
{
    private ManticoreQueryBuilder $query;
    private ManticoreConnection $connection;

    public function __construct(ManticoreQueryBuilder $source)
    {
        $this->query = clone $source;
        $this->query->applyBeforeQueryCallbacks();
        $query = $this->query;
        if (!is_string($query->from) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $query->from)
            || ($query->columns !== null && $query->columns !== ['*']) || $query->joins || $query->unions
            || $query->groups || $query->havings || $query->aggregate || $query->distinct || $query->lock !== null
            || $query->orders || $query->limit !== null || $query->offset !== null || $query->manticoreOptions
            || $query->hasKnn() || $query->getFacets() || $query->hasQueryCallbacks()) {
            throw new LogicException('Read-before-write helpers require one table without projection, ordering, windows, search options or after-query callbacks.');
        }
        foreach ($query->getSearchExpressions() as $expression) {
            if ($expression['visible']) {
                throw new LogicException('Read-before-write helpers do not accept computed projections.');
            }
        }
        $this->connection = $query->getConnection();
    }

    public function updateOrInsert(array $attributes, array|callable $values, bool $replace): bool
    {
        $this->validateAttributes($attributes);
        if (is_array($values) && !is_callable($values)) {
            $this->validateRow($values, true);
            $this->assertSameId($attributes, $values);
        }
        $schema = $this->schema();
        $this->validateLookupColumns($attributes, $schema);
        $existing = $this->find($attributes);
        $values = $values instanceof Closure || is_callable($values) ? $values($existing !== null) : $values;
        if (!is_array($values)) {
            throw new InvalidArgumentException('The updateOrInsert/replaceOrInsert callback must return an associative row.');
        }
        $this->validateRow($values, true);
        $this->assertSameId($attributes, $values);
        if ($existing === null) {
            $row = array_merge($attributes, $values);
            $this->validateColumns($row, $schema);

            return $this->writer()->insert($this->encodeRow($row, $schema));
        }
        $this->assertSameId(['id' => $existing['id']], $values);
        if ($replace) {
            $row = array_merge($attributes, $values, ['id' => $existing['id']]);
            $this->validateColumns($row, $schema);

            return $this->writer()->replace($this->encodeRow($row, $schema));
        }
        unset($values['id']);
        if (!$values) {
            return true;
        }
        $this->validateColumns($values, $schema);
        foreach ($values as $column => $value) {
            if (($schema[$column]['type'] ?? null) === 'text') {
                throw new LogicException('Full-text fields require replaceOrInsert(), not updateOrInsert().');
            }
        }

        return (bool) $this->writer()->where('id', $this->bindingId($existing['id']))->update($this->encodeRow($values, $schema));
    }

    public function upsert(array $values, mixed $uniqueBy, mixed $update): int
    {
        if ($this->query->wheres) {
            throw new LogicException('upsert() does not accept WHERE conditions. Put lookup columns in uniqueBy.');
        }
        $uniqueBy = is_string($uniqueBy) ? [$uniqueBy] : $uniqueBy;
        $this->validateColumnList($uniqueBy, 'uniqueBy', false);
        $rows = array_is_list($values) ? $values : [$values];
        $columns = null;
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('upsert() requires an associative row or a list of rows.');
            }
            $this->validateRow($row);
            $keys = array_keys($row);
            sort($keys);
            $columns ??= $keys;
            if ($keys !== $columns) {
                throw new InvalidArgumentException('All upsert rows must contain the same columns.');
            }
            $attributes = [];
            foreach ($uniqueBy as $column) {
                if (!array_key_exists($column, $row)) {
                    throw new InvalidArgumentException('Missing upsert lookup column: '.$column);
                }
                $attributes[$column] = $row[$column];
            }
            $this->validateAttributes($attributes);
            $key = serialize($attributes);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Duplicate lookup values in the upsert batch.');
            }
            $seen[$key] = true;
        }
        $update ??= $columns;
        $this->validateColumnList($update, 'update', true);
        if (array_diff($update, $columns)) {
            throw new InvalidArgumentException('Every update column must be present in every upsert row.');
        }
        $schema = $this->schema();
        foreach ($rows as $row) {
            $this->validateColumns($row, $schema);
        }
        $this->validateLookupColumns(array_fill_keys($uniqueBy, null), $schema);

        $operation = function () use ($rows, $uniqueBy, $update, $schema): int {
            if ($update === []) {
                $encoded = array_map(fn ($row) => $this->encodeRow($row, $schema), $rows);
                return $this->writer()->insert($encoded) ? count($rows) : 0;
            }
            $plans = [];
            $ids = [];
            // Pending writes are invisible even to the current Manticore transaction.
            // Resolve every document before staging the first INSERT or REPLACE.
            foreach ($rows as $row) {
                $attributes = array_intersect_key($row, array_flip($uniqueBy));
                $existing = $this->find($attributes, true);
                if ($existing === null) {
                    $plans[] = ['insert', $this->encodeRow($row, $schema)];
                    $id = $row['id'] ?? null;
                } else {
                    $changes = array_intersect_key($row, array_flip($update));
                    $this->assertSameId(['id' => $existing['id']], $changes);
                    $snapshot = $this->snapshot($existing, $schema, $changes);
                    $plans[] = ['replace', $this->encodeRow(array_merge($snapshot, $changes, ['id' => $existing['id']]), $schema)];
                    $id = $existing['id'];
                }
                if ($id !== null && self::idKey($id) !== '0') {
                    $key = self::idKey($id);
                    if (isset($ids[$key])) {
                        throw new LogicException('Multiple upsert rows resolve to the same document ID.');
                    }
                    $ids[$key] = true;
                }
            }
            foreach ($plans as [$method, $row]) {
                if (!$this->writer()->$method($row)) {
                    throw new RuntimeException('Manticore refused an upsert '.$method.' operation.');
                }
            }

            return count($rows);
        };

        // Join an explicit caller transaction without unsupported nested BEGIN/savepoints.
        if ($this->connection->pretending()) {
            return $operation();
        }
        if ($this->connection->transactionLevel() === 0) {
            return $this->connection->transaction($operation);
        }
        try {
            return $operation();
        } catch (Throwable $error) {
            // Without savepoints, an error must abort the whole caller transaction.
            try {
                $this->connection->rollBack();
            } catch (Throwable $rollbackError) {
                throw new TransactionRollbackException($error, $rollbackError);
            }
            throw $error;
        }
    }

    private function find(array $attributes, bool $full = false): ?array
    {
        $lookup = clone $this->query;
        $schema = $this->schema();
        $lookup->select($full ? ['*'] : ['id'])->where(function ($query) use ($attributes, $schema) {
            foreach ($attributes as $column => $value) {
                $query->where($column, $this->lookupValue($column, $value, $schema));
            }
        })->limit(2)->useWritePdo();
        $rows = $this->connection->selectFromWriteConnection($lookup->toSql(), $lookup->getBindings());
        if (count($rows) > 1) {
            throw new LogicException('Lookup attributes match multiple documents; use an unambiguous key.');
        }

        return $rows ? (array) $rows[0] : null;
    }

    private function snapshot(array $row, array $schema, array $changes): array
    {
        $snapshot = [];
        foreach ($schema as $column => $info) {
            if ($info['type'] === 'text' && !preg_match('/\bstored\b/i', $info['properties'])) {
                if (!array_key_exists($column, $changes)) {
                    throw new LogicException('Cannot preserve unstored full-text field: '.$column.'. Include it in the upsert update columns and values.');
                }
                continue;
            }
            if (!array_key_exists($column, $row)) {
                throw new LogicException('Cannot read a field required for REPLACE: '.$column);
            }
            // Preserve JSON text verbatim: {} must not become [], and SQL empty JSON is null.
            $snapshot[$column] = $info['type'] === 'json'
                ? ($row[$column] === '' || $row[$column] === null ? 'null' : $row[$column])
                : AttributeCodec::decode($info['type'], $row[$column]);
        }

        return $snapshot;
    }

    private function writer(): ManticoreQueryBuilder
    {
        return $this->query->newQuery()->from($this->query->from)->useWritePdo();
    }

    private function schema(): array
    {
        return $this->connection->getTableSchema($this->query->from);
    }

    private function validateAttributes(array $attributes): void
    {
        $this->validateRow($attributes);
        foreach ($attributes as $column => $value) {
            if ($value === null || !is_scalar($value)) {
                throw new InvalidArgumentException('Lookup values must be non-null scalars: '.$column);
            }
            if (is_float($value) && !is_finite($value)) {
                throw new InvalidArgumentException('Lookup values must be finite.');
            }
        }
        if (array_key_exists('id', $attributes) && self::idKey($attributes['id']) === '0') {
            throw new InvalidArgumentException('Document ID 0 is an auto-ID marker and cannot be a lookup key.');
        }
    }

    private function validateRow(array $row, bool $empty = false): void
    {
        if ((!$row && !$empty) || ($row && array_is_list($row))) {
            throw new InvalidArgumentException('Read-before-write helpers require associative rows.');
        }
        foreach ($row as $column => $value) {
            if (!is_string($column) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $column)) {
                throw new InvalidArgumentException('Write columns must be simple identifiers.');
            }
            if ($value instanceof Expression) {
                throw new InvalidArgumentException('Read-before-write helpers do not accept SQL expression values.');
            }
        }
        if (array_key_exists('id', $row)) {
            self::idKey($row['id']);
        }
    }

    private function validateColumns(array $row, array $schema): void
    {
        foreach ($row as $column => $value) {
            if ($schema && !isset($schema[$column])) {
                throw new InvalidArgumentException('Unknown write column: '.$column);
            }
            if (isset($schema[$column])) {
                AttributeCodec::encode($schema[$column]['type'], $value);
            }
        }
    }

    private function encodeRow(array $row, array $schema): array
    {
        foreach ($row as $column => &$value) {
            if (isset($schema[$column])) {
                $value = AttributeCodec::encode($schema[$column]['type'], $value);
            }
            if ($column === 'id') {
                $literal = self::idKey($value);
                $value = (string) (int) $literal === $literal ? (int) $literal : $this->query->raw($literal);
            }
        }
        unset($value);

        return $row;
    }

    private function bindingId(mixed $id): mixed
    {
        $literal = self::idKey($id);
        if (strlen($literal) > 19 || (strlen($literal) === 19 && strcmp($literal, '9223372036854775807') > 0)) {
            // Convert unsigned SQL IDs to signed decimal without float conversion.
            $digits = str_pad($literal, 20, '0', STR_PAD_LEFT);
            $maximum = '18446744073709551616';
            $difference = '';
            $borrow = 0;
            for ($i = 19; $i >= 0; $i--) {
                $digit = (int) $maximum[$i] - (int) $digits[$i] - $borrow;
                $borrow = $digit < 0 ? 1 : 0;
                $difference = (string) ($digit + ($borrow ? 10 : 0)).$difference;
            }
            $literal = '-'.ltrim($difference, '0');
        }

        return (string) (int) $literal === $literal ? (int) $literal : $this->query->raw($literal);
    }

    private function lookupValue(string $column, mixed $value, array $schema): mixed
    {
        if ($column === 'id') {
            return $this->bindingId($value);
        }
        $type = $schema[$column]['type'] ?? null;
        if ($type === 'string') {
            return is_bool($value) ? (string) (int) $value : (string) $value;
        }
        if ($type === 'float' && is_string($value) && is_numeric($value)) {
            $number = (float) $value;
            if (!is_finite($number)) {
                throw new InvalidArgumentException('Lookup values must be finite.');
            }
            return $number;
        }
        if (is_string($value) && in_array($type, ['integer', 'int', 'bigint', 'uint', 'uint32', 'uint64', 'timestamp', 'bool', 'boolean'], true)
            && preg_match('/^-?[0-9]+$/D', $value)) {
            return (string) (int) $value === $value ? (int) $value : $this->query->raw($value);
        }

        return $value;
    }

    private function validateLookupColumns(array $attributes, array $schema): void
    {
        foreach ($attributes as $column => $value) {
            if ($schema && (!isset($schema[$column]) || !in_array($schema[$column]['type'],
                ['bigint', 'integer', 'int', 'uint', 'uint32', 'uint64', 'float', 'bool', 'boolean', 'timestamp', 'string'], true))) {
                throw new InvalidArgumentException('Lookup columns must be existing scalar attributes: '.$column);
            }
        }
    }

    private function validateColumnList(mixed $columns, string $name, bool $empty): void
    {
        if (!is_array($columns) || !array_is_list($columns) || (!$columns && !$empty)) {
            throw new InvalidArgumentException($name.' must be '.($empty ? 'a' : 'a non-empty').' list of column names.');
        }
        foreach ($columns as $column) {
            if (!is_string($column) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $column)) {
                throw new InvalidArgumentException('Invalid '.$name.' column name.');
            }
        }
        if (count(array_unique($columns)) !== count($columns)) {
            throw new InvalidArgumentException($name.' contains duplicate column names.');
        }
    }

    private function assertSameId(array $attributes, array $values): void
    {
        if (array_key_exists('id', $attributes) && array_key_exists('id', $values)
            && self::idKey($attributes['id']) !== self::idKey($values['id'])) {
            throw new InvalidArgumentException('Changing an existing document ID is not supported.');
        }
    }

    private static function idKey(mixed $id): string
    {
        if ((!is_int($id) && !is_string($id)) || !preg_match('/^-?[0-9]+$/D', (string) $id)) {
            throw new InvalidArgumentException('Document IDs must be integers or integer strings.');
        }
        $id = (string) $id;
        $negative = str_starts_with($id, '-');
        $magnitude = ltrim($negative ? substr($id, 1) : $id, '0') ?: '0';
        $maximum = $negative ? '9223372036854775808' : '18446744073709551615';
        if (strlen($magnitude) > strlen($maximum)
            || (strlen($magnitude) === strlen($maximum) && strcmp($magnitude, $maximum) > 0)) {
            throw new InvalidArgumentException('Document ID is outside the supported 64-bit range.');
        }

        return $negative && $magnitude !== '0' ? sprintf('%u', (int) $id) : $magnitude;
    }
}
