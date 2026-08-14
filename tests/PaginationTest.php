<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Row;
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * paginate() and simplePaginate() - the pagination Laravel templates expect.
 */
class PaginationTest extends TestCase
{
    /**
     * @var string|null
     */
    protected $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresServer();

        $this->table = $this->tableName('paging');

        ManticoreDb::create($this->table, [
            'title' => 'text',
            'price' => 'float',
        ]);

        $rows = [];
        for ($i = 1; $i <= 7; $i++) {
            $rows[] = ['id' => $i, 'title' => 'row number ' . $i, 'price' => $i * 10.0];
        }
        ManticoreDb::table($this->table)->insert($rows);
    }

    protected function tearDown(): void
    {
        if ($this->table) {
            ManticoreDb::dropIfExists($this->table);
            $this->table = null;
        }

        parent::tearDown();
    }

    public function testPaginateReturnsALengthAwarePaginator(): void
    {
        $paginator = ManticoreDb::table($this->table)->orderBy('id')->paginate(3, '*', 'page', 1);

        $this->assertInstanceOf(LengthAwarePaginator::class, $paginator);
        $this->assertSame(7, $paginator->total());
        $this->assertSame(3, $paginator->perPage());
        $this->assertSame(1, $paginator->currentPage());
        $this->assertSame(3, $paginator->lastPage());
        $this->assertCount(3, $paginator->items());
        $this->assertContainsOnlyInstancesOf(Row::class, $paginator->items());
    }

    public function testPaginateReturnsTheRequestedPage(): void
    {
        $paginator = ManticoreDb::table($this->table)->orderBy('id')->paginate(3, '*', 'page', 3);

        $this->assertSame(3, $paginator->currentPage());
        $this->assertCount(1, $paginator->items());
        $this->assertSame('row number 7', $paginator->items()[0]->title);
        $this->assertFalse($paginator->hasMorePages());
    }

    public function testPaginateRespectsTheConditions(): void
    {
        $paginator = ManticoreDb::table($this->table)->where('price', '>', 40)->paginate(2, '*', 'page', 1);

        $this->assertSame(3, $paginator->total());
        $this->assertCount(2, $paginator->items());
    }

    public function testPaginateOfAnEmptyResult(): void
    {
        $paginator = ManticoreDb::table($this->table)->where('price', '>', 1000)->paginate(3, '*', 'page', 1);

        $this->assertSame(0, $paginator->total());
        $this->assertSame([], $paginator->items());
    }

    public function testPaginateSelectsTheGivenColumns(): void
    {
        $paginator = ManticoreDb::table($this->table)->paginate(2, 'title', 'page', 1);

        $this->assertSame(['title'], array_keys($paginator->items()[0]->toArray()));
    }

    public function testSimplePaginateKnowsWhetherThereIsANextPage(): void
    {
        $first = ManticoreDb::table($this->table)->orderBy('id')->simplePaginate(3, '*', 'page', 1);
        $last = ManticoreDb::table($this->table)->orderBy('id')->simplePaginate(3, '*', 'page', 3);

        $this->assertInstanceOf(Paginator::class, $first);
        $this->assertCount(3, $first->items());
        $this->assertTrue($first->hasMorePages());

        $this->assertCount(1, $last->items());
        $this->assertFalse($last->hasMorePages());
    }

    /**
     * The page number comes from the request when it is not given
     */
    public function testCurrentPageIsResolvedFromTheRequest(): void
    {
        Paginator::currentPageResolver(static function () {
            return 2;
        });

        $paginator = ManticoreDb::table($this->table)->orderBy('id')->paginate(3);

        $this->assertSame(2, $paginator->currentPage());
        $this->assertSame('row number 4', $paginator->items()[0]->title);

        Paginator::currentPageResolver(static function () {
            return 1;
        });
    }
}
