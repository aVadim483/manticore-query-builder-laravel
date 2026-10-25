<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;

class DatabaseDriverHybridIntegrationTest extends DatabaseTestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('database.connections.manticore', [
            'driver' => 'manticore', 'host' => $this->serverHost(), 'port' => $this->serverPort(),
        ]);
    }

    public function testIndependentBranchesShareFiltersCaptureMetadataAndDoNotChangeTheSourceQuery(): void
    {
        $this->requiresServer();
        $db = DB::connection('manticore');
        $schema = $db->getSchemaBuilder();
        $name = $this->tableName('hybrid');
        try {
            $schema->create($name, function ($table) {
                $table->text('title');
                $table->integer('stock');
                $table->floatVector('embedding', 3, 'l2');
            });
            $db->table($name)->insert([
                ['id' => 1, 'title' => 'phone', 'stock' => 1, 'embedding' => [0, 0, 0]],
                ['id' => 2, 'title' => 'phone', 'stock' => 1, 'embedding' => [10, 0, 0]],
                ['id' => 3, 'title' => 'semantic', 'stock' => 1, 'embedding' => [1, 0, 0]],
                ['id' => 4, 'title' => 'phone', 'stock' => 0, 'embedding' => [0, 0, 0]],
            ]);
            $common = $db->table($name)->where('stock', 1);
            $sql = $common->toSql();
            $bindings = $common->getBindings();
            $guard = false;
            DB::listen(function (QueryExecuted $event) use ($db, &$guard, $name) {
                if (!$guard && str_contains($event->sql, '__hybrid_')) {
                    $guard = true;
                    try { $db->select('SELECT id FROM `'.$name.'` WHERE id=999'); } finally { $guard = false; }
                }
            });
            $result = $common->hybridSearch('phone', 'embedding', [0, 0, 0], ['text_candidates' => 2, 'vector_candidates' => 2]);
            $this->assertSame([1, 2, 3], $result->hits()->pluck('id')->all());
            $this->assertSame(2, $result->textMeta->totalFound());
            // RAM KNN may report more matches than its returned top-k window.
            $this->assertGreaterThanOrEqual(2, $result->vectorMeta->totalFound());
            $this->assertSame(3, $result->windowTotal());
            $this->assertNull($result->totalFound());
            $this->assertSame($sql, $common->toSql());
            $this->assertSame($bindings, $common->getBindings());
            $this->assertSame([1, 2], $result->paginateWindow(2)->getCollection()->pluck('id')->all());
            $this->assertSame([3], $result->paginateWindow(2, 2)->getCollection()->pluck('id')->all());
            $emptyText = $common->hybridSearch('missingword', 'embedding', [0, 0, 0], ['vector_candidates' => 2]);
            $this->assertSame(0, $emptyText->textMeta->totalFound());
            $this->assertSame([1, 3], $emptyText->hits()->pluck('id')->all());
            $this->assertSame([1, 2], (clone $common)->knn('embedding', [0, 0, 0], 2)->match('phone')->orderByKnnDistance()->pluck('id')->all());
            // Force a vector-only failure after a successful text search; it must propagate.
            $once = false;
            DB::listen(function (QueryExecuted $event) use ($db, $name, &$once) {
                if (!$once && str_contains($event->sql, '__hybrid_text_score')) {
                    $once = true;
                    $db->statement('DROP TABLE `'.$name.'`');
                }
            });
            $this->expectException(QueryException::class);
            $common->hybridSearch('phone', 'embedding', [0, 0, 0]);
        } finally {
            $schema->dropIfExists($name);
        }
    }

    public function testTerminalFamilyExecutesImmediatelyAndFirstRanksTheCompleteWindows(): void
    {
        $this->requiresServer();
        $db = DB::connection('manticore');
        $schema = $db->getSchemaBuilder();
        $name = $this->tableName('hybrid_terminal');
        try {
            $schema->create($name, function ($table) {
                $table->text('title');
                $table->integer('stock');
                $table->floatVector('embedding', 3, 'l2');
            });
            $db->table($name)->insert([
                ['id' => 1, 'title' => 'phone', 'stock' => 1, 'embedding' => [10, 0, 0]],
                ['id' => 2, 'title' => 'phone', 'stock' => 1, 'embedding' => [1, 0, 0]],
                ['id' => 3, 'title' => 'semantic', 'stock' => 1, 'embedding' => [0, 0, 0]],
                ['id' => 4, 'title' => 'phone', 'stock' => 0, 'embedding' => [0, 0, 0]],
            ]);
            $common = $db->table($name)->where('stock', 1);
            $originalSql = $common->toSql();
            $originalBindings = $common->getBindings();
            $options = ['text_candidates' => 2, 'vector_candidates' => 2];
            $referenceQueries = null;
            $referenceRows = null;
            $db->enableQueryLog();
            foreach (['hybridSearch', 'hybridGet', 'hybridFirst'] as $method) {
                $db->flushQueryLog();
                $result = $common->$method('phone', 'embedding', [0, 0, 0], $options);
                if ($method === 'hybridSearch') {
                    $this->assertInstanceOf(\avadim\Manticore\Laravel\Database\HybridResult::class, $result);
                    $this->assertSame([2, 1, 3], $result->hits()->pluck('id')->all());
                    $this->assertSame(2, $result->hits()[0]->textRank);
                    $this->assertSame(2, $result->hits()[0]->vectorRank);
                    $this->assertSame(3, $result->windowTotal());
                    $this->assertSame(2, $result->textMeta->totalFound());
                    $this->assertGreaterThanOrEqual(2, $result->vectorMeta->totalFound());
                    $this->assertNull($result->totalFound());
                    $referenceRows = $result->rows()->map(static fn ($row) => (array)$row)->all();
                } elseif ($method === 'hybridGet') {
                    $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
                    $this->assertSame([2, 1, 3], $result->pluck('id')->all());
                    foreach ($result as $row) { $this->assertInstanceOf(\stdClass::class, $row); }
                    $this->assertSame($referenceRows, $result->map(static fn ($row) => (array)$row)->all());
                } else {
                    $this->assertInstanceOf(\stdClass::class, $result);
                    $this->assertSame(2, $result->id);
                    $this->assertSame($referenceRows[0], (array)$result);
                }
                $queries = array_values(array_filter($db->getQueryLog(), static fn ($entry) =>
                    str_contains($entry['query'], '__hybrid_text_score') || str_contains($entry['query'], '__hybrid_distance')));
                $this->assertCount(2, $queries, $method.' must execute both searches before returning.');
                $queries = array_map(static fn ($entry) => [$entry['query'], $entry['bindings']], $queries);
                $referenceQueries ??= $queries;
                $this->assertSame($referenceQueries, $queries, 'First must not reduce candidate limits.');
                $this->assertSame($originalSql, $common->toSql());
                $this->assertSame($originalBindings, $common->getBindings());
                $empty = $db->table($name)->where('stock', 99)->$method('phone', 'embedding', [0, 0, 0], $options);
                if ($method === 'hybridSearch') {
                    $this->assertInstanceOf(\avadim\Manticore\Laravel\Database\HybridResult::class, $empty);
                    $this->assertTrue($empty->rows()->isEmpty());
                    $this->assertSame(0, $empty->windowTotal());
                } elseif ($method === 'hybridGet') {
                    $this->assertInstanceOf(\Illuminate\Support\Collection::class, $empty);
                    $this->assertTrue($empty->isEmpty());
                } else {
                    $this->assertNull($empty);
                }
            }
        } finally {
            $schema->dropIfExists($name);
        }
    }
}
