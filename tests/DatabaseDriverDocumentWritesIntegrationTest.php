<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

class DatabaseDriverDocumentWritesIntegrationTest extends DatabaseTestCase
{
    private ?string $writeTable = null;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('database.connections.manticore', [
            'driver' => 'manticore', 'host' => $this->serverHost(), 'port' => $this->serverPort(),
            'prefix' => 'app_', 'timeout' => 3,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresServer();
        $this->writeTable = $this->tableName('writes');
        $db = DB::connection('manticore');
        $db->getSchemaBuilder()->create($this->writeTable, function ($table) {
            $table->text('title');
            $table->text('body');
            $table->string('sku');
            $table->integer('stock');
            $table->float('price');
            $table->json('details');
            $table->multi64('tags');
            $table->floatVector('embedding');
        });
        $db->table($this->writeTable)->insert([
            'id' => 1, 'title' => 'old phone', 'body' => 'keep this body', 'sku' => 'A', 'stock' => 5, 'price' => 1.25,
            'details' => ['empty' => (object) [], 'items' => [], 'nullable' => null],
            'tags' => [9007199254740993, 2], 'embedding' => [0.25, 1, 2],
        ]);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->writeTable !== null) {
                $db = DB::connection('manticore');
                if ($db->transactionLevel() > 0) {
                    $db->rollBack();
                }
                $db->getSchemaBuilder()->dropIfExists($this->writeTable);
            }
        } finally {
            parent::tearDown();
        }
    }

    private function writesQuery()
    {
        return DB::connection('manticore')->table($this->writeTable);
    }

    public function testUpdateOrInsertCreatesAndUpdatesWithoutReplacingOtherFields(): void
    {
        $query = $this->writesQuery();
        $originalWheres = $query->wheres;
        $this->assertTrue($query->updateOrInsert(['sku' => 'B'], fn ($exists) => ['id' => 2, 'title' => $exists ? 'wrong' : 'created', 'stock' => 3]));
        $this->assertTrue($query->updateOrInsert(['sku' => 'A'], fn ($exists) => ['stock' => $exists ? 7 : 99]));
        $this->assertTrue($query->updateOrInsert(['id' => 1]));
        $this->assertSame('created', (clone $query)->find(2)->title);
        $row = $this->writesQuery()->find(1);
        $this->assertSame(7, $row->stock);
        $this->assertSame('old phone', $row->title);
        $this->assertSame('keep this body', $row->body);
        $this->assertSame($originalWheres, $query->wheres);
    }

    public function testReplaceOrInsertUsesTheMatchedIdAndResetsOmittedFields(): void
    {
        $this->assertTrue($this->writesQuery()->replaceOrInsert(['sku' => 'A'], ['title' => 'replaced phone']));
        $row = $this->writesQuery()->find(1);
        $this->assertSame('replaced phone', $row->title);
        $this->assertSame('A', $row->sku);
        $this->assertSame('', $row->body);
        $this->assertSame(0, $row->stock);
        $this->assertSame([], $row->tags);
        $this->assertSame([], $row->embedding);
        $this->assertTrue($this->writesQuery()->replaceOrInsert(['sku' => 'B'], fn ($exists) => ['id' => 2, 'title' => 'inserted']));
        $this->assertSame('inserted', $this->writesQuery()->find(2)->title);
    }

    public function testUpsertUsesReplaceAndPreservesFieldsNotSelectedForUpdate(): void
    {
        $db = DB::connection('manticore');
        $db->enableQueryLog();
        $db->flushQueryLog();
        $this->assertSame(2, $this->writesQuery()->upsert([
            ['id' => 999, 'sku' => 'A', 'title' => 'changed phone'],
            ['id' => 2, 'sku' => 'B', 'title' => 'new phone'],
        ], 'sku', ['title']));
        $row = $this->writesQuery()->find(1);
        $this->assertSame('changed phone', $row->title);
        $this->assertSame('keep this body', $row->body);
        $this->assertSame(5, $row->stock);
        $this->assertSame(1.25, $row->price);
        $this->assertSame([2, 9007199254740993], $row->tags);
        $this->assertSame([0.25, 1.0, 2.0], $row->embedding);
        $this->assertNull($this->writesQuery()->find(999));
        $this->assertSame('new phone', $this->writesQuery()->find(2)->title);
        $query = $this->writesQuery()->where('id', 1)->select('details');
        $raw = $db->selectFromWriteConnection($query->toSql(), $query->getBindings())[0]->details;
        $this->assertInstanceOf(\stdClass::class, json_decode($raw)->empty);
        $log = array_column($db->getQueryLog(), 'query');
        $this->assertCount(1, array_filter($log, fn ($sql) => str_starts_with($sql, 'replace into')));
        $this->assertCount(0, array_filter($log, fn ($sql) => str_starts_with($sql, 'update ')));
        $this->assertSame(0, $db->transactionLevel());
    }

    public function testDefaultUpsertColumnsPreserveOmittedFieldsAndSupportLargeIds(): void
    {
        $this->assertSame(1, $this->writesQuery()->upsert(['id' => 1, 'title' => 'new title', 'stock' => 8], ['id']));
        $this->assertSame(8, $this->writesQuery()->find(1)->stock);
        $this->assertSame('keep this body', $this->writesQuery()->find(1)->body);
        $this->assertTrue($this->writesQuery()->updateOrInsert(['id' => '9007199254740993'], ['title' => 'large ID']));
        $this->assertSame(1, $this->writesQuery()->upsert(['id' => '9007199254740993', 'title' => 'large changed'], 'id'));
        $this->assertSame('large changed', $this->writesQuery()->find(9007199254740993)->title);
        $this->assertTrue($this->writesQuery()->updateOrInsert(['id' => '18446744073709551615'], ['title' => 'maximum ID']));
        $this->assertSame(1, $this->writesQuery()->upsert(['id' => '18446744073709551615', 'title' => 'maximum changed'], 'id'));
        $this->assertSame('maximum changed', $this->writesQuery()->find(-1)->title);
    }

    public function testFailureAfterStagingWritesRollsBackTheWholeUpsert(): void
    {
        $db = DB::connection('manticore');
        $writes = 0;
        DB::listen(function (QueryExecuted $event) use (&$writes) {
            if (preg_match('/^(?:insert|replace) into/', $event->sql) && ++$writes === 2) {
                throw new RuntimeException('Injected write failure');
            }
        });
        try {
            $this->writesQuery()->upsert([['id' => 1, 'title' => 'must roll back'], ['id' => 2, 'title' => 'must disappear']], 'id');
            $this->fail('The injected failure did not propagate.');
        } catch (RuntimeException $error) {
            $this->assertSame('Injected write failure', $error->getMessage());
        }
        $this->assertSame(2, $writes);
        $this->assertSame(0, $db->transactionLevel());
        $this->assertSame('old phone', $this->writesQuery()->find(1)->title);
        $this->assertNull($this->writesQuery()->find(2));
    }

    public function testUpsertJoinsAnOuterTransactionAndDoesNotCommitIt(): void
    {
        $db = DB::connection('manticore');
        $db->beginTransaction();
        $this->assertSame(2, $this->writesQuery()->upsert([['id' => 1, 'title' => 'pending'], ['id' => 2, 'title' => 'pending']], 'id'));
        $this->assertSame(1, $db->transactionLevel());
        $this->assertSame('old phone', $this->writesQuery()->find(1)->title);
        $this->assertNull($this->writesQuery()->find(2));
        $db->rollBack();
        $this->assertSame('old phone', $this->writesQuery()->find(1)->title);
        $this->assertNull($this->writesQuery()->find(2));
    }

    public function testAmbiguousKeysAndInvalidBatchesCannotPartiallyChangeDocuments(): void
    {
        $this->writesQuery()->insert(['id' => 2, 'sku' => 'A', 'title' => 'second']);
        try {
            $this->writesQuery()->upsert([['sku' => 'B', 'stock' => 1], ['sku' => 'A', 'stock' => 8]], 'sku', ['stock']);
            $this->fail('An ambiguous key was accepted.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('multiple documents', $error->getMessage());
        }
        $this->assertSame(2, $this->writesQuery()->count());
        $this->assertSame(5, $this->writesQuery()->find(1)->stock);
        try {
            $this->writesQuery()->upsert([['id' => 1, 'stock' => 9], ['id' => 3, 'unknown' => 1]], 'id');
            $this->fail('An invalid batch was accepted.');
        } catch (InvalidArgumentException $error) {
            $this->assertNotSame('', $error->getMessage());
        }
        $this->assertSame(5, $this->writesQuery()->find(1)->stock);
    }

    public function testUnstoredFieldsMustBeSuppliedWhenTheyCannotBePreserved(): void
    {
        $db = DB::connection('manticore');
        $name = $this->tableName('unstored');
        try {
            $db->getSchemaBuilder()->create($name, function ($table) {
                $table->text('title')->stored(false)->indexed(true);
                $table->integer('stock');
            });
            $db->table($name)->insert(['id' => 1, 'title' => 'hidden', 'stock' => 2]);
            try {
                $db->table($name)->upsert(['id' => 1, 'stock' => 9], 'id');
                $this->fail('An unstored field was silently lost.');
            } catch (LogicException $error) {
                $this->assertStringContainsString('unstored full-text field: title', $error->getMessage());
            }
            $this->assertSame(2, $db->table($name)->find(1)->stock);
            $this->assertSame(1, $db->table($name)->upsert(['id' => 1, 'title' => 'changed', 'stock' => 9], 'id'));
            $this->assertSame(1, $db->table($name)->match('changed')->first()->id);
        } finally {
            $db->getSchemaBuilder()->dropIfExists($name);
        }
    }

    public function testUpsertPreservesStructuredDataWhenReadCastsAreDisabled(): void
    {
        config(['database.connections.manticore.cast_attributes' => false]);
        DB::purge('manticore');
        $this->assertSame(1, $this->writesQuery()->upsert(['id' => 1, 'stock' => 9], 'id'));
        $raw = $this->writesQuery()->find(1);
        $this->assertSame(9, (int) $raw->stock);
        $this->assertInstanceOf(\stdClass::class, json_decode($raw->details)->empty);
        $this->assertSame('2,9007199254740993', $raw->tags);
        $this->assertEquals([0.25, 1, 2], array_map('floatval', explode(',', $raw->embedding)));
    }

    public function testEmptyUpdateColumnsInsertOnlyAndDoNotReplaceExistingIds(): void
    {
        $this->assertSame(2, $this->writesQuery()->upsert([['id' => 2, 'title' => 'two'], ['id' => 3, 'title' => 'three']], 'id', []));
        try {
            $this->writesQuery()->upsert(['id' => 1, 'title' => 'must not replace'], 'id', []);
            $this->fail('A duplicate ID was silently replaced.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('duplicate', strtolower($error->getMessage()));
        }
        $this->assertSame('old phone', $this->writesQuery()->find(1)->title);
        $this->assertSame(3, $this->writesQuery()->count());
    }

    public function testAnUpsertFailureAbortsTheWholeOuterTransaction(): void
    {
        $db = DB::connection('manticore');
        $db->beginTransaction();
        $this->writesQuery()->insert(['id' => 3, 'title' => 'earlier pending insert']);
        $writes = 0;
        DB::listen(function (QueryExecuted $event) use (&$writes) {
            if (preg_match('/^(?:insert|replace) into/', $event->sql) && ++$writes === 2) {
                throw new RuntimeException('Outer transaction write failure');
            }
        });
        try {
            $this->writesQuery()->upsert([['id' => 1, 'title' => 'pending'], ['id' => 2, 'title' => 'pending']], 'id');
            $this->fail('The injected failure did not propagate.');
        } catch (RuntimeException $error) {
            $this->assertSame('Outer transaction write failure', $error->getMessage());
        }
        $this->assertSame(0, $db->transactionLevel());
        $this->assertSame('old phone', $this->writesQuery()->find(1)->title);
        $this->assertNull($this->writesQuery()->find(2));
        $this->assertNull($this->writesQuery()->find(3));
    }

    public function testConcurrentInsertConflictRollsBackOurChangesAndKeepsTheOtherWriter(): void
    {
        config(['database.connections.manticore_racer' => config('database.connections.manticore')]);
        $other = DB::connection('manticore_racer');
        $inserted = false;
        DB::listen(function (QueryExecuted $event) use (&$inserted, $other) {
            if (!$inserted && $event->connectionName === 'manticore' && str_starts_with($event->sql, 'select ')
                && $event->bindings === [2]) {
                $inserted = true;
                $other->table($this->writeTable)->insert(['id' => 2, 'title' => 'concurrent insert']);
            }
        });
        try {
            $this->writesQuery()->upsert([['id' => 1, 'title' => 'our change'], ['id' => 2, 'title' => 'our insert']], 'id');
            $this->fail('The concurrent INSERT conflict was ignored.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('duplicate', strtolower($error->getMessage()));
        } finally {
            DB::purge('manticore_racer');
        }
        $this->assertTrue($inserted);
        $this->assertSame('old phone', $this->writesQuery()->find(1)->title);
        $this->assertSame('concurrent insert', $this->writesQuery()->find(2)->title);
        $this->assertSame(0, DB::connection('manticore')->transactionLevel());
    }
}
