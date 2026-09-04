<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Row;
use avadim\Manticore\Laravel\Tests\Support\ArrayLogger;
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

    /**
     * Table of a result deeper than max_matches, built only by the test that needs it
     *
     * @var string|null
     */
    protected $deepTable;

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

        if ($this->deepTable) {
            ManticoreDb::dropIfExists($this->deepTable);
            $this->deepTable = null;
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

    /**
     * The queries the logger saw, as SQL
     *
     * @param ArrayLogger $logger
     *
     * @return string
     */
    private function loggedSql(ArrayLogger $logger): string
    {
        $sql = [];
        foreach ($logger->recordsOfLevel('info') as $record) {
            $sql[] = isset($record['context']['query']) ? (string)$record['context']['query'] : '';
        }

        return implode(' ;; ', $sql);
    }

    /**
     * Manticore looks through max_matches rows and no further - a thousand of them by default -
     * and a window past that is an error, not an empty page. A paginator of fifteen rows draws a
     * link to page 68 and then answers it with "offset out of bounds", so the page asks for the
     * matches it needs.
     */
    public function testPaginateAsksForTheMatchesADeepPageNeeds(): void
    {
        $logger = new ArrayLogger();

        $paginator = ManticoreDb::table($this->table)->setLogger($logger)->paginate(15, '*', 'page', 68);

        $this->assertSame(7, $paginator->total());
        $this->assertSame([], $paginator->items());
        // 67 pages of 15 rows behind it, and 15 more on it
        $this->assertMatchesRegularExpression('/max_matches\s*=\s*1020/', $this->loggedSql($logger));
    }

    /**
     * ... and a page within reach is left as it was: a wider window costs the server memory
     */
    public function testPaginateOfAShallowPageAsksForNothing(): void
    {
        $logger = new ArrayLogger();

        ManticoreDb::table($this->table)->setLogger($logger)->paginate(15, '*', 'page', 1);

        $this->assertStringNotContainsString('max_matches', $this->loggedSql($logger));
    }

    /**
     * An explicit maxMatches() belongs to the caller, whether it is larger or smaller
     */
    public function testPaginateKeepsTheMaxMatchesOfTheCaller(): void
    {
        $logger = new ArrayLogger();

        ManticoreDb::table($this->table)->setLogger($logger)->maxMatches(2000)->paginate(15, '*', 'page', 68);

        $sql = $this->loggedSql($logger);

        $this->assertMatchesRegularExpression('/max_matches\s*=\s*2000/', $sql);
        $this->assertStringNotContainsString('1020', $sql);
    }

    public function testSimplePaginateReachesADeepPageAsWell(): void
    {
        $logger = new ArrayLogger();

        $paginator = ManticoreDb::table($this->table)->setLogger($logger)->simplePaginate(15, '*', 'page', 68);

        $this->assertSame([], $paginator->items());
        // one row more than the page holds is asked for, hence 1021 rather than 1020
        $this->assertMatchesRegularExpression('/max_matches\s*=\s*1021/', $this->loggedSql($logger));
    }

    /**
     * The whole of it against a result that really is deeper than the default: the last page of
     * 1200 rows is the one the paginator used to fail on
     */
    public function testTheLastPageOfAResultDeeperThanTheDefaultIsServed(): void
    {
        $this->deepTable = $this->tableName('deep');

        ManticoreDb::create($this->deepTable, ['title' => 'text']);

        for ($chunk = 0; $chunk < 4; $chunk++) {
            $rows = [];
            for ($i = $chunk * 300 + 1; $i <= ($chunk + 1) * 300; $i++) {
                $rows[] = ['id' => $i, 'title' => 'row number ' . $i];
            }
            ManticoreDb::table($this->deepTable)->insert($rows);
        }

        $paginator = ManticoreDb::table($this->deepTable)->orderBy('id')->paginate(15, '*', 'page', 80);

        $this->assertSame(1200, $paginator->total());
        $this->assertSame(80, $paginator->lastPage());
        $this->assertCount(15, $paginator->items());
        $this->assertSame('row number 1186', $paginator->items()[0]->title);
        $this->assertSame('row number 1200', $paginator->items()[14]->title);
    }
}
