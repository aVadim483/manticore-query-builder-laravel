<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel;

use avadim\Manticore\QueryBuilder\Query as BaseQuery;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
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
     * Run the callback over the result page by page
     *
     * @param int $count rows per page
     * @param callable $callback receives a Collection of Row and the page number; returning
     *                           false stops the walk
     *
     * @return bool false when the walk was stopped by the callback
     */
    public function chunk(int $count, callable $callback): bool
    {
        return parent::chunk($count, function (array $rows, int $page) use ($callback) {
            return $callback($this->newCollection($rows), $page);
        });
    }

    /**
     * Run the callback over the result page by page, walking by the id column
     *
     * @param int $count rows per page
     * @param callable $callback receives a Collection of Row and the page number; returning
     *                           false stops the walk
     * @param string|null $column the column to walk by, the document id by default
     *
     * @return bool false when the walk was stopped by the callback
     */
    public function chunkById(int $count, callable $callback, ?string $column = 'id'): bool
    {
        return parent::chunkById($count, function (array $rows, int $page) use ($callback) {
            return $callback($this->newCollection($rows), $page);
        }, $column);
    }

    /**
     * Run the callback over every row of the result.
     *
     * The walk of the query builder is written out again rather than inherited: it hands its
     * pages to a callback typed array, and the pages of chunk() are a Collection here.
     *
     * @param callable $callback receives the Row and its number; returning false stops the walk
     * @param int|null $count rows per page
     *
     * @return bool false when the walk was stopped by the callback
     */
    public function each(callable $callback, ?int $count = 1000): bool
    {
        $number = 0;

        return $this->chunk((int)$count, static function (Collection $rows) use ($callback, &$number) {
            foreach ($rows as $row) {
                if ($callback($row, $number++) === false) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * The rows of the result as Row objects, one by one, fetched page by page as they are asked for.
     *
     * The generator is numbered from zero, the way the Collection of get() is: the keys of the
     * query builder are document ids, and a walk without an order can meet the same id on two
     * pages - which would cost a row when the generator is read into an array.
     *
     * @param int|null $count rows per page
     *
     * @return \Generator
     */
    public function lazy(?int $count = 1000): \Generator
    {
        $number = 0;

        foreach (parent::lazy($count) as $row) {
            yield $number++ => $this->newRow((array)$row);
        }
    }

    /**
     * Alias of lazy()
     *
     * @param int|null $count
     *
     * @return \Generator
     */
    public function cursor(?int $count = 1000): \Generator
    {
        return $this->lazy($count);
    }

    /**
     * The only row matching the query
     *
     * @return Row
     * @throws \RuntimeException when nothing matched, or more than one row did
     */
    public function sole(): Row
    {
        return $this->newRow((array)parent::sole());
    }

    /**
     * The given page of the result, with the total number of rows alongside it
     *
     * @param int|null $perPage
     * @param string|array $columns
     * @param string $pageName
     * @param int|null $page current page, taken from the request when not given
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function paginate($perPage = 15, $columns = '*', string $pageName = 'page', ?int $page = null): LengthAwarePaginator
    {
        $perPage = max(1, (int)$perPage);
        $page = $page ?: max(1, (int)Paginator::resolveCurrentPage($pageName));
        // count() rewrites the column list, so it goes to a copy
        $total = $this->clone()->count();
        $items = $total > 0 ? $this->forPage($page, $perPage)->get($columns) : new Collection();

        return new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * The given page of the result, without counting the rest.
     *
     * One row more than asked for is selected, which is enough to tell whether there is a next
     * page - and cheaper than the COUNT(*) of paginate().
     *
     * @param int|null $perPage
     * @param string|array $columns
     * @param string $pageName
     * @param int|null $page
     *
     * @return \Illuminate\Pagination\Paginator
     */
    public function simplePaginate($perPage = 15, $columns = '*', string $pageName = 'page', ?int $page = null): Paginator
    {
        $perPage = max(1, (int)$perPage);
        $page = $page ?: max(1, (int)Paginator::resolveCurrentPage($pageName));
        // one row more than the page holds, and the offset is still counted by the page size
        $items = $this->limit($perPage + 1)->offset(($page - 1) * $perPage)->get($columns);

        return new Paginator($items, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
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
