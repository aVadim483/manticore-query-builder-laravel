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
    protected array $searchFacets = [];
    protected array $facetCasts = [];

    protected function plainTable(): ?string
    {
        return is_string($this->from) && preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)(?:\s+(?:as\s+)?[a-zA-Z_][a-zA-Z0-9_]*)?$/iD', $this->from, $match)
            ? $match[1] : null;
    }

    protected function prepareReadCasts(): void
    {
        $this->readCasts = [];
        $this->facetCasts = [];
        if ($this->connection->getConfig('cast_attributes') === false || $this->connection->pretending() || $this->plainTable() === null) {
            return;
        }
        $schema = $this->connection->getTableSchema($this->plainTable());
        foreach ($this->searchFacets as $name => $facet) {
            $type = $schema[$facet['column']]['type'] ?? null;
            $this->facetCasts[$name] = in_array($type, ['multi', 'multi64', 'mva', 'mva64'], true) ? 'bigint' : $type;
        }
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
        if ($this->searchFacets) {
            throw new LogicException('Queries with facets must be executed through search().');
        }
        $this->prepareReadCasts();

        return parent::runSelect();
    }

    public function cursor()
    {
        if ($this->searchFacets) {
            throw new LogicException('Queries with facets must be executed through search().');
        }
        return new LazyCollection(function () {
            $this->applyBeforeQueryCallbacks();
            if ($this->searchFacets) {
                throw new LogicException('Queries with facets must be executed through search().');
            }
            $this->prepareReadCasts();
            foreach ($this->connection->cursor($this->toSql(), $this->getBindings(), !$this->useWritePdo, $this->fetchUsing ?? []) as $row) {
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
            $result = $this->connection->search($this->toSql(), $this->getBindings(), !$this->useWritePdo, array_keys($this->searchFacets));
            $rows = $this->processor->processSelect($this, $result->rows()->all());
            $rows = $this->applyAfterQueryCallbacks(new Collection($rows));

            $facets = $result->facets();
            foreach ($facets as $name => $buckets) {
                foreach ($buckets as $bucket) {
                    if (isset($this->facetCasts[$name]) && property_exists($bucket, $name)) {
                        $bucket->$name = AttributeCodec::decode($this->facetCasts[$name], $bucket->$name);
                    }
                    if (property_exists($bucket, 'count(*)')) {
                        $bucket->{'count(*)'} = (int)$bucket->{'count(*)'};
                    }
                }
            }
            return new SearchResult($rows, $result->meta(), $facets);
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

    public function facet(string $column, ?string $alias = null, int $limit = 20, string $sort = 'count', string $direction = 'desc'): static
    {
        $alias ??= str_replace('.', '_', $column);
        $direction = strtolower($direction);
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)*$/D', $column)
            || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $alias) || $limit < 1
            || !in_array($sort, ['count', 'value'], true) || !in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('A facet requires an attribute/JSON path, alias, positive limit and valid ordering.');
        }
        if (isset($this->searchFacets[$alias])) {
            throw new InvalidArgumentException('A facet alias must be unique.');
        }
        $this->searchFacets[$alias] = compact('column', 'alias', 'limit', 'sort', 'direction');
        return $this;
    }

    public function getFacets(): array
    {
        return $this->searchFacets;
    }

    public function withHighlight(string|array $fields = [], array $options = [], ?string $query = null, string $alias = '_highlight'): static
    {
        $fields = is_string($fields) ? ($fields === '' ? [] : explode(',', $fields)) : $fields;
        if (!array_is_list($fields)) {
            throw new InvalidArgumentException('Highlight fields must be a list.');
        }
        foreach ($fields as $field) {
            if (!is_string($field) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $field)) {
                throw new InvalidArgumentException('Highlight fields require column names.');
            }
        }
        $settings = [];
        foreach ($options as $name => $value) {
            if (!is_string($name) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)
                || !is_scalar($value) || (is_float($value) && !is_finite($value))) {
                throw new InvalidArgumentException('Highlight options require valid names and finite scalar values.');
            }
            $settings[] = $name.'='.$this->grammar->escape($value);
        }
        $sql = 'HIGHLIGHT({'.implode(', ', $settings).'}, '.$this->grammar->escape(implode(',', $fields))
            .($query === null ? '' : ', '.$this->grammar->escape($query)).')';
        return $this->addSearchExpression($alias, $sql, true);
    }

    public function knn(string $column, array $vector, int $k = 10, array $options = []): static
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $column) || $k <= 0
            || !array_is_list($vector) || !$vector) {
            throw new InvalidArgumentException('KNN requires an attribute, positive k and non-empty vector list.');
        }
        foreach ($vector as $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) {
                throw new InvalidArgumentException('KNN vectors require finite numbers.');
            }
        }
        foreach ($options as $name => &$value) {
            if (in_array($name, ['rescore', 'early_termination', 'prefilter'], true) && is_bool($value)) {
                $value = (int)$value;
            }
            $valid = match ($name) {
                'ef' => is_int($value) && $value > 0,
                'oversampling' => (is_int($value) || is_float($value)) && is_finite((float)$value) && $value >= 1,
                'rescore', 'early_termination', 'prefilter' => $value === 0 || $value === 1,
                default => false,
            };
            if (!$valid) {
                throw new InvalidArgumentException('Invalid or unsupported KNN option: '.$name);
            }
        }
        unset($value);
        if ($this->hasKnn()) {
            throw new LogicException('Only one KNN condition is supported.');
        }
        $this->wheres[] = compact('column', 'vector', 'k', 'options') + ['type' => 'Knn', 'boolean' => 'and'];
        $this->limit($k);

        return $this;
    }

    public function hasKnn(): bool
    {
        foreach ($this->wheres ?? [] as $where) {
            if ($where['type'] === 'Knn' || ($where['type'] === 'Nested' && $where['query'] instanceof self && $where['query']->hasKnn())) {
                return true;
            }
        }
        return false;
    }

    public function withKnnDistance(string $alias = '_knn_distance'): static
    {
        return $this->addSearchExpression($alias, 'KNN_DIST()', true);
    }

    public function orderByKnnDistance(string $direction = 'asc'): static
    {
        $alias = '__manticore_knn_distance';
        $this->searchExpressions[$alias] = ['sql' => 'KNN_DIST()', 'visible' => false];

        return $this->orderBy($alias, $direction);
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
