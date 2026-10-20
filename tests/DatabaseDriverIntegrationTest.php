<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use stdClass;

class DatabaseDriverIntegrationTest extends DatabaseTestCase
{
    private ?string $driverTable = null;

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
        $this->driverTable = $this->tableName('driver');
        $connection = DB::connection('manticore');
        $connection->statement("CREATE TABLE {$this->driverTable} (title text, brand string, price float)");
        $connection->table($this->driverTable)->insert([
            ['id' => 1, 'title' => 'galaxy phone', 'brand' => 'acme', 'price' => 100],
            ['id' => 2, 'title' => 'galaxy tablet', 'brand' => 'other', 'price' => 200],
            ['id' => 3, 'title' => 'apple phone', 'brand' => 'acme', 'price' => 300],
        ]);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->driverTable !== null) {
                DB::connection('manticore')->statement("DROP TABLE IF EXISTS {$this->driverTable}");
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testSearchReturnsLaravelRowsAndBindings(): void
    {
        $connection = DB::connection('manticore');
        $rows = $connection->table($this->driverTable)->select('id', 'title')
            ->match('galaxy')->where('price', '>', 100)->option('max_matches', 100)->get();
        $this->assertInstanceOf(Collection::class, $rows);
        $this->assertCount(1, $rows);
        $this->assertInstanceOf(stdClass::class, $rows[0]);
        $this->assertSame(2, (int)$rows[0]->id);
        $raw = $connection->select("SELECT title FROM {$this->driverTable} WHERE id = :id", ['id' => 1]);
        $this->assertInstanceOf(stdClass::class, $raw[0]);
        $this->assertSame('galaxy phone', $raw[0]->title);
    }

    public function testAggregatesExistsPluckAndOffset(): void
    {
        $query = DB::connection('manticore')->table($this->driverTable);
        $this->assertSame(3, $query->count());
        $this->assertTrue((clone $query)->where('id', 1)->exists());
        $this->assertFalse((clone $query)->where('id', 999)->exists());
        $this->assertSame(['galaxy tablet'], (clone $query)->orderBy('id')->offset(1)->limit(1)->pluck('title')->all());
        $this->assertSame(600.0, (float)$query->sum('price'));
    }

    public function testLazyAndCursorReturnLazyCollections(): void
    {
        $query = DB::connection('manticore')->table($this->driverTable)->orderBy('id');
        $this->assertInstanceOf(LazyCollection::class, $query->lazy(2));
        $this->assertSame([1, 2, 3], (clone $query)->lazy(2)->pluck('id')->map(fn ($id) => (int)$id)->all());
        $connection = $query->getConnection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        $cursor = $query->cursor();
        $this->assertInstanceOf(LazyCollection::class, $cursor);
        $this->assertSame([1, 2, 3], $cursor->pluck('id')->map(fn ($id) => (int)$id)->all());
        $this->assertCount(1, $connection->getQueryLog());
    }

    public function testWritesAndInsertGetIdUseTheSameConnection(): void
    {
        $query = DB::connection('manticore')->table($this->driverTable);
        $id = $query->insertGetId(['title' => 'new phone', 'brand' => 'acme', 'price' => 50]);
        $this->assertGreaterThan(0, $id);
        $this->assertSame('new phone', (clone $query)->find($id)->title);
        $this->assertSame(1, (clone $query)->where('id', $id)->update(['price' => 75]));
        $this->assertSame(1, (clone $query)->where('id', $id)->delete());
    }

    public function testSqlErrorsThrowLaravelQueryException(): void
    {
        $this->expectException(QueryException::class);
        DB::connection('manticore')->table($this->driverTable)->insert(['nonexistent' => 1]);
    }

    public function testDisconnectAndReconnectRestoreTheConnection(): void
    {
        $connection = DB::connection('manticore');
        $pdo = $connection->getPdo();
        DB::disconnect('manticore');
        $this->assertNull($connection->getRawPdo());
        DB::reconnect('manticore');
        $this->assertNotSame($pdo, $connection->getPdo());
        $this->assertSame(3, $connection->table($this->driverTable)->count());
    }

    public function testSingleRowReadsAndPaginationKeepLaravelContracts(): void
    {
        $query = DB::connection('manticore')->table($this->driverTable)->orderBy('id');
        $row = (clone $query)->find(1, ['id', 'title']);
        $this->assertInstanceOf(stdClass::class, $row);
        $this->assertSame(['id', 'title'], array_keys((array)$row));
        $this->assertNull((clone $query)->where('id', 999)->first());
        $page = (clone $query)->paginate(2, ['*'], 'page', 2);
        $this->assertSame(3, $page->total());
        $this->assertCount(1, $page->items());
        $this->assertSame(3, (int)$page->items()[0]->id);
    }

    public function testBindingsPreserveQuotesBackslashesAndUnicode(): void
    {
        $connection = DB::connection('manticore');
        $value = "Привет, O'Reilly\\books";
        $connection->table($this->driverTable)->insert(['id' => 4, 'brand' => $value]);
        $this->assertSame($value, $connection->table($this->driverTable)->where('brand', $value)->value('brand'));
    }

    public function testTransactionCommitAndRollback(): void
    {
        $connection = DB::connection('manticore');
        $connection->transaction(fn ($db) => $db->table($this->driverTable)->insert(['id' => 4, 'title' => 'committed']));
        $this->assertTrue($connection->table($this->driverTable)->where('id', 4)->exists());
        try {
            $connection->transaction(function ($db) {
                $db->table($this->driverTable)->insert(['id' => 5, 'title' => 'rolled back']);
                throw new \RuntimeException('Abort transaction');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('Abort transaction', $e->getMessage());
        }
        $this->assertSame(0, $connection->transactionLevel());
        $this->assertFalse($connection->table($this->driverTable)->where('id', 5)->exists());
    }

    public function testDeepPaginationAndLazyWalkGrowTheSearchWindow(): void
    {
        $db = DB::connection('manticore');
        $rows = [];
        for ($id = 4; $id <= 1050; ++$id) {
            $rows[] = ['id' => $id, 'title' => 'phone'];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            $db->table($this->driverTable)->insert($chunk);
        }
        $query = $db->table($this->driverTable)->orderBy('id');
        $page = (clone $query)->paginate(10, ['id'], 'page', 103);
        $this->assertSame(1050, $page->total());
        $this->assertSame(range(1021, 1030), $page->getCollection()->pluck('id')->all());
        $simplePage = (clone $query)->simplePaginate(10, ['id'], 'page', 103);
        $this->assertSame(range(1021, 1030), $simplePage->getCollection()->pluck('id')->all());
        $this->assertTrue($simplePage->hasMorePages());
        $this->assertSame(range(1, 1050), (clone $query)->lazy(500)->pluck('id')->all());
    }

    public function testFloatBindingsWorkInBuilderRawQueriesAndWrites(): void
    {
        $db = DB::connection('manticore');
        $query = $db->table($this->driverTable);
        $this->assertTrue((clone $query)->insert(['id' => 4, 'title' => 'phone', 'brand' => "O'Reilly ?", 'price' => 12.75]));
        $this->assertSame([4], (clone $query)->where('price', '>', 12.5)->where('price', '<', 13.0)->pluck('id')->all());
        $rows = $db->select("SELECT id FROM {$this->driverTable} WHERE price > :min AND price < :max AND brand = :brand", [
            'min' => 12.5, 'max' => 13.0, 'brand' => "O'Reilly ?",
        ]);
        $this->assertSame(4, $rows[0]->id);
        $this->assertSame(1, (clone $query)->where('id', 4)->update(['price' => 14.25]));
        $this->assertEqualsWithDelta(14.25, (clone $query)->where('id', 4)->value('price'), 0.00001);
    }

    public function testInsertIdIsCapturedBeforeAnEventListenerPerformsAnotherWrite(): void
    {
        $db = DB::connection('manticore');
        $listenerRan = false;
        DB::listen(function ($event) use (&$listenerRan, $db) {
            if (!$listenerRan && str_starts_with($event->sql, 'insert into')) {
                $listenerRan = true;
                $db->table($this->driverTable)->insert(['id' => 99, 'title' => 'listener']);
            }
        });
        $this->assertSame(42, $db->table($this->driverTable)->insertGetId(['id' => 42, 'title' => 'original']));
        $this->assertTrue($listenerRan);
        $this->assertSame('listener', $db->table($this->driverTable)->find(99)->title);
    }

    public function testFailedWriteRollsBackAndAfterCommitRunsOnlyOnSuccess(): void
    {
        $db = DB::connection('manticore');
        $committed = 0;
        $db->transaction(function ($db) use (&$committed) {
            $db->afterCommit(function () use (&$committed) { ++$committed; });
            $db->table($this->driverTable)->insert(['id' => 4, 'title' => 'committed']);
        });
        $this->assertSame(1, $committed);
        try {
            $db->transaction(function ($db) {
                $db->table($this->driverTable)->insert(['id' => 5, 'title' => 'rolled back']);
                $db->table($this->driverTable)->insert(['unknown_column' => 1]);
            });
            $this->fail('A failed write did not throw.');
        } catch (QueryException $e) {
            $this->assertSame(0, $db->transactionLevel());
        }
        $this->assertFalse($db->table($this->driverTable)->where('id', 5)->exists());
    }
}
