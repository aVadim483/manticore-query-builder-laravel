<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use LogicException;

class ManticoreGrammar extends Grammar
{
    public function compileWheres(Builder $query)
    {
        if ($query instanceof ManticoreQueryBuilder) {
            $query = FullTextConditions::normalize($query) ?? $query;
        }

        return parent::compileWheres($query);
    }

    public function compileSelect(Builder $query)
    {
        QueryCapabilities::select($query);
        if (count($query->havings ?? []) > 1) {
            throw new LogicException('Manticore supports a single HAVING condition per query.');
        }
        if ($query instanceof ManticoreQueryBuilder && $query->hasKnn()) {
            $this->assertKnnConditions($query);
        }
        if ($query->unions || $query->joins || $query->lock !== null
            || isset($query->groupLimit) || $query->distinct) {
            throw new LogicException('Unions, joins, locks, group limits and DISTINCT are not supported by this Manticore driver yet.');
        }
        if ($query->aggregate && $query->havings) {
            throw new LogicException('Aggregates with HAVING require a Manticore-specific implementation.');
        }
        if ($query instanceof ManticoreQueryBuilder && $query->getFacets() && ($query->aggregate || $query->groups || $query->havings)) {
            throw new LogicException('Facets cannot be combined with main-query aggregates, grouping or HAVING.');
        }

        $sql = parent::compileSelect($query);
        if ($query instanceof ManticoreQueryBuilder) {
            $searchOptions = $query->manticoreOptions;
            $window = ($query->offset ?? 0) + ($query->limit ?? 0);
            if ($query->limit !== null && $window > 1000 && !array_key_exists('max_matches', $searchOptions)) {
                if (!is_int($window)) {
                    throw new LogicException('The Manticore result window exceeds the PHP integer range.');
                }
                $searchOptions['max_matches'] = $window;
            }
            $options = [];
            foreach ($searchOptions as $name => $value) {
                $options[] = SearchOptions::compile($name, $value, $this);
            }
            if ($options) {
                $sql .= ' option '.implode(', ', $options);
            }
            foreach ($query->getFacets() as $facet) {
                $column = str_contains($facet['column'], '.') ? $facet['column'] : $this->wrap($facet['column']);
                $sql .= ' facet '.$column;
                if ($facet['column'] !== $facet['alias']) {
                    $sql .= ' as '.$this->wrap($facet['alias']);
                }
                $sql .= ' order by '.($facet['sort'] === 'count' ? 'count(*)' : $this->wrap($facet['alias']))
                    .' '.$facet['direction'].' limit '.$facet['limit'];
            }
        }

        return $sql;
    }

    protected function wrapValue($value)
    {
        return $value === '*' ? $value : '`'.str_replace('`', '``', $value).'`';
    }

    protected function wrapJsonSelector($value)
    {
        return JsonPath::compile($value, $this);
    }

    protected function whereJsonBoolean(Builder $query, $where)
    {
        $column = $this->wrap($where['column']);
        $value = $this->getValue($where['value']) === 'true' ? '1' : '0';

        return '('.$column.' IS NOT NULL and '.$column.' '.$where['operator'].' '.$value.')';
    }

    protected function whereMatch(Builder $query, $where)
    {
        return 'MATCH('.$this->parameter($where['value']).')';
    }

    protected function whereNull(Builder $query, $where)
    {
        return $this->isDocumentId($query, $where['column']) ? '0 = 1' : parent::whereNull($query, $where);
    }

    protected function whereNotNull(Builder $query, $where)
    {
        return $this->isDocumentId($query, $where['column']) ? '1 = 1' : parent::whereNotNull($query, $where);
    }

