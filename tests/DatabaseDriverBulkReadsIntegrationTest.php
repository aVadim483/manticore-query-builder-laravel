<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use Illuminate\Support\Facades\DB;

class DatabaseDriverBulkReadsIntegrationTest extends DatabaseDriverLargeResultTestCase
{
    public function testOffsetWalksVisitEveryDocumentBeyondTheDefaultMatchWindow(): void
    {
        $query = $this->walkQuery()->select('id', 'payload')->orderBy('id');
        $ids = $pages = [];
        $this->assertTrue((clone $query)->chunk(700, function ($rows, $page) use (&$ids, &$pages) {
            $pages[] = [$page, $rows->count()];
            array_push($ids, ...$rows->pluck('id')->all());
            $this->assertSame($rows->last()->id, $rows->last()->payload['position']);
        }));
        $this->assertSame(range(1, self::DOCUMENT_COUNT), $ids);
        $this->assertSame([[1, 700], [2, 700], [3, 700], [4, 5]], $pages);
        $each = [];
        $this->assertTrue((clone $query)->each(function ($row) use (&$each) { $each[] = $row->id; }, 700));
        $this->assertSame($ids, $each);
        $this->assertSame($ids, (clone $query)->lazy(700)->pluck('id')->all());
    }

    public function testIdWalksSupportBothDirectionsAndSelectedAliases(): void
    {
        $query = $this->walkQuery()->select('id as document_id', 'payload');
        foreach (['chunkById', 'chunkByIdDesc'] as $method) {
            $ids = [];
            $this->assertTrue((clone $query)->$method(700, function ($rows) use (&$ids) {
                array_push($ids, ...$rows->pluck('document_id')->all());
            }, 'id', 'document_id'));
            $this->assertSame($method === 'chunkById' ? range(1, self::DOCUMENT_COUNT) : range(self::DOCUMENT_COUNT, 1), $ids);
        }
        $ids = $indexes = [];
        $this->assertTrue((clone $query)->eachById(function ($row, $index) use (&$ids, &$indexes) {
            $ids[] = $row->document_id;
            $indexes[] = $index;
        }, 700, 'id', 'document_id'));
        $this->assertSame(range(1, self::DOCUMENT_COUNT), $ids);
        $this->assertSame(range(0, self::DOCUMENT_COUNT - 1), $indexes);
        $this->assertSame($ids, (clone $query)->lazyById(700, 'id', 'document_id')->pluck('document_id')->all());
        $this->assertSame(array_reverse($ids), (clone $query)->lazyByIdDesc(700, 'id', 'document_id')->pluck('document_id')->all());
    }

    public function testEarlyExitDoesNotFetchAnotherPageAndLazyQueriesAreDeferred(): void
    {
        $db = DB::connection('manticore');
        $db->enableQueryLog();
        $db->flushQueryLog();
        $lazy = $this->walkQuery()->orderBy('id')->lazy(700);
        $this->assertSame([], $this->selectLog());
        $this->assertSame([1, 2, 3], $lazy->take(3)->pluck('id')->all());
        $this->assertCount(1, $this->selectLog());
        $db->flushQueryLog();
        $this->assertFalse($this->walkQuery()->chunkById(700, fn () => false));
        $this->assertCount(1, $this->selectLog());
        $db->flushQueryLog();
        $this->assertFalse($this->walkQuery()->orderBy('id')->each(fn ($row) => $row->id < 5, 700));
        $this->assertCount(1, $this->selectLog());
        $db->flushQueryLog();
        $this->assertSame([1, 2, 3], $this->walkQuery()->lazyById(700)->take(3)->pluck('id')->all());
        $this->assertCount(1, $this->selectLog());
    }

    public function testIdWalksRemainCompleteWhenProcessedDocumentsAreDeleted(): void
    {
        $db = DB::connection('manticore');
        $ids = [];
        $this->walkQuery()->select('id')->chunkById(700, function ($rows) use (&$ids, $db) {
            $batch = $rows->pluck('id')->all();
            array_push($ids, ...$batch);
            $db->table($this->walkTable)->whereIn('id', $batch)->delete();
        });
        $this->assertSame(range(1, self::DOCUMENT_COUNT), $ids);
        $this->assertSame(0, $db->table($this->walkTable)->count());
    }

    public function testIdBoundariesKeep64BitPrecisionAndGroupedOrFilters(): void
    {
        $db = DB::connection('manticore');
        $large = [9007199254740993, 9007199254740995, 9007199254740997];
        $db->table($this->walkTable)->insert(array_map(fn ($id) => ['id' => $id, 'title' => 'galaxy phone'], $large));
        $this->assertSame($large, $this->walkQuery()->where('id', '>', self::DOCUMENT_COUNT)->lazyById(1)->pluck('id')->all());
        $ids = $this->walkQuery()->where(fn ($query) => $query->where('id', '<=', 3)->orWhere('id', '>', 2102))
            ->where('id', '<=', self::DOCUMENT_COUNT)->lazyById(2)->pluck('id')->all();
        $this->assertSame([1, 2, 3, 2103, 2104, 2105], $ids);
        $this->assertTrue($this->walkQuery()->where('id', 0)->chunkById(700, fn () => $this->fail('An empty result invoked the callback.')));
    }
}
