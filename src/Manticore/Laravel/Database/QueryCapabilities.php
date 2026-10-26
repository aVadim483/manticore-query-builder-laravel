<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use LogicException;

/** Reject SQL clauses that the base Laravel grammar would omit or compile incompatibly. */
final class QueryCapabilities
{
    public static function unsupportedDynamicMethod(string $method): bool
    {
        return (bool) preg_match('/^(?:or)?where(?:Like|NotLike|Binary|NotBinary|NullSafeEquals|ValueBetween|ValueNotBetween|VectorDistanceLessThan|AfterToday|BeforeToday|Today|TodayOrAfter|TodayOrBefore|Past|NowOrPast|Future|NowOrFuture)$/iD', $method);
    }

    public static function select(Builder $query): void
    {
        if ($query->joins || $query->unions || $query->distinct || $query->lock !== null
            || isset($query->groupLimit) || ($query->indexHint ?? null) !== null || ($query->timeout ?? null) !== null) {
            throw new LogicException('Manticore does not support Laravel joins, unions, DISTINCT, locks, group limits, index hints or timeout().');
        }
        self::wheres($query);
        foreach ($query->havings ?? [] as $having) {
            if (!in_array(strtolower($having['type']), ['basic', 'raw', 'between'], true)) {
                throw new LogicException('Unsupported Manticore HAVING condition: '.$having['type']);
            }
            if (strtolower($having['type']) === 'between' && count($having['values']) !== 2) {
                throw new InvalidArgumentException('havingBetween() requires exactly two boundary values.');
            }
        }
        foreach ($query->orders ?? [] as $order) {
            if (($order['type'] ?? null) === 'InOrderOf') {
                throw new LogicException('inOrderOf() is not supported. Use a native SELECT expression and order by its alias.');
            }
        }
    }

    public static function write(Builder $query, bool $payload = false): void
    {
        if (!is_string($query->from) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $query->from)) {
            throw new LogicException('Manticore writes require one plain table name without an alias or subquery.');
        }
        if (($query->columns !== null && $query->columns !== ['*']) || $query->aggregate || $query->distinct
            || $query->joins || $query->unions || $query->groups || $query->havings || $query->orders
            || $query->limit !== null || $query->offset !== null || $query->lock !== null || isset($query->groupLimit)
            || ($query->indexHint ?? null) !== null || ($query->timeout ?? null) !== null || ($query->fetchUsing ?? [])
            || ($query instanceof ManticoreQueryBuilder && ($query->manticoreOptions || $query->getSearchExpressions()
                || $query->hasKnn() || $query->getFacets())) || ($payload && $query->wheres)) {
            throw new LogicException('Manticore writes do not accept projections, aggregates, DISTINCT, joins, unions, grouping, HAVING, ordering, windows, locks, hints, timeouts or search expressions; INSERT/REPLACE/TRUNCATE also reject WHERE.');
        }
        self::wheres($query);
    }

    private static function wheres(Builder $query, bool $nested = false): int
    {
        $matches = 0;
        $hasOr = false;
        foreach ($query->wheres ?? [] as $index => $where) {
            $type = strtolower($where['type']);
            $hasOr = $hasOr || ($index > 0 && str_starts_with(strtolower($where['boolean']), 'or'));
            if (!in_array($type, ['basic', 'raw', 'expression', 'in', 'notin', 'inraw', 'notinraw', 'integerinraw', 'integernotinraw',
                'between', 'null', 'notnull', 'nested', 'jsonboolean', 'fulltext', 'match', 'mva', 'knn'], true)) {
                throw new LogicException('Unsupported Manticore predicate: '.$where['type'].'. Compute native expressions in SELECT and filter by their aliases.');
            }
            if ($type === 'basic' && !in_array(strtolower($where['operator']), ['=', '!=', '<>', '<', '<=', '>', '>='], true)) {
                throw new LogicException('Unsupported Manticore WHERE operator: '.$where['operator']);
            }
            if ($type === 'between' && (!is_countable($where['values']) || count($where['values']) !== 2)) {
                throw new InvalidArgumentException('whereBetween() requires exactly two boundary values.');
            }
            if ($type === 'match') {
                if ($nested || strtolower($where['boolean']) !== 'and') {
                    throw new LogicException('match() requires a top-level AND condition. Use whereFullText() for nested text groups.');
                }
                $matches++;
            }
            if ($type === 'nested') {
                $matches += self::wheres($where['query'], true);
            }
        }
        if ($matches > 1 || ($matches && $hasOr)) {
            throw new LogicException('Use one MATCH condition with AND attribute filters; OR must be inside a separate attribute group.');
        }

        return $matches;
    }

    public static function payload(array $values, bool $single = false): void
    {
        if (!$values || ($single && array_is_list($values))) {
            throw new InvalidArgumentException('This write requires a non-empty associative row.');
        }
        $rows = array_is_list($values) ? $values : [$values];
        $columns = null;
        foreach ($rows as $row) {
            if (!is_array($row) || !$row || array_is_list($row)) {
                throw new InvalidArgumentException('Write payloads must be associative rows.');
            }
            $keys = array_keys($row);
            foreach ($keys as $key) {
                if (!is_string($key) || $key === '') {
                    throw new InvalidArgumentException('Write columns must have non-empty names.');
                }
                if (str_contains($key, '->')) {
                    throw new LogicException('JSON-path assignments are not supported; write the complete JSON attribute.');
                }
            }
            sort($keys);
            $columns ??= $keys;
            if ($keys !== $columns) {
                throw new InvalidArgumentException('All rows in an INSERT/REPLACE batch must have identical column sets.');
            }
        }
    }
}
