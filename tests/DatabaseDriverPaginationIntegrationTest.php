<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;

class DatabaseDriverPaginationIntegrationTest extends DatabaseDriverLargeResultTestCase
{
    public function testCursorPagesWalkAllDocumentsWithoutGrowingOffsetsOrCounting(): void
    {
        $query = $this->walkQuery()->select('id', 'category')->orderBy('category');
        $sql = $query->toSql();
        $bindings = $query->getBindings();
        $db = DB::connection('manticore');
        $db->enableQueryLog();
        $db->flushQueryLog();
        $cursor = null;
        $ids = [];
        $first = null;
        do {
            $page = $query->cursorPaginate(700, ['*'], 'cursor', $cursor);
            $this->assertInstanceOf(CursorPaginator::class, $page);
            $first ??= $page;
            array_push($ids, ...$page->getCollection()->pluck('id')->all());
            $cursor = $page->nextCursor()?->encode();
        } while ($cursor !== null);
        $expected = range(1, self::DOCUMENT_COUNT);
        usort($expected, fn ($a, $b) => ($a % 7 <=> $b % 7) ?: ($a <=> $b));
        $this->assertSame($expected, $ids);
        $this->assertNull($first->previousCursor());
        $this->assertSame($sql, $query->toSql());
        $this->assertSame($bindings, $query->getBindings());
        $this->assertCount(4, $this->selectLog());
        foreach ($this->selectLog() as $entry) {
            $this->assertStringNotContainsString('count(', strtolower($entry['query']));
            $this->assertStringNotContainsString('offset', strtolower($entry['query']));
            $this->assertStringContainsString('limit 701', $entry['query']);
        }
    }

    public function testCursorAliasesAndReversePagesRoundTrip(): void
    {
        $query = $this->walkQuery()->select('id as document_id', 'price')->orderBy('document_id', 'desc');
        $first = $query->cursorPaginate(3);
        $second = $query->cursorPaginate(3, ['*'], 'cursor', $first->nextCursor()->encode());
        $previous = $query->cursorPaginate(3, ['*'], 'cursor', $second->previousCursor());
        $this->assertSame([2105, 2104, 2103], $first->getCollection()->pluck('document_id')->all());
        $this->assertSame([2102, 2101, 2100], $second->getCollection()->pluck('document_id')->all());
        $this->assertSame($first->getCollection()->pluck('document_id')->all(), $previous->getCollection()->pluck('document_id')->all());
        $this->assertSame([1, 2], $this->walkQuery()->select('id as document_id')->cursorPaginate(2)->getCollection()->pluck('document_id')->all());
    }

    public function testFloatCursorKeysAreRejectedInsteadOfRepeatingRoundedBoundaries(): void
    {
        DB::connection('manticore')->table($this->walkTable)->insert([
            ['id' => 2106, 'title' => 'galaxy phone', 'price' => 0.000000142857142857],
            ['id' => 2107, 'title' => 'galaxy phone', 'price' => 0.000000152857142857],
        ]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('SQL float output');
        $this->walkQuery()->where('id', '>', 2105)->select('id', 'price')->orderBy('price')->cursorPaginate(1);
    }

    public function testCursorPaginationRetainsLargeIntegerBoundaries(): void
    {
        $ids = [9007199254740993, 9007199254740995, 9007199254740997];
        DB::connection('manticore')->table($this->walkTable)->insert(array_map(fn ($id) => ['id' => $id, 'title' => 'galaxy phone'], $ids));
        $query = $this->walkQuery()->where('id', '>', self::DOCUMENT_COUNT)->select('id');
        $first = $query->cursorPaginate(1);
        $second = $query->cursorPaginate(1, ['*'], 'cursor', $first->nextCursor()->encode());
        $this->assertSame([$ids[1]], $second->getCollection()->pluck('id')->all());
        $this->assertSame([$ids[2]], $query->cursorPaginate(1, ['*'], 'cursor', $second->nextCursor())->getCollection()->pluck('id')->all());
        $empty = $query->cursorPaginate(1, ['*'], 'cursor', new Cursor(['id' => $ids[2]]));
        $this->assertTrue($empty->isEmpty());
        $this->assertNull($empty->nextCursor());
    }

    public function testFilteredTotalsAndSourceBindingsRemainCorrect(): void
    {
        $query = $this->walkQuery()->select('id')->selectRaw('price * ? as adjusted', [2])->where('id', '>=', 1800)->orderBy('id');
        $sql = $query->toSql();
        $bindings = $query->getBindings();
        $page = $query->paginate(100, ['*'], 'page', 2);
        $this->assertSame(306, $page->total());
        $this->assertSame(range(1900, 1999), $page->getCollection()->pluck('id')->all());
        $this->assertSame(950.0, (float)$page->items()[0]->adjusted);
        $this->assertSame($sql, $query->toSql());
        $this->assertSame($bindings, $query->getBindings());
        $simple = $query->simplePaginate(100, ['*'], 'page', 4);
        $this->assertSame(range(2100, 2105), $simple->getCollection()->pluck('id')->all());
        $this->assertFalse($simple->hasMorePages());
    }

    public function testGroupedPaginationCountsAllGroupsBeyond1000AndKeepsOptions(): void
    {
        $query = $this->walkQuery()->select('id')->selectRaw('COUNT(*) as n')->groupBy('id')->orderBy('id')->option('max_matches', 10);
        $sql = $query->toSql();
        $page = $query->paginate(10, ['*'], 'page', 103);
        $this->assertSame(self::DOCUMENT_COUNT, $page->total());
        $this->assertSame(range(1021, 1030), $page->getCollection()->pluck('id')->all());
        $this->assertSame($sql, $query->toSql());
        $this->assertSame(['max_matches' => 10], $query->manticoreOptions);
        $multi = $this->walkQuery()->select('id', 'category')->groupBy('category', 'id')->orderBy('id');
        $this->assertSame(self::DOCUMENT_COUNT, $multi->getCountForPagination());
    }

    public function testHavingCountsOnlySurvivingGroupsIncludingEmptyResults(): void
    {
        $query = $this->walkQuery()->select('category')->selectRaw('COUNT(*) as n')->groupBy('category')
            ->having('n', '>', 300)->orderBy('category');
        $sql = $query->toSql();
        $bindings = $query->getBindings();
        $page = $query->paginate(2, ['*'], 'page', 2);
        $this->assertSame(5, $page->total());
        $this->assertSame([3, 4], $page->getCollection()->pluck('category')->all());
        $this->assertSame(5, $query->getCountForPagination());
        $this->assertSame($sql, $query->toSql());
        $this->assertSame($bindings, $query->getBindings());
        $empty = $query->cloneWithout(['havings'])->cloneWithoutBindings(['having'])->having('n', '>', 1000)->paginate(2);
        $this->assertSame(0, $empty->total());
        $this->assertSame([], $empty->items());
    }
}
