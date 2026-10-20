<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use LogicException;
use Illuminate\Support\LazyCollection;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;

class ManticoreQueryBuilder extends Builder
{
    /** @var array<string, int|float|string|array|Ranker> */
    public array $manticoreOptions = [];

    /** @var array<string, array{sql: string, visible: bool}> */
    protected array $searchExpressions = [];
    protected array $readCasts = [];

    protected function plainTable(): ?string
    {
        return is_string($this->from) && preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)(?:\s+(?:as\s+)?[a-zA-Z_][a-zA-Z0-9_]*)?$/iD', $this->from, $match)
            ? $match[1] : null;
    }

    protected function prepareReadCasts(): void
    {
        $this->readCasts = [];
        if ($this->connection->getConfig('cast_attributes') === false || $this->connection->pretending() || $this->plainTable() === null) {
            return;
        }
        $schema = $this->connection->getTableSchema($this->plainTable());
        foreach ($this->columns ?? ['*'] as $column) {
            if ($column === '*' || (is_string($column) && str_ends_with($column, '.*'))) {
                foreach ($schema as $name => $info) {
                    $this->readCasts[$name] = $info['type'];
                }
            } elseif (is_string($column) && preg_match('/^(?:[a-zA-Z_][a-zA-Z0-9_]*\.)?([a-zA-Z_][a-zA-Z0-9_]*)(?:\s+as\s+([a-zA-Z_][a-zA-Z0-9_]*))?$/iD', $column, $match)) {
                if (isset($schema[$match[1]])) {
                    $this->readCasts[$match[2] ?? $match[1]] = $schema[$match[1]]['type'];
                }
            } elseif ($column instanceof ExpressionContract
                && preg_match('/\bas\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s*$/i', $column->getValue($this->grammar), $match)) {
                unset($this->readCasts[$match[1]]);
            }
        }
        foreach ($this->getSearchExpressions() as $alias => $expression) {
            unset($this->readCasts[$alias]);
        }
    }

    protected function runSelect()
    {
        $this->applyBeforeQueryCallbacks();
        $this->prepareReadCasts();

        return parent::runSelect();
    }

    public function cursor()
    {
        return new LazyCollection(function () {
            $this->applyBeforeQueryCallbacks();
            $this->prepareReadCasts();
            foreach ($this->connection->cursor($this->toSql(), $this->getBindings(), !$this->useWritePdo) as $row) {
                $row = $this->applyAfterQueryCallbacks(new Collection($this->processReadRows([$row])))->first();
                if ($row !== null) {
                    yield $row;
                }
            }
        });
    }

    protected function encodeWriteRows(array $values): array
    {
        if (!$values) {
            return [];
        }
        $this->applyBeforeQueryCallbacks();
        $table = $this->plainTable();
        $schema = $table !== null && $this->connection->getConfig('cast_attributes') !== false
            ? $this->connection->getTableSchema($table) : [];
        $multiple = array_is_list($values) && is_array($values[0]);
        $rows = $multiple ? $values : [$values];
        foreach ($rows as &$row) {
            foreach ($row as $column => &$value) {
                if (isset($schema[$column])) {
                    $value = AttributeCodec::encode($schema[$column]['type'], $value);
                } elseif (is_array($value) || (is_object($value) && !$value instanceof ExpressionContract)) {
                    throw new InvalidArgumentException('A structured value requires a known JSON, MVA or vector column.');
                }
            }
            unset($value);
        }
        unset($row);

        return $multiple ? $rows : $rows[0];
    }

    public function insert(array $values)
    {
        return parent::insert($this->encodeWriteRows($values));
    }

    public function insertGetId(array $values, $sequence = null)
    {
        return parent::insertGetId($this->encodeWriteRows($values), $sequence);
    }

    public function update(array $values)
    {
        $this->grammar->compileUpdate($this, $values);
        $values = $this->encodeWriteRows($values);
        $schema = $this->plainTable() === null || $this->connection->pretending() || $this->connection->getConfig('cast_attributes') === false
            ? [] : $this->connection->getTableSchema($this->plainTable());
        foreach ($values as $column => $value) {
            if (($schema[$column]['type'] ?? null) === 'text') {
                throw new LogicException('Full-text fields require REPLACE, not UPDATE.');
            }
        }

        return parent::update($values);
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        if (!$values) {
            return 0;
        }
        throw new LogicException('Manticore has no native atomic upsert for arbitrary unique keys. Use replace() with document IDs.');
    }

    public function replace(array $values): bool
    {
        if (!$values) {
            return true;
        }
        [$sql, $bindings] = $this->compileReplacement($values);

        return $this->connection->insert($sql, $bindings);
    }

    public function replaceGetId(array $values): int|string
    {
        if (!$values || array_is_list($values)) {
            throw new InvalidArgumentException('replaceGetId() requires a single associative row.');
        }
        [$sql, $bindings] = $this->compileReplacement($values);

        return $this->connection->insertAndGetId($sql, $bindings);
    }

    protected function compileReplacement(array $values): array
    {
        $values = $this->encodeWriteRows($values);
        if (!array_is_list($values)) {
            $values = [$values];
        }
        foreach ($values as &$row) {
            ksort($row);
        }
        unset($row);

        return [preg_replace('/^insert\b/', 'replace', $this->grammar->compileInsert($this, $values)),
            $this->cleanBindings(Arr::flatten($values, 1))];
    }

    public function search($columns = ['*']): SearchResult
    {
        $original = $this->columns;
        $this->columns ??= Arr::wrap($columns);
        try {
            $this->applyBeforeQueryCallbacks();
            $this->prepareReadCasts();
            $result = $this->connection->search($this->toSql(), $this->getBindings(), !$this->useWritePdo);
            $rows = $this->processor->processSelect($this, $result->rows()->all());
            $rows = $this->applyAfterQueryCallbacks(new Collection($rows));

            return new SearchResult($rows, $result->meta());
        } finally {
            $this->columns = $original;
        }
    }

    /** Current session metadata; call immediately after get() on this connection. */
    public function getMeta(): Meta
    {
        return $this->connection->getMeta();
    }

    public function withScore(string $alias = '_score'): static
    {
        return $this->addSearchExpression($alias, 'WEIGHT()', true);
    }

    public static function escapeMatch(string $text): string
    {
        $replacements = [];
        foreach (str_split('\\()|-!@~"&/^$=<>*?') as $character) {
            $replacements[$character] = '\\'.$character;
        }

        return strtr($text, $replacements);
    }

    public function whereMvaAny(string $column, $operator, $value = null): static
    {
        return $this->addMvaCondition('ANY', $column, $operator, $value, func_num_args() === 2, 'and');
    }

    public function orWhereMvaAny(string $column, $operator, $value = null): static
    {
        return $this->addMvaCondition('ANY', $column, $operator, $value, func_num_args() === 2, 'or');
    }

    public function whereMvaAll(string $column, $operator, $value = null): static
    {
        return $this->addMvaCondition('ALL', $column, $operator, $value, func_num_args() === 2, 'and');
    }

    public function orWhereMvaAll(string $column, $operator, $value = null): static
    {
        return $this->addMvaCondition('ALL', $column, $operator, $value, func_num_args() === 2, 'or');
    }

    protected function addMvaCondition(string $quantifier, string $column, $operator, $value, bool $short, string $boolean): static
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?$/D', $column)) {
            throw new InvalidArgumentException('An MVA filter requires an attribute name.');
        }
        if ($short) {
            $value = $operator;
            $operator = is_array($value) ? 'IN' : '=';
        }
        if (!is_string($operator)) {
            throw new InvalidArgumentException('An MVA operator must be a string.');
        }
        $operator = strtoupper(trim($operator));
        $set = in_array($operator, ['IN', 'NOT IN', 'BETWEEN', 'NOT BETWEEN'], true);
        if (!$set && !in_array($operator, ['=', '!=', '<>', '>', '>=', '<', '<='], true)) {
            throw new InvalidArgumentException('Unsupported MVA comparison operator.');
        }
        if ($set && (!is_array($value) || !$value)) {
            throw new InvalidArgumentException('MVA sets and ranges require a non-empty array.');
        }
        if (in_array($operator, ['BETWEEN', 'NOT BETWEEN'], true) && count($value) !== 2) {
            throw new InvalidArgumentException('An MVA range requires exactly two bounds.');
        }
        $values = $set ? array_values($value) : [$value];
        foreach ($values as &$item) {
            if (!is_int($item) && !(is_string($item) && preg_match('/^-?[0-9]+$/D', $item))) {
                throw new InvalidArgumentException('MVA comparisons require integer values.');
            }
            if (is_string($item)) {
                $normalized = ltrim(ltrim($item, '-'), '0') ?: '0';
                if ($item[0] === '-' && $normalized !== '0') {
                    $normalized = '-'.$normalized;
                }
                if ((string)(int)$item !== $normalized) {
                    throw new InvalidArgumentException('MVA values must fit the PHP integer range.');
                }
                // PDO must send an integer, not a quoted numeric string, to an MVA filter.
                $item = (int)$item;
            }
        }
        unset($item);
        $this->wheres[] = compact('quantifier', 'column', 'operator', 'values', 'boolean') + ['type' => 'Mva'];
        $this->addBinding($values, 'where');

        return $this;
    }

    public function withDistance(string $latitudeColumn, string $longitudeColumn, float $latitude, float $longitude, string $unit = 'm', string $alias = '_distance'): static
    {
        return $this->addSearchExpression($alias, $this->distanceExpression($latitudeColumn, $longitudeColumn, $latitude, $longitude, $unit), true);
    }

    public function whereGeoDistance(string $latitudeColumn, string $longitudeColumn, float $latitude, float $longitude, float $radius, string $unit = 'm'): static
    {
        if (!is_finite($radius) || $radius < 0) {
            throw new InvalidArgumentException('A geo radius must be finite and non-negative.');
        }
        $alias = $this->distanceAlias($this->distanceExpression($latitudeColumn, $longitudeColumn, $latitude, $longitude, $unit));

        return $this->where($alias, '<=', $radius);
    }

    public function orderByDistance(string $latitudeColumn, string $longitudeColumn, float $latitude, float $longitude, string $unit = 'm', string $direction = 'asc'): static
    {
        if (!in_array(strtolower($direction), ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Invalid distance ordering direction.');
        }
        $alias = $this->distanceAlias($this->distanceExpression($latitudeColumn, $longitudeColumn, $latitude, $longitude, $unit));

        return $this->orderBy($alias, $direction);
    }

    protected function distanceExpression(string $latitudeColumn, string $longitudeColumn, float $latitude, float $longitude, string $unit): string
    {
        if (!is_finite($latitude) || abs($latitude) > 90 || !is_finite($longitude) || abs($longitude) > 180) {
            throw new InvalidArgumentException('Geo coordinates must be finite degrees in the latitude/longitude ranges.');
        }
        if (!in_array($unit, ['m', 'km', 'mi'], true)) {
            throw new InvalidArgumentException('Distance units must be m, km or mi.');
        }
        $columns = [];
        foreach ([$latitudeColumn, $longitudeColumn] as $column) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)*$/D', $column)) {
                throw new InvalidArgumentException('Geo coordinates require attribute names or JSON paths.');
            }
            $columns[] = str_contains($column, '.') ? 'DOUBLE('.$column.')' : $this->grammar->wrap($column);
        }

        return 'GEODIST('.implode(', ', $columns).', '.json_encode($latitude, JSON_THROW_ON_ERROR)
            .', '.json_encode($longitude, JSON_THROW_ON_ERROR).', {in=deg, out='.$unit.'})';
    }

    protected function distanceAlias(string $sql): string
    {
        foreach ($this->searchExpressions as $alias => $expression) {
            if ($expression['sql'] === $sql) {
                return $alias;
            }
        }
        // The expression determines its alias so independent nested conditions cannot collide.
        $alias = '__manticore_distance_'.substr(hash('sha256', $sql), 0, 16);
        $this->searchExpressions[$alias] = ['sql' => $sql, 'visible' => false];

        return $alias;
    }

    protected function addSearchExpression(string $alias, string $sql, bool $visible): static
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias) || strtolower($alias) === 'id'
            || str_starts_with(strtolower($alias), '__manticore_')) {
            throw new InvalidArgumentException('Invalid or reserved search expression alias.');
        }
        if (isset($this->searchExpressions[$alias]) && $this->searchExpressions[$alias]['sql'] !== $sql) {
            throw new InvalidArgumentException('Search expression alias is already in use.');
        }
        $this->searchExpressions[$alias] = compact('sql', 'visible');

        return $this;
    }

    public function getSearchExpressions(): array
    {
        $expressions = $this->searchExpressions;
        foreach ($this->wheres ?? [] as $where) {
            if ($where['type'] === 'Nested' && $where['query'] instanceof self) {
                foreach ($where['query']->getSearchExpressions() as $alias => $expression) {
                    if (isset($expressions[$alias]) && $expressions[$alias]['sql'] !== $expression['sql']) {
                        throw new LogicException('Conflicting search aliases in nested queries.');
                    }
                    $expressions[$alias] = $expression;
                }
            }
        }

        return $expressions;
    }

    public function processReadRows(array $rows): array
    {
        foreach ($rows as &$row) {
            foreach ($this->readCasts as $column => $type) {
                if (is_object($row) && property_exists($row, $column)) {
                    $row->$column = AttributeCodec::decode($type, $row->$column);
                } elseif (is_array($row) && array_key_exists($column, $row)) {
                    $row[$column] = AttributeCodec::decode($type, $row[$column]);
                }
            }
        }
        unset($row);

        return $this->cleanSearchRows($rows);
    }

    public function cleanSearchRows(array $rows): array
    {
        foreach ($this->getSearchExpressions() as $alias => $expression) {
            if (!$expression['visible']) {
                foreach ($rows as &$row) {
                    if (is_object($row)) {
                        unset($row->$alias);
                    } elseif (is_array($row)) {
                        unset($row[$alias]);
                    }
                }
                unset($row);
            }
        }

        return $rows;
    }

    public function applyAfterQueryCallbacks($result)
    {
        if ($result instanceof Collection) {
            $result = new Collection($this->cleanSearchRows($result->all()));
        }

        return parent::applyAfterQueryCallbacks($result);
    }

    public function match(string $text): static
    {
        $this->wheres[] = ['type' => 'Match', 'value' => $text, 'boolean' => 'and'];
        $this->addBinding($text, 'where');

        return $this;
    }

    public function fieldWeights(array $weights): static
    {
        return $this->option('field_weights', $weights);
    }

    public function ranker(string $name, ?string $expression = null): static
    {
        return $this->option('ranker', new Ranker(strtolower($name), $expression));
    }

    public function option(string $name, int|float|string|array|Ranker|null $value = null): static
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) {
            throw new InvalidArgumentException('Invalid Manticore option name.');
        }
        $name = strtolower($name);
        if ($value === null) {
            unset($this->manticoreOptions[$name]);
        } else {
            SearchOptions::compile($name, $value, $this->grammar);
            $this->manticoreOptions[$name] = $value;
        }

        return $this;
    }

    public function exists()
    {
        // Manticore does not support Laravel's SELECT EXISTS (subquery).
        $query = clone $this;
        $query->limit(1);

        return $query->get()->isNotEmpty();
    }
}
