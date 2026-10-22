<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Database\ManticoreConnection;
use avadim\Manticore\Laravel\Database\ServerCapabilities;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseDriverCapabilitiesTest extends DatabaseTestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('database.connections.manticore', [
            'driver' => 'manticore', 'host' => $this->serverHost(), 'port' => $this->serverPort(),
        ]);
    }

    public function testStatusParsingDoesNotClaimOperationalSupport(): void
    {
        $caps = new ServerCapabilities(['version' => '28.6.6 abc (knn 13.8.3 abc) (buddy v4.2.0)', 'cluster_demo_status' => 'synced']);
        $this->assertSame('28.6.6', $caps->version);
        $this->assertTrue($caps->advertises('knn'));
        $this->assertFalse($caps->advertises('embeddings'));
        $this->assertTrue($caps->hasClusters);
        $offline = new ManticoreConnection(fn () => throw new \LogicException('No network'));
        $this->expectException(\LogicException::class);
        $offline->pretend(fn () => $offline->capabilities());
    }

    public function testDiscoveryAndProbesAreConnectionLocalAndResetOnReconnectAndDdl(): void
    {
        $this->requiresServer();
        $db = DB::connection('manticore');
        $name = $this->tableName('capabilities');
        $schema = $db->getSchemaBuilder();
        try {
            $schema->create($name, function ($table) {
                $table->text('title');
                $table->floatVector('embedding', 3, 'cosine');
                $table->setting('min_infix_len', 2);
            });
            $db->table($name)->insert(['id' => 1, 'title' => 'galaxy', 'embedding' => [1, 0, 0]]);
            $caps = $db->capabilities();
            $this->assertTrue($caps->advertises('knn'));
            $this->assertSame($caps, $db->capabilities());
            $knn = $db->probeCapability('knn', $name, ['vector' => [1, 0, 0]]);
            $this->assertTrue($knn->supported);
            $this->assertSame($knn, $db->probeCapability('knn', $name, ['vector' => [1, 0, 0]]));
            foreach (['keywords', 'suggest', 'qsuggest', 'autocomplete', 'fuzzy'] as $feature) {
                $this->assertTrue($db->probeCapability($feature, $name, ['text' => 'galaxy'])->supported, $feature);
            }
            $this->assertSame(['dimensions' => 3, 'metric' => 'cosine'], $schema->getVectorDefinition($name, 'embedding'));
            $schema->table($name, fn ($table) => $table->integer('stock'));
            $this->assertNotSame($knn, $db->probeCapability('knn', $name, ['vector' => [1, 0, 0]]));
            $this->assertFalse($db->probeCapability('knn', $name, ['column' => 'stock', 'vector' => [1, 0, 0]])->supported);
            config(['database.connections.other_search' => config('database.connections.manticore')]);
            $this->assertNotSame($caps, DB::connection('other_search')->capabilities());
            DB::reconnect('manticore');
            $this->assertNotSame($caps, $db->capabilities());
            $caps = $db->capabilities();
            DB::purge('manticore');
            $this->assertNotSame($caps, DB::connection('manticore')->capabilities());
        } finally {
            Schema::connection('manticore')->dropIfExists($name);
        }
    }

    public function testProbeDoesNotHideMissingTableOrBadVector(): void
    {
        $this->requiresServer();
        $db = DB::connection('manticore');
        $name = $this->tableName('bad_probe');
        $schema = $db->getSchemaBuilder();
        try {
            $schema->create($name, fn ($table) => $table->floatVector('embedding', 3, 'cosine'));
            foreach ([[1, 0], [0, 0, 0], [INF, 1, 1]] as $vector) {
                try {
                    $db->probeCapability('knn', $name, ['vector' => $vector]);
                    $this->fail('Invalid vector accepted.');
                } catch (\InvalidArgumentException $error) {
                    $this->assertNotSame('', $error->getMessage());
                }
            }
            $this->expectException(QueryException::class);
            $db->probeCapability('keywords', $name.'_missing');
        } finally {
            $schema->dropIfExists($name);
        }
    }

    public function testOnlyExplicitDependencyErrorsBecomeNegativeEvidence(): void
    {
        $db = new class(fn () => throw new \LogicException('No PDO')) extends ManticoreConnection {
            public QueryException $failure;
            public int $calls = 0;
            public function keywords(string $text, string $table, array $options = []): \Illuminate\Support\Collection
            {
                $this->calls++;
                throw $this->failure;
            }
        };
        $db->failure = new QueryException('manticore', 'CALL KEYWORDS(?, ?)', ['probe', 'docs'], new \PDOException('requires Manticore Buddy'));
        $evidence = $db->probeCapability('keywords', 'docs');
        $this->assertFalse($evidence->supported);
        $this->assertSame($db->failure, $evidence->cause);
        $this->assertSame($evidence, $db->probeCapability('keywords', 'docs'));
        $this->assertSame(1, $db->calls);
        $db->forgetSchema();
        $db->failure = new QueryException('manticore', 'CALL KEYWORDS(?, ?)', ['probe', 'docs'], new \PDOException('no such table docs'));
        foreach ([1, 2] as $attempt) {
            try {
                $db->probeCapability('keywords', 'docs');
                $this->fail('Query error was hidden.');
            } catch (QueryException $error) {
                $this->assertSame($db->failure, $error);
            }
        }
        $this->assertSame(3, $db->calls);
    }
}
