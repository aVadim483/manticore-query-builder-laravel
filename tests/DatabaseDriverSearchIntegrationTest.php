<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use stdClass;

class DatabaseDriverSearchIntegrationTest extends DatabaseTestCase
{
    private ?string $searchTable = null;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('database.connections.manticore', [
            'driver' => 'manticore', 'host' => $this->serverHost(), 'port' => $this->serverPort(), 'timeout' => 3,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresServer();
        $this->searchTable = $this->tableName('search');
        $db = DB::connection('manticore');
        $db->statement("CREATE TABLE {$this->searchTable} (title text, tags multi, tags64 multi64, lat float, lon float, location json)");
        foreach ([
            [1, 'galaxy phone', '(1,2)', 0, 0],
            [2, 'galaxy tablet', '(2,3)', 0, 1],
            [3, 'apple phone', '(3,4)', 1, 1],
        ] as [$id, $title, $tags, $lat, $lon]) {
            $db->table($this->searchTable)->insert([
                'id' => $id, 'title' => $title, 'tags' => DB::raw($tags),
                'tags64' => DB::raw('(9007199254740993)'), 'lat' => $lat, 'lon' => $lon,
                'location' => json_encode(['lat' => $lat, 'lon' => $lon]),
            ]);
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->searchTable !== null) {
                DB::connection('manticore')->statement("DROP TABLE IF EXISTS {$this->searchTable}");
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testSearchCapturesMetadataBeforeQueryListenersChangeIt(): void
    {
        $db = DB::connection('manticore');
        $db->enableQueryLog();
        $db->flushQueryLog();
        $listenerRan = false;
        DB::listen(function (QueryExecuted $event) use (&$listenerRan, $db) {
            if (!$listenerRan && str_contains($event->sql, 'MATCH(')) {
                $listenerRan = true;
                $db->table($this->searchTable)->where('id', 3)->get();
            }
        });
        $query = $db->table($this->searchTable)->match('galaxy')->withScore()->limit(1);
        $result = $query->search(['id', 'title']);
        $this->assertTrue($listenerRan);
        $this->assertCount(1, $result->rows());
        $this->assertInstanceOf(stdClass::class, $result->rows()[0]);
        $this->assertGreaterThan(0, $result->rows()[0]->_score);
        $this->assertSame(2, $result->meta()->totalFound());
        $this->assertIsFloat($result->meta()->time());
        $this->assertNull($query->columns);
        $this->assertSame(['SHOW META'], array_values(array_map(fn ($entry) => $entry['query'], array_filter($db->getQueryLog(), fn ($entry) => $entry['query'] === 'SHOW META'))));
        $db->table($this->searchTable)->get();
        $this->assertSame(2, $result->meta()->totalFound());
    }

    public function testGetDoesNotFetchMetadataAndGetMetaCanReadItExplicitly(): void
    {
        $db = DB::connection('manticore');
        $db->enableQueryLog();
        $db->flushQueryLog();
        $query = $db->table($this->searchTable)->match('galaxy')->limit(1);
        $this->assertCount(1, $query->get());
        $this->assertCount(1, $db->getQueryLog());
        $this->assertSame(2, $query->getMeta()->totalFound());
        $this->assertCount(2, $db->getQueryLog());
    }

    public function testMvaAnyAllRangesAndLargeIntegers(): void
    {
        $query = DB::connection('manticore')->table($this->searchTable)->orderBy('id');
        $this->assertSame([1, 2], (clone $query)->whereMvaAny('tags', 2)->pluck('id')->all());
        $this->assertSame([1], (clone $query)->whereMvaAll('tags', [1, 2])->pluck('id')->all());
        $this->assertSame([1, 2, 3], (clone $query)->whereMvaAny('tags', 'BETWEEN', [2, 3])->pluck('id')->all());
        $this->assertSame([1, 3], (clone $query)->where(fn ($q) => $q->whereMvaAny('tags', 1)->orWhereMvaAll('tags', '>', 2))->pluck('id')->all());
        $this->assertSame(3, (clone $query)->whereMvaAny('tags64', '9007199254740993')->count());
    }

    public function testGeoDistanceWorksWithSelectFilteringAndOrdering(): void
    {
        $query = DB::connection('manticore')->table($this->searchTable)
            ->withDistance('lat', 'lon', 0, 0, 'km')->select('id')
            ->whereGeoDistance('lat', 'lon', 0, 0, 120, 'km')
            ->orderByDistance('lat', 'lon', 0, 0, 'km');
        $rows = $query->get();
        $this->assertSame([1, 2], $rows->pluck('id')->all());
        $this->assertEqualsWithDelta(0, $rows[0]->_distance, 0.01);
        $this->assertGreaterThan(100, $rows[1]->_distance);
        $this->assertSame(['id', '_distance'], array_keys((array)$rows[0]));
    }

    public function testHiddenGeoExpressionsAreRemovedFromGetSearchCursorAndAggregates(): void
    {
        $query = DB::connection('manticore')->table($this->searchTable)
            ->where(fn ($q) => $q->whereGeoDistance('lat', 'lon', 0, 0, 120, 'km'))
            ->orderByDistance('lat', 'lon', 0, 0, 'km')->select('id');
        foreach ([$query->get(), $query->search()->rows(), $query->cursor()->collect()] as $rows) {
            $this->assertSame([1, 2], $rows->pluck('id')->all());
            $this->assertSame(['id'], array_keys((array)$rows[0]));
        }
        $this->assertSame(2, (clone $query)->count());
        $this->assertSame(2, (clone $query)->paginate(1)->total());
    }

    public function testJsonCoordinatePathsAreCastForGeoSearch(): void
    {
        $rows = DB::connection('manticore')->table($this->searchTable)->select('id')
            ->whereGeoDistance('location.lat', 'location.lon', 0, 0, 10, 'km')->get();
        $this->assertSame([1], $rows->pluck('id')->all());
    }
}
