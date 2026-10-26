<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use PDO;

class DatabaseDriverApiAuditIntegrationTest extends DatabaseTestCase
{
    private ?string $auditTable = null;

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
        $this->auditTable = $this->tableName('apiaudit');
        $db = DB::connection('manticore');
        $db->getSchemaBuilder()->create($this->auditTable, function ($table) {
            $table->text('title');
            $table->integer('stock');
            $table->json('details');
        });
        foreach ([1, 2, 3] as $id) {
            $db->table($this->auditTable)->insert(['id' => $id, 'title' => 'phone', 'stock' => $id,
                'details' => ['tags' => $id < 3 ? ['sale'] : ['old']]]);
        }
    }

    protected function tearDown(): void
    {
        try {
            if ($this->auditTable !== null) DB::connection('manticore')->getSchemaBuilder()->dropIfExists($this->auditTable);
        } finally {
            parent::tearDown();
        }
    }

    private function queryTable()
    {
        return DB::connection('manticore')->table($this->auditTable);
    }

    public function testRejectedWriteClausesCannotUpdateDeleteOrTruncateDocuments(): void
    {
        foreach ([fn ($q) => $q->groupBy('stock'), fn ($q) => $q->having('stock', '>', 1),
            fn ($q) => $q->distinct(), fn ($q) => $q->forceIndex('stock')] as $configure) {
            foreach (['update', 'delete'] as $method) {
                try {
                    $query = $configure($this->queryTable()->where('id', 1));
                    $method === 'update' ? $query->update(['stock' => 99]) : $query->delete();
                    $this->fail('An unsupported write clause was ignored.');
                } catch (LogicException $error) {
                    $this->assertNotSame('', $error->getMessage());
                }
            }
        }
        try {
            $this->queryTable()->where('id', 1)->truncate();
            $this->fail('TRUNCATE ignored its filter.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('WHERE', $error->getMessage());
        }
        $this->assertSame([1, 2, 3], $this->queryTable()->orderBy('id')->pluck('stock')->all());
        $this->assertSame(1, $this->queryTable()->where('id', 1)->update(['stock' => 8]));
        $this->assertSame(8, $this->queryTable()->find(1)->stock);
        $this->queryTable()->truncate();
        $this->assertSame(0, $this->queryTable()->count());
    }

    public function testNestedDeferredBindingsExecuteAndFetchModesAreNotIgnored(): void
    {
        $query = $this->queryTable()->where(function ($nested) {
            $nested->whereJsonContains('details->tags', 'sale')->whereFullText('title', 'phone');
        })->whereIntegerInRaw('id', [1, 2, 3])->orderBy('id');
        $this->assertSame([1, 2], (clone $query)->pluck('id')->all());
        $this->assertSame(2, (clone $query)->search()->meta()->totalFound());
        $this->assertSame(2, (clone $query)->paginate(1)->total());
        $this->assertSame(['sale'], $query->getRawBindings()['select']);
        if (method_exists(Builder::class, 'fetchUsing')) {
            $row = (clone $query)->fetchUsing(PDO::FETCH_ASSOC)->first();
            $this->assertIsArray($row);
            $this->assertSame(['tags' => ['sale']], $row['details']);
            $db = DB::connection('manticore');
            $db->enableQueryLog();
            $db->flushQueryLog();
            try {
                (clone $query)->fetchUsing(PDO::FETCH_ASSOC)->search();
                $this->fail('search() ignored fetchUsing().');
            } catch (LogicException $error) {
                $this->assertStringContainsString('fetch', $error->getMessage());
            }
            $this->assertSame([], $db->getQueryLog());
        }
    }

    public function testBatchColumnsStayAlignedAndSupportedHavingRangesExecute(): void
    {
        try {
            $this->queryTable()->insert([['id' => 4, 'title' => 'wrong'], ['id' => 5, 'stock' => 99]]);
            $this->fail('A misaligned batch was inserted.');
        } catch (InvalidArgumentException $error) {
            $this->assertStringContainsString('identical column sets', $error->getMessage());
        }
        $this->assertSame(3, $this->queryTable()->count());
        $this->queryTable()->insert([['id' => 4, 'title' => 'four', 'stock' => 40], ['stock' => 50, 'id' => 5, 'title' => 'five']]);
        $this->assertSame(50, $this->queryTable()->find(5)->stock);
        $this->assertSame('five', $this->queryTable()->find(5)->title);
        $grouped = $this->queryTable()->select('stock')->selectRaw('count(*) as n')->groupBy('stock');
        $this->assertCount(5, (clone $grouped)->havingBetween('n', [1, 2])->get());
        $this->assertCount(5, (clone $grouped)->havingBetween('n', [2, 3], 'and', true)->get());
    }

    public function testUpdateRejectsTextAndColumnarEvenWithCastsDisabled(): void
    {
        $db = DB::connection('manticore');
        $name = $this->tableName('columnar');
        try {
            $db->getSchemaBuilder()->create($name, function ($table) {
                $table->text('title');
                $table->integer('stock');
                $table->setting('engine', 'columnar');
            });
            $db->table($name)->insert(['id' => 1, 'title' => 'old', 'stock' => 2]);
            config(['database.connections.manticore.cast_attributes' => false]);
            DB::purge('manticore');
            $db = DB::connection('manticore');
            foreach ([['title' => 'new'], ['stock' => 9]] as $values) {
                try {
                    $db->table($name)->where('id', 1)->update($values);
                    $this->fail('An unsupported UPDATE was sent.');
                } catch (LogicException $error) {
                    $this->assertStringContainsString('REPLACE', $error->getMessage());
                }
            }
            $this->assertSame(1, $db->table($name)->upsert(['id' => 1, 'stock' => 9], 'id'));
            $this->assertSame(9, (int) $db->table($name)->find(1)->stock);
            $this->assertSame('old', $db->table($name)->find(1)->title);
        } finally {
            $db->getSchemaBuilder()->dropIfExists($name);
        }
    }
}
