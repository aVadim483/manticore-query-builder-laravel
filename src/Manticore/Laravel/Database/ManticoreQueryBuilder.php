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
use Illuminate\Database\Query\Expression;

class ManticoreQueryBuilder extends Builder
{
    public function __call($method, $parameters)
    {
        if (!static::hasMacro($method) && QueryCapabilities::unsupportedDynamicMethod($method)) {
            throw new LogicException('Unsupported Laravel method for Manticore: '.$method);
        }

        return parent::__call($method, $parameters);
    }

    /** @var array<string, int|float|string|array|Ranker> */
    public array $manticoreOptions = [];

    /** @var array<string, array{sql: string, visible: bool, bindings?: array}> */
    protected array $searchExpressions = [];
    protected array $readCasts = [];
    protected array $searchFacets = [];
    protected array $facetCasts = [];
    // Laravel 11.0 predates after-query callbacks; keep one consistent driver contract.
    protected $afterQueryCallbacks = [];

    public function afterQuery(\Closure $callback)
    {
        $this->afterQueryCallbacks[] = $callback;
        return $this;
    }

    public function get($columns = ['*'])
    {
        $result = parent::get($columns);
        return method_exists(Builder::class, 'afterQuery') ? $result : $this->applyAfterQueryCallbacks($result);
    }

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
        $this->toSql(); // Validate before DESCRIBE can open a connection.
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
            $this->toSql();
            $this->prepareReadCasts();
            foreach ($this->connection->cursor($this->toSql(), $this->getBindings(), !$this->useWritePdo, $this->fetchUsing ?? []) as $row) {
                $row = $this->applyAfterQueryCallbacks(new Collection($this->processReadRows([$row])))->first();
                if ($row !== null) {
                    yield $row;
                }
            }
        });
    }

    public function cursorPaginate($perPage = 15, $columns = ['*'], $cursorName = 'cursor', $cursor = null)
    {
        if (!is_int($perPage) || $perPage < 1 || $perPage === PHP_INT_MAX) {
            throw new InvalidArgumentException('Cursor page size must be a positive integer below PHP_INT_MAX.');
        }
        $query = clone $this;
        $query->applyBeforeQueryCallbacks();
        $cursor = CursorPagination::prepare($query, $columns, $cursorName, $cursor);
        return $query->runCursorPagination($perPage, $columns, $cursorName, $cursor);
    }

    private function runCursorPagination($perPage, $columns, $cursorName, $cursor)
    {
        return parent::cursorPaginate($perPage, $columns, $cursorName, $cursor);
    }

    public function paginate($perPage = 15, $columns = ['*'], $pageName = 'page', $page = null, $total = null)
    {
        $query = clone $this;
        $query->applyBeforeQueryCallbacks();
        $query->assertPaginationCountSupported();
        if ($query->groups || $query->havings) {
            $query->prepareGroupedPagination();
            $total = value($total) ?? $query->groupedPaginationTotal($columns);
        }
        return $query->runLaravelPagination($perPage, $columns, $pageName, $page, $total);
    }

    private function runLaravelPagination($perPage, $columns, $pageName, $page, $total)
    {
        return parent::paginate($perPage, $columns, $pageName, $page, $total);
    }

    public function getCountForPagination($columns = ['*'])
    {
        $query = clone $this;
        $query->applyBeforeQueryCallbacks();
        $query->assertPaginationCountSupported();
        if ($query->groups || $query->havings) {
            $query->prepareGroupedPagination();
            return $query->groupedPaginationTotal($columns);
        }
        return $query->runLaravelPaginationCount($columns);
    }

    private function runLaravelPaginationCount($columns)
    {
        return parent::getCountForPagination($columns);
    }

    private function assertPaginationCountSupported(): void
    {
        if ($this->getFacets() || $this->hasKnn() || ($this->manticoreOptions['cutoff'] ?? 0) != 0) {
            throw new LogicException('Exact pagination counts do not support facets, KNN or cutoff. Use search() or simplePaginate().');
        }
    }

    private function prepareGroupedPagination(): void
    {
        $table = $this->plainTable();
        if (!$this->groups || $table === null || $this->aggregate || $this->joins || $this->unions || $this->distinct) {
            throw new LogicException('Grouped pagination requires scalar attributes of a single table.');
        }
        $schema = $this->connection->getTableSchema($table);
        foreach ($this->groups as $column) {
            if (!is_string($column) || !preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)$/D', $column, $parts)
                || !in_array($schema[$parts[1]]['type'] ?? null, ['int', 'integer', 'uint', 'uint32', 'uint64', 'bigint', 'float', 'double', 'bool', 'boolean', 'timestamp', 'string'], true)) {
                throw new LogicException('Grouped pagination supports scalar attributes, not MVA, JSON paths or raw grouping expressions.');
            }
        }
        // A scalar tuple creates at most one group per matching document. This bounds the
        // group heap without approximating COUNT(DISTINCT) or truncating the groups at 1000.
        $documents = $this->cloneWithout(['columns', 'orders', 'limit', 'offset', 'groups', 'havings'])
            ->cloneWithoutBindings(['select', 'order', 'groupBy', 'having'])->count();
        $this->option('max_matches', max(1, (int)$documents));
        $this->option('accurate_aggregation', 1);
    }

    private function groupedPaginationTotal($columns): int
    {
        $query = clone $this;
        $query->columns ??= Arr::wrap($columns);
        $query->offset = null;
        $query->limit(1);
        $result = $query->search();
        if ($result->meta()->totalFound() === null || $result->meta()['total_relation'] !== 'eq') {
            throw new LogicException('Manticore did not return an exact group count for pagination.');
        }
        return $result->meta()->totalFound();
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

    private function encodeInsertRows(array $values): array
    {
        $values = $this->encodeWriteRows($values);
        if (!$values) {
            return [];
        }
        $multiple = array_is_list($values) && is_array($values[0]);
        $rows = [];
        foreach ($multiple ? $values : [$values] as $row) {
            $encoded = [];
            foreach ($row as $column => $value) {
                if (is_float($value)) {
                    if (!is_finite($value)) {
                        throw new InvalidArgumentException('Manticore parameters must be finite numbers.');
                    }
                    // Laravel renders Expressions and removes them from bindings. Do this only
                    // after schema encoding, so JSON numbers remain JSON-bound strings.
                    $value = new Expression(json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
                }
                $encoded[$column] = $value;
            }
            $rows[] = $encoded;
        }

        return $multiple ? $rows : $rows[0];
    }

    public function insert(array $values)
    {
        if (!$values) {
            return true;
        }
        $this->applyBeforeQueryCallbacks();
        QueryCapabilities::write($this, true);
        QueryCapabilities::payload($values);
        return parent::insert($this->encodeInsertRows($values));
    }

    public function insertGetId(array $values, $sequence = null)
    {
        QueryCapabilities::payload($values, true);
        if ($sequence !== null && $sequence !== 'id') {
            throw new InvalidArgumentException('Manticore only supports the document id.');
        }
        $this->applyBeforeQueryCallbacks();
        QueryCapabilities::write($this, true);
        return parent::insertGetId($this->encodeInsertRows($values), $sequence);
    }

    public function update(array $values)
    {
        QueryCapabilities::payload($values, true);
        if (array_key_exists('id', $values)) {
            throw new LogicException('Manticore document IDs cannot be updated.');
        }
        $this->applyBeforeQueryCallbacks();
        if ($normalized = FullTextConditions::normalize($this)) {
            return $normalized->update($values);
        }
        $this->grammar->compileUpdate($this, $values);
        $values = $this->encodeWriteRows($values);
        $schema = $this->plainTable() === null || $this->connection->pretending()
            ? [] : $this->connection->getTableSchema($this->plainTable());
        foreach ($values as $column => $value) {
            if (($schema[$column]['type'] ?? null) === 'text') {
                throw new LogicException('Full-text fields require REPLACE, not UPDATE.');
            }
            if (preg_match('/\bcolumnar\b/i', $schema[$column]['properties'] ?? '')) {
                throw new LogicException('Columnar attributes require REPLACE, not UPDATE.');
            }
        }

        return parent::update($values);
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        if (!$values) {
            return 0;
        }
        return (new DocumentWrites($this))->upsert($values, $uniqueBy, $update);
    }

    public function updateOrInsert(array $attributes, array|callable $values = [])
    {
        return (new DocumentWrites($this))->updateOrInsert($attributes, $values, false);
    }

    public function replaceOrInsert(array $attributes, array|callable $values = []): bool
    {
        return (new DocumentWrites($this))->updateOrInsert($attributes, $values, true);
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
        $this->applyBeforeQueryCallbacks();
        QueryCapabilities::write($this, true);
        QueryCapabilities::payload($values);
        $values = $this->encodeInsertRows($values);
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
        if ($this->fetchUsing ?? []) {
            throw new LogicException('search() requires the default object fetch mode. Use get() or cursor() with fetchUsing().');
        }
        $original = $this->columns;
        $this->columns ??= Arr::wrap($columns);
        try {
            $this->applyBeforeQueryCallbacks();
            if ($this->fetchUsing ?? []) {
                throw new LogicException('search() requires the default object fetch mode.');
            }
            $this->toSql();
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

    public function hybridSearch(string $text, string $column, array $vector, array $options = []): HybridResult
    {
        return (new HybridSearch($this, $text, $column, $vector, $options))->search();
    }

    /** @return Collection<int, \stdClass> Documents in fused ranking order. */
    public function hybridGet(string $text, string $column, array $vector, array $options = []): Collection
    {
        return $this->hybridSearch($text, $column, $vector, $options)->rows();
    }

    /** Rank complete candidate windows, then return the best document or null. */
    public function hybridFirst(string $text, string $column, array $vector, array $options = []): ?\stdClass
    {
        return $this->hybridGet($text, $column, $vector, $options)->first();
    }

    public function hasQueryCallbacks(): bool
    {
        return (bool)($this->beforeQueryCallbacks || $this->afterQueryCallbacks);
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

        foreach ($this->afterQueryCallbacks as $callback) {
            $result = $callback($result) ?? $result;
        }
        return $result;
    }

    public function delete($id = null)
    {
        if ($id !== null) {
            $this->where($this->from.'.id', '=', $id);
        }
        $this->applyBeforeQueryCallbacks();
        if ($normalized = FullTextConditions::normalize($this)) {
            return $normalized->delete();
        }

        return parent::delete();
    }

    public function getBindings()
    {
        return Arr::flatten($this->getRawBindings());
    }

    public function getRawBindings()
    {
        $normalized = FullTextConditions::normalize($this);

        if ($normalized) {
            return $normalized->getRawBindings();
        }
        $bindings = $this->bindings;
        foreach ($this->getSearchExpressions() as $expression) {
            array_push($bindings['select'], ...($expression['bindings'] ?? []));
        }

        return $bindings;
    }

    public function addNestedWhereQuery($query, $boolean = 'and')
    {
        if ($query->wheres) {
            $this->wheres[] = ['type' => 'Nested', 'query' => $query, 'boolean' => $boolean];
            // Synthetic MATCH/JSON bindings belong to the final query, not the nested fragment.
            $this->addBinding($query->bindings['where'], 'where');
        }

        return $this;
    }

    public function mergeBindings(Builder $query)
    {
        if ($query instanceof self && $query->getRawBindings() !== $query->bindings) {
            throw new LogicException('Manual mergeBindings() of deferred search bindings is not supported. Use nested query conditions.');
        }

        return parent::mergeBindings($query);
    }

    public function mergeWheres($wheres, $bindings)
    {
        $check = function (array $conditions) use (&$check): void {
            foreach ($conditions as $where) {
                if ($where['type'] === 'Fulltext' || (isset($where['column']) && is_string($where['column'])
                    && str_starts_with($where['column'], '__manticore_'))) {
                    throw new LogicException('Manual mergeWheres() of search predicates is not supported. Use nested query conditions.');
                }
                if ($where['type'] === 'Nested') {
                    $check($where['query']->wheres ?? []);
                }
            }
        };
        $check((array) $wheres);
        return parent::mergeWheres($wheres, $bindings);
    }

    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (($this->isQueryable($column) && $operator !== null) || $this->isQueryable($operator) || $this->isQueryable($value)) {
            throw new LogicException('Scalar subqueries are not supported by the Manticore driver.');
        }

        return parent::where(...func_get_args());
    }

    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        if ($this->isQueryable($values)) {
            throw new LogicException('IN subqueries are not supported by the Manticore driver.');
        }

        return parent::whereIn($column, $values, $boolean, $not);
    }

    public function selectSub($query, $as)
    {
        throw new LogicException('selectSub() is not supported by the Manticore driver.');
    }

    public function fromSub($query, $as)
    {
        throw new LogicException('fromSub() is not supported. Native sub-selects have a different syntax; use explicit SQL.');
    }

    public function inOrderOf($column, $values, $filter = true)
    {
        throw new LogicException('inOrderOf() is not supported. Order by a native SELECT expression alias.');
    }

    public function incrementEach(array $columns, array $extra = [])
    {
        throw new LogicException('Manticore UPDATE does not support increment/decrement expressions.');
    }

    public function decrementEach(array $columns, array $extra = [])
    {
        throw new LogicException('Manticore UPDATE does not support increment/decrement expressions.');
    }

    public function explain()
    {
        throw new LogicException('Laravel EXPLAIN SELECT is not supported. Use native Manticore profiling commands.');
    }

    public function timeout(?int $seconds): static
    {
        if ($seconds !== null) {
            throw new LogicException('timeout() is not supported. Use option(\'max_query_time\', $milliseconds) for the server search limit.');
        }
        if (property_exists($this, 'timeout')) {
            $this->timeout = null;
        }
        return $this;
    }

    protected function wherePastOrFuture($columns, $operator, $boolean)
    {
        throw new LogicException('Laravel relative-date filters are not supported. Compare explicit Unix timestamps.');
    }

    protected function ensureConnectionSupportsVectors()
    {
        throw new LogicException('Laravel vector-distance methods are not supported. Use knn() or hybridSearch().');
    }

    public function whereVectorSimilarTo($column, $vector, $minSimilarity = 0.6, $order = true)
    {
        // Reject before Laravel can request an embedding for a string argument.
        $this->ensureConnectionSupportsVectors();
    }

    public function whereJsonContains($column, $value, $boolean = 'and', $not = false)
    {
        return $this->whereJsonMembership($column, $value, $boolean, $not, false);
    }

    public function whereJsonOverlaps($column, $value, $boolean = 'and', $not = false)
    {
        return $this->whereJsonMembership($column, $value, $boolean, $not, true);
    }

    public function orWhereJsonOverlaps($column, $value)
    {
        return $this->whereJsonOverlaps($column, $value, 'or');
    }

    public function whereJsonDoesntOverlap($column, $value, $boolean = 'and')
    {
        return $this->whereJsonOverlaps($column, $value, $boolean, true);
    }

    public function orWhereJsonDoesntOverlap($column, $value)
    {
        return $this->whereJsonDoesntOverlap($column, $value, 'or');
    }

    private function whereJsonMembership($column, $value, $boolean, $not, bool $overlap): static
    {
        $path = JsonPath::compile($column, $this->grammar);
        $this->assertJsonBoolean($boolean, $not);
        $values = is_array($value) ? $value : [$value];
        if (!array_is_list($values) || (!$values && !$overlap)) {
            throw new InvalidArgumentException('JSON contains requires a scalar or a non-empty list; JSON objects and nested candidates are not supported.');
        }
        foreach ($values as $item) {
            if ((!is_string($item) && !is_bool($item) && !is_int($item) && !is_float($item))
                || (is_float($item) && !is_finite($item))) {
                throw new InvalidArgumentException('JSON contains/overlaps candidates must be strings, booleans or finite numbers; null, objects and nested arrays are not supported.');
            }
        }
        $terms = array_fill(0, count($values), 'ANY(__json_value = ? FOR __json_value IN '.$path.')');
        $sql = $terms ? '('.implode($overlap ? ' OR ' : ' AND ', $terms).')' : '0';
        $alias = $this->jsonExpressionAlias($sql, $values);

        return $this->where($alias, '=', $not ? 0 : 1, $boolean);
    }

    public function whereJsonContainsKey($column, $boolean = 'and', $not = false)
    {
        JsonPath::compile($column, $this->grammar);
        $this->assertJsonBoolean($boolean, $not);

        return $this->whereNull($column, $boolean, !$not);
    }

    public function whereJsonLength($column, $operator, $value = null, $boolean = 'and')
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }
        $path = JsonPath::compile($column, $this->grammar);
        $this->assertJsonBoolean($boolean, false);
        if (!in_array($operator, ['=', '!=', '<>', '<', '<=', '>', '>='], true)
            || !is_int($value) || $value < 0) {
            throw new InvalidArgumentException('whereJsonLength() requires a comparison operator and a non-negative integer length.');
        }
        $alias = $this->jsonExpressionAlias('LENGTH('.$path.')');

        return $this->whereNested(function ($query) use ($column, $alias, $operator, $value) {
            $query->whereNotNull($column)->where($alias, $operator, $value);
        }, $boolean);
    }

    private function jsonExpressionAlias(string $sql, array $bindings = []): string
    {
        $alias = '__manticore_json_'.substr(hash('sha256', $sql.serialize($bindings)), 0, 16);
        $this->searchExpressions[$alias] = ['sql' => $sql, 'visible' => false, 'bindings' => $bindings];

        return $alias;
    }

    private function assertJsonBoolean(mixed $boolean, mixed $not): void
    {
        if (!is_string($boolean) || !in_array(strtolower($boolean), ['and', 'or'], true) || !is_bool($not)) {
            throw new InvalidArgumentException('JSON filters require an AND/OR boolean and a boolean negation flag.');
        }
    }

    /** @return $this */
    public function whereFullText($columns, $value, array $options = [], $boolean = 'and')
    {
        if (!is_string($columns) && !is_array($columns)) {
            throw new InvalidArgumentException('whereFullText() requires a field name or a list of field names.');
        }
        $columns = (array) $columns;
        if (!$columns || !array_is_list($columns)) {
            throw new InvalidArgumentException('whereFullText() requires a field name or a non-empty list of field names.');
        }
        foreach ($columns as $column) {
            if (!is_string($column) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $column)) {
                throw new InvalidArgumentException('Invalid whereFullText() field name.');
            }
        }
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('whereFullText() requires non-empty search text.');
        }
        $unknown = array_diff(array_keys($options), ['escape']);
        if ($unknown) {
            throw new InvalidArgumentException('Unsupported whereFullText() option(s): '.implode(', ', $unknown).'.');
        }
        if (array_key_exists('escape', $options) && !is_bool($options['escape'])) {
            throw new InvalidArgumentException('whereFullText() option escape must be boolean.');
        }
        if (!is_string($boolean) || !in_array(strtolower($boolean), ['and', 'or'], true)) {
            throw new InvalidArgumentException('whereFullText() boolean must be and or or.');
        }
        $fields = count($columns) === 1 ? $columns[0] : '('.implode(',', $columns).')';
        $text = ($options['escape'] ?? true) ? self::escapeMatch($value) : $value;
        // The combined MATCH binding is generated at compilation, so nested builders
        // contribute only their attribute bindings to Laravel's raw where bindings.
        $this->wheres[] = [
            'type' => 'Fulltext', 'expression' => '@'.$fields.' ('.$text.')',
            'boolean' => strtolower($boolean),
        ];

        return $this;
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
