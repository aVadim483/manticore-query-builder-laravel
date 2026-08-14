<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel;

use avadim\Manticore\QueryBuilder\Query as BaseQuery;
use Illuminate\Support\Collection;

/**
 * The query builder of the package, answering the way Laravel does.
 *
 * The standalone builder returns plain arrays on purpose - a collection there would mean
 * dragging illuminate/* into a framework-agnostic library. Here the framework is a given,
 * so reads answer with a Collection of Row objects.
 *
 * Everything else - where(), match(), orderBy(), the writes, the schema statements - is
 * inherited untouched.
 *
 * @package avadim\Manticore\Laravel
 */
class Query extends BaseQuery
{
    /**
     * The rows of the query as a Collection, keyed from zero
     *
     * @param string|array|null $columns
     * @param string|array ...$more
     *
     * @return \Illuminate\Support\Collection
     */
    public function get($columns = '*', ...$more): Collection
    {
        $rows = func_num_args() ? parent::get($columns, ...$more) : parent::get();

        return $this->newCollection($rows);
    }

    /**
     * The first row of the query, or null when there is none
     *
     * @return Row|null
     */
    public function first(): ?Row
    {
        $row = parent::first();

        return null === $row ? null : $this->newRow((array)$row);
    }

    /**
     * The row with the given id, or null when there is none
     *
     * @param int $id
     *
     * @return Row|null
     */
    public function find(int $id): ?Row
    {
        return parent::find($id);
    }

    /**
     * The values of one column as a Collection, optionally keyed by another one
     *
     * @param string $column
     * @param string|null $key
     *
     * @return \Illuminate\Support\Collection
     */
    public function pluck(string $column, ?string $key = null): Collection
    {
        return new Collection(parent::pluck($column, $key));
    }

    /**
     * @param mixed $rows
     *
     * @return \Illuminate\Support\Collection
     */
    protected function newCollection($rows): Collection
    {
        $rows = is_array($rows) ? array_values($rows) : [];

        return new Collection(array_map([$this, 'newRow'], $rows));
    }

    /**
     * @param array $attributes
     *
     * @return Row
     */
    protected function newRow(array $attributes): Row
    {
        return new Row($attributes);
    }
}