    private function isDocumentId(Builder $query, mixed $column): bool
    {
        if (!is_string($column)) {
            return false;
        }
        if (strtolower($column) === 'id') {
            return true;
        }
        if (is_string($query->from) && preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)(?:\s+(?:as\s+)?([a-zA-Z_][a-zA-Z0-9_]*))?$/iD', $query->from, $parts)) {
            return in_array(strtolower($column), [strtolower($parts[1]).'.id', strtolower($parts[2] ?? $parts[1]).'.id'], true);
        }
        return false;
    }

    protected function whereMva(Builder $query, $where)
    {
        $left = $where['quantifier'].'('.$this->wrap($where['column']).') '.$where['operator'].' ';
        if (str_contains($where['operator'], 'BETWEEN')) {
            return $left.'? AND ?';
        }
        if (str_contains($where['operator'], 'IN')) {
            return $left.'('.$this->parameterize($where['values']).')';
        }

        return $left.'?';
    }

    protected function assertKnnConditions(Builder $query, bool $nested = false): void
    {
        $count = 0;
        foreach ($query->wheres ?? [] as $where) {
            if (strtolower($where['boolean']) !== 'and') {
                throw new LogicException('KNN queries require AND filters; OR trees are not supported.');
            }
            if ($where['type'] === 'Knn' && ($nested || ++$count > 1)) {
                throw new LogicException('KNN must appear once at the top level.');
            }
            if ($where['type'] === 'Nested') {
                $this->assertKnnConditions($where['query'], true);
            }
        }
    }

    protected function whereKnn(Builder $query, $where): string
    {
        $vector = implode(', ', array_map(fn ($value) => json_encode($value, JSON_THROW_ON_ERROR), $where['vector']));
        $options = [];
        foreach ($where['options'] as $name => $value) {
            // Manticore's KNN parser requires oversampling to be a float token, even for 2.0.
            $options[] = $name.'='.json_encode($name === 'oversampling' ? (float)$value : $value,
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        }
        return 'KNN('.$this->wrap($where['column']).', '.$where['k'].', ('.$vector.')'
            .($options ? ', {'.implode(', ', $options).'}' : '').')';
    }

    protected function compileColumns(Builder $query, $columns)
    {
        $sql = parent::compileColumns($query, $columns);

        return $sql === null ? null : $this->appendSearchExpressions($query, $sql);
    }

    protected function compileAggregate(Builder $query, $aggregate)
    {
        return $this->appendSearchExpressions($query, parent::compileAggregate($query, $aggregate));
    }

    protected function appendSearchExpressions(Builder $query, string $sql): string
    {
        if ($query instanceof ManticoreQueryBuilder) {
            foreach ($query->getSearchExpressions() as $alias => $expression) {
                $sql .= ', '.$expression['sql'].' as '.$this->wrap($alias);
            }
        }

        return $sql;
    }

    protected function compileLimit(Builder $query, $limit)
    {
        return 'limit '.($query->offset ? (int)$query->offset.', ' : '').(int)$limit;
    }

    protected function compileOffset(Builder $query, $offset)
    {
        if ($offset && $query->limit === null) {
            throw new LogicException('Manticore requires a limit when an offset is specified.');
        }

        return '';
    }

    protected function whereExists(Builder $query, $where)
    {
        throw new LogicException('Manticore does not support WHERE EXISTS subqueries.');
    }

    protected function whereNotExists(Builder $query, $where)
    {
        throw new LogicException('Manticore does not support WHERE NOT EXISTS subqueries.');
    }

    public function compileUpdate(Builder $query, array $values)
    {
        $this->assertPlainWrite($query);

        return parent::compileUpdate($query, $values);
    }

    public function compileDelete(Builder $query)
    {
        $this->assertPlainWrite($query);

        return parent::compileDelete($query);
    }

    protected function assertPlainWrite(Builder $query): void
    {
        QueryCapabilities::write($query);
    }

    public function compileInsert(Builder $query, array $values)
    {
        QueryCapabilities::write($query, true);

        return parent::compileInsert($query, $values);
    }

    public function compileInsertOrIgnore(Builder $query, array $values)
    {
        throw new LogicException('insertOrIgnore() is not supported by the Manticore driver.');
    }

    public function compileInsertOrIgnoreReturning(Builder $query, array $values, array $returning, ?array $uniqueBy)
    {
        throw new LogicException('insertOrIgnoreReturning() is not supported by the Manticore driver.');
    }

    public function compileInsertUsing(Builder $query, array $columns, string $sql)
    {
        throw new LogicException('insertUsing() is not supported by the Manticore driver.');
    }

    public function compileInsertOrIgnoreUsing(Builder $query, array $columns, string $sql)
    {
        throw new LogicException('insertOrIgnoreUsing() is not supported by the Manticore driver.');
    }

    public function compileTruncate(Builder $query)
    {
        QueryCapabilities::write($query, true);

        return parent::compileTruncate($query);
    }

    public function compileRandom($seed)
    {
        throw new LogicException('inRandomOrder() is not supported. Use orderByRaw(\'RAND()\') and option(\'rand_seed\', $seed).');
    }

    protected function dateBasedWhere($type, Builder $query, $where)
    {
        throw new LogicException('Laravel date filters are not supported. Compare Unix timestamps or use native SELECT expressions.');
    }

    public function supportsSavepoints()
    {
        return false;
    }
}
