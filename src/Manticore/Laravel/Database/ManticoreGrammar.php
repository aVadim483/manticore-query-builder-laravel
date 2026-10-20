<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use LogicException;

class ManticoreGrammar extends Grammar
{
    public function compileSelect(Builder $query)
    {
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
        }

        return $sql;
    }

    protected function wrapValue($value)
    {
        return $value === '*' ? $value : '`'.str_replace('`', '``', $value).'`';
    }

    protected function whereMatch(Builder $query, $where)
    {
        return 'MATCH('.$this->parameter($where['value']).')';
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
            $options[] = $name.'='.json_encode($value, JSON_THROW_ON_ERROR);
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
        if ($query->joins || $query->orders || $query->limit !== null || $query->offset !== null
            || ($query instanceof ManticoreQueryBuilder && ($query->manticoreOptions || $query->getSearchExpressions() || $query->hasKnn()))) {
            throw new LogicException('Manticore UPDATE and DELETE do not support joins, ordering, limits, offsets, search options or computed search expressions in this driver.');
        }
    }

    public function supportsSavepoints()
    {
        return false;
    }
}
