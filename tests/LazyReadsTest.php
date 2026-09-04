<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Row;
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use Illuminate\Support\Collection;

/**
 * chunk(), chunkById(), each(), lazy(), cursor() and sole() - the walks of a result that is too
 * large to be read at once.
 *
 * They live in the query builder, and everything here is about the shape of what they hand over:
 * a page is a Collection and a row is a Row, the same as get() and first() answer with. Without
 * these overrides the walks would hand out plain arrays, so the code inside a chunk callback
 * would have to be written differently from the code outside it.
 */
class LazyReadsTest extends TestCase
{
    /**
     * @var string|null
     */
    protected $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresServer();

        $this->table = $this->tableName('walk');

        ManticoreDb::create($this->table, [
            'title' => 'text',
            'num'   => 'int',
        ]);

        $rows = [];
        for ($i = 1; $i <= 7; $i++) {
            $rows[] = ['id' => $i, 'title' => 'row number ' . $i, 'num' => $i];
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

    public function testChunkHandsOverCollectionsOfRows(): void
    {
        $pages = [];
        $seen = [];

        $result = ManticoreDb::table($this->table)->orderBy('id')->chunk(3, function ($rows, $page) use (&$pages, &$seen) {
            $this->assertInstanceOf(Collection::class, $rows);
            $this->assertContainsOnlyInstancesOf(Row::class, $rows->all());

            $pages[] = $page;
            foreach ($rows as $row) {
                $seen[] = $row->title;
            }
        });

        $this->assertTrue($result);
        $this->assertSame([1, 2, 3], $pages);
        $this->assertCount(7, $seen);
        $this->assertSame('row number 1', $seen[0]);
        $this->assertSame('row number 7', $seen[6]);
    }

    public function testChunkStopsWhenTheCallbackSaysSo(): void
    {
        $pages = 0;

        $result = ManticoreDb::table($this->table)->orderBy('id')->chunk(3, function () use (&$pages) {
            $pages++;

            return false;
        });

        $this->assertFalse($result);
        $this->assertSame(1, $pages);
    }

    public function testChunkByIdHandsOverCollectionsOfRows(): void
    {
        $seen = [];

        $result = ManticoreDb::table($this->table)->chunkById(3, function ($rows) use (&$seen) {
            $this->assertInstanceOf(Collection::class, $rows);
            $this->assertContainsOnlyInstancesOf(Row::class, $rows->all());

            foreach ($rows as $row) {
                $seen[] = (int)$row->id;
            }
        });

        $this->assertTrue($result);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $seen);
    }

    public function testEachHandsOverOneRowAtATime(): void
    {
        $numbers = [];
        $titles = [];

        $result = ManticoreDb::table($this->table)->orderBy('id')->each(function ($row, $number) use (&$numbers, &$titles) {
            $this->assertInstanceOf(Row::class, $row);

            $numbers[] = $number;
            $titles[] = $row->title;
        }, 3);

        $this->assertTrue($result);
        $this->assertSame([0, 1, 2, 3, 4, 5, 6], $numbers);
        $this->assertSame('row number 4', $titles[3]);
    }

    public function testEachStopsWhenTheCallbackSaysSo(): void
    {
        $seen = 0;

        $result = ManticoreDb::table($this->table)->orderBy('id')->each(function () use (&$seen) {
            $seen++;

            return $seen < 2 ? null : false;
        }, 3);

        $this->assertFalse($result);
        $this->assertSame(2, $seen);
    }

    public function testLazyYieldsRowsNumberedFromZero(): void
    {
        $rows = ManticoreDb::table($this->table)->orderBy('id')->lazy(3);

        $this->assertInstanceOf(\Generator::class, $rows);

        $collected = [];
        foreach ($rows as $key => $row) {
            $this->assertInstanceOf(Row::class, $row);
            $collected[$key] = $row->title;
        }

        $this->assertSame(range(0, 6), array_keys($collected));
        $this->assertSame('row number 1', $collected[0]);
        $this->assertSame('row number 7', $collected[6]);
    }

    /**
     * The keys of the builder are document ids, which repeat when a walk without an order meets
     * the same row twice - a generator numbered from zero loses nothing when it is read at once
     */
    public function testLazyReadIntoAnArrayKeepsEveryRow(): void
    {
        $rows = iterator_to_array(ManticoreDb::table($this->table)->orderBy('id')->lazy(2));

        $this->assertCount(7, $rows);
    }

    public function testCursorWalksTheSameWayAsLazy(): void
    {
        $titles = [];
        foreach (ManticoreDb::table($this->table)->orderBy('id')->cursor(2) as $row) {
            $this->assertInstanceOf(Row::class, $row);
            $titles[] = $row->title;
        }

        $this->assertCount(7, $titles);
        $this->assertSame('row number 1', $titles[0]);
    }

    public function testSoleReturnsTheOnlyRow(): void
    {
        $row = ManticoreDb::table($this->table)->where('num', 4)->sole();

        $this->assertInstanceOf(Row::class, $row);
        $this->assertSame('row number 4', $row->title);
    }

    public function testSoleThrowsWhenMoreThanOneRowMatches(): void
    {
        $this->expectException(\RuntimeException::class);

        ManticoreDb::table($this->table)->where('num', '>', 1)->sole();
    }

    public function testSoleThrowsWhenNothingMatches(): void
    {
        $this->expectException(\RuntimeException::class);

        ManticoreDb::table($this->table)->where('num', 100)->sole();
    }
}
