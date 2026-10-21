<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use InvalidArgumentException;
use LogicException;

/** Validate keyset pagination before Laravel builds its lexicographic boundary conditions. */
final class CursorPagination
{
    public static function prepare(ManticoreQueryBuilder $query, $columns, string $cursorName, $cursor): ?Cursor
    {
        if ($query->groups || $query->havings || $query->aggregate || $query->unions || $query->joins
            || $query->distinct || $query->getFacets() || $query->hasKnn() || $query->offset) {
            throw new LogicException('Cursor pagination requires an ordinary document query without groups, facets, KNN or offsets.');
        }
        if (!is_string($query->from) || !preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)(?:\s+(?:as\s+)?[a-zA-Z_][a-zA-Z0-9_]*)?$/iD', $query->from, $table)) {
            throw new LogicException('Cursor pagination requires a single table.');
        }
        $query->columns ??= is_array($columns) ? $columns : [$columns];
        $selected = [];
        $star = false;
        foreach ($query->columns as $column) {
            if ($column === '*' || (is_string($column) && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*\.\*$/D', $column))) {
                $star = true;
            } elseif (is_string($column) && preg_match('/^(?:[a-zA-Z_][a-zA-Z0-9_]*\.)?([a-zA-Z_][a-zA-Z0-9_]*)(?:\s+as\s+([a-zA-Z_][a-zA-Z0-9_]*))?$/iD', $column, $parts)) {
                $alias = $parts[2] ?? $parts[1];
                if (isset($selected[$alias])) {
                    throw new LogicException('Cursor pagination requires unique selected column names.');
                }
                $selected[$alias] = $parts[1];
            } else {
                throw new LogicException('Cursor pagination supports simple selected columns and aliases, not raw projections.');
            }
        }
        $idAlias = array_search('id', $selected, true);
        $orders = $query->orders ?: [['column' => $idAlias === false ? 'id' : $idAlias, 'direction' => 'asc']];
        foreach ($orders as $order) {
            if (($order['type'] ?? null) === 'Raw' || !isset($order['column'], $order['direction'])
                || !is_string($order['column']) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $order['column'])) {
                throw new LogicException('Cursor ordering requires selected integer attributes or aliases.');
            }
        }
        $last = end($orders);
        if (($selected[$last['column']] ?? $last['column']) !== 'id') {
            $idAlias = array_search('id', $selected, true);
            $orders[] = ['column' => $idAlias === false ? 'id' : $idAlias, 'direction' => 'asc'];
        }
        $schema = $query->getConnection()->getTableSchema($table[1]);
        $types = [];
        foreach ($orders as $order) {
            $alias = $order['column'];
            if (!$star && !array_key_exists($alias, $selected)) {
                throw new LogicException('Select every cursor ordering column, including the document ID.');
            }
            $name = $selected[$alias] ?? $alias;
            $type = $schema[$name]['type'] ?? null;
            if (!in_array($type, ['int', 'integer', 'uint', 'uint32', 'uint64', 'bigint', 'bool', 'boolean', 'timestamp'], true)
                || isset($query->getSearchExpressions()[$alias])) {
                // SQL float output can round a boundary (e.g. 1.4285714e-7 to 0.00000014).
                throw new LogicException('Cursor ordering supports integer, boolean and timestamp attributes and the document ID only; SQL float output is not a lossless boundary.');
            }
            if (isset($types[$alias])) {
                throw new LogicException('Cursor ordering columns must be unique.');
            }
            $types[$alias] = $type;
        }
        $query->orders = $orders;
        if ($cursor === null) {
            $cursor = CursorPaginator::resolveCurrentCursor($cursorName);
        }
        if (is_string($cursor)) {
            $cursor = Cursor::fromEncoded($cursor) ?? throw new InvalidArgumentException('Invalid pagination cursor.');
        }
        if ($cursor === null) {
            return null;
        }
        if (!$cursor instanceof Cursor || !is_bool($cursor->toArray()['_pointsToNextItems'])) {
            throw new InvalidArgumentException('Invalid pagination cursor.');
        }
        $parameters = [];
        foreach ($types as $alias => $type) {
            try {
                $value = $cursor->parameter($alias);
            } catch (\UnexpectedValueException $error) {
                throw new InvalidArgumentException('The cursor is missing an ordering value: '.$alias, 0, $error);
            }
            if (is_bool($value) && in_array($type, ['bool', 'boolean'], true)) {
                $value = (int)$value;
            }
            if (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) && (string)(int)$value === $value) {
                $value = (int)$value;
            }
            if (!is_int($value)) {
                throw new InvalidArgumentException('Integer cursor values must fit the PHP integer range.');
            }
            $parameters[$alias] = $value;
        }
        return new Cursor($parameters, $cursor->pointsToNextItems());
    }
}
