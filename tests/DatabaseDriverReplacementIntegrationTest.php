<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

class DatabaseDriverReplacementIntegrationTest extends DatabaseTestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('database.connections.manticore', [
            'driver' => 'manticore', 'host' => $this->serverHost(), 'port' => $this->serverPort(), 'prefix' => 'app_',
        ]);
    }

    public function testSchemaCloneAndReplacementHaveObservableGapAndRetainBothGenerations(): void
    {
        $this->requiresServer();
        $db = DB::connection('manticore');
        $schema = $db->getSchemaBuilder();
        $active = $this->tableName('active');
        $staged = $active.'_new';
        $backup = $active.'_old';
        $process = null;
        $stopFile = null;
        $pipes = [];
        try {
            $schema->create($active, function ($table) {
                $table->text('title');
                $table->json('details');
                $table->multi('tags');
                $table->floatVector('embedding', 3, 'cosine');
                $table->setting('min_infix_len', 2);
            });
            $db->table($active)->insert(['id' => 1, 'title' => 'old', 'details' => ['kept' => true], 'tags' => [1], 'embedding' => [1, 0, 0]]);
            $schema->createLike($staged, $active);
            $this->assertSame(0, $db->table($staged)->count());
            $this->assertSame($schema->getColumns($active), $schema->getColumns($staged));
            $this->assertSame($schema->getSettings($active), $schema->getSettings($staged));
            $this->assertSame(['dimensions' => 3, 'metric' => 'cosine'], $schema->getVectorDefinition($staged, 'embedding'));
            $db->table($staged)->insert(['id' => 1, 'title' => 'new', 'details' => ['kept' => false], 'tags' => [2], 'embedding' => [0, 1, 0]]);
            $replacement = $schema->replacement($active, $staged, $backup);
            $this->assertSame('prepared', $replacement->inspect()->status);
            try {
                $replacement->switch(false);
                $this->fail('Maintenance acknowledgement must be required.');
            } catch (\LogicException $error) {
                $this->assertStringContainsString('lock', $error->getMessage());
            }
            $stopFile = tempnam(sys_get_temp_dir(), 'mc_reader_');
            unlink($stopFile);
            $process = proc_open([PHP_BINARY, '-d', 'xdebug.mode=off', __DIR__.'/fixtures/index-replacement-reader.php',
                $this->serverHost(), (string)$this->serverPort(), 'app_'.$active, $stopFile], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            stream_set_timeout($pipes[1], 20);
            $this->assertSame("READY\n", fgets($pipes[1]));
            DB::listen(function (QueryExecuted $event) {
                if (str_contains($event->sql, ' rename ')) { usleep(250000); }
            });
            $this->assertSame('switched', $replacement->switch(true)->status);
            $this->assertSame('new', $db->table($active)->find(1)->title);
            $this->assertSame('old', $db->table($backup)->find(1)->title);
            $this->assertSame('switched', $replacement->switch(true)->status);
            $this->assertSame('prepared', $replacement->rollback(true)->status);
            $this->assertSame('old', $db->table($active)->find(1)->title);
            $this->assertSame('new', $db->table($staged)->find(1)->title);
            touch($stopFile);
            fclose($pipes[0]);
            unset($pipes[0]);
            $observed = json_decode(trim(stream_get_contents($pipes[1])), true, 512, JSON_THROW_ON_ERROR);
            $this->assertContains('old', $observed);
            $this->assertContains('new', $observed);
            $this->assertContains('gap', $observed);
        } finally {
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            if ($stopFile !== null) { @unlink($stopFile); }
            foreach ([$active, $staged, $backup] as $name) { $schema->dropIfExists($name); }
        }
    }

    public function testNewProcessCanResumeOrRollBackAfterAnInjectedFailure(): void
    {
        $this->requiresServer();
        $db = DB::connection('manticore');
        $schema = $db->getSchemaBuilder();
        $active = $this->tableName('recovery');
        $staged = $active.'_new';
        $backup = $active.'_old';
        try {
            $schema->create($active, fn ($table) => $table->text('title'));
            $schema->createLike($staged, $active);
            $db->table($active)->insert(['id' => 1, 'title' => 'old']);
            $db->table($staged)->insert(['id' => 1, 'title' => 'new']);
            $fail = true;
            DB::listen(function (QueryExecuted $event) use (&$fail) {
                if ($fail && str_contains($event->sql, ' rename ')) {
                    $fail = false;
                    throw new \RuntimeException('Injected failure after first DDL');
                }
            });
            try {
                $schema->replacement($active, $staged, $backup)->switch(true);
                $this->fail('Injected interruption was hidden.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('Injected failure', $error->getMessage());
            }
            $replacement = $schema->replacement($active, $staged, $backup);
            $this->assertSame('gap', $replacement->inspect()->status);
            $this->assertSame('prepared', $replacement->rollback(true)->status);
            $this->assertSame('old', $db->table($active)->find(1)->title);
            $fail = true;
            try { $replacement->switch(true); } catch (\RuntimeException $error) { $this->assertStringContainsString('Injected failure', $error->getMessage()); }
            $this->assertSame('switched', $schema->replacement($active, $staged, $backup)->switch(true)->status);
            // Interrupt rollback after the new generation has been saved to staged.
            $fail = true;
            try { $replacement->rollback(true); } catch (\RuntimeException $error) { $this->assertStringContainsString('Injected failure', $error->getMessage()); }
            $this->assertSame('gap', $replacement->inspect()->status);
            $this->assertSame('prepared', $schema->replacement($active, $staged, $backup)->rollback(true)->status);
            $this->assertSame('new', $db->table($staged)->find(1)->title);
        } finally {
            foreach ([$active, $staged, $backup] as $name) { $schema->dropIfExists($name); }
        }
    }

    public function testConflictingAndDistributedLayoutsAreRejectedWithoutDeletingTables(): void
    {
        $this->requiresServer();
        $db = DB::connection('manticore');
        $schema = $db->getSchemaBuilder();
        $active = $this->tableName('conflict');
        try {
            foreach ([$active, $active.'_new', $active.'_old'] as $name) {
                $schema->create($name, fn ($table) => $table->text('title'));
            }
            $replacement = $schema->replacement($active, $active.'_new', $active.'_old');
            $this->assertSame('conflict', $replacement->inspect()->status);
            try { $replacement->switch(true); $this->fail('Conflict was accepted.'); }
            catch (\LogicException $error) { $this->assertStringContainsString('Conflicting', $error->getMessage()); }
            $this->assertTrue($schema->hasTable($active.'_old'));
            $schema->drop($active.'_old');
            $db->statement("CREATE TABLE `app_".$active."_old` type='distributed' local='app_".$active."'");
            $this->expectException(\avadim\Manticore\Laravel\Database\UnsupportedFeatureException::class);
            $replacement->inspect();
        } finally {
            foreach ([$active.'_old', $active.'_new', $active] as $name) { $schema->dropIfExists($name); }
        }
    }

    public function testServerFailureBetweenStepsPreservesAllTablesAndOriginalException(): void
    {
        $this->requiresServer();
        $db = DB::connection('manticore');
        $schema = $db->getSchemaBuilder();
        $active = $this->tableName('server_failure');
        $staged = $active.'_new';
        $backup = $active.'_old';
        try {
            $schema->create($active, fn ($table) => $table->text('title'));
            $schema->createLike($staged, $active);
            $db->table($active)->insert(['id' => 1, 'title' => 'old']);
            $db->table($staged)->insert(['id' => 1, 'title' => 'new']);
            $armed = false;
            $once = false;
            DB::listen(function (QueryExecuted $event) use (&$armed, &$once, $schema, $active) {
                if (!$once && str_contains($event->sql, ' rename ')) { $armed = true; }
                if ($armed && !$once && $event->sql === 'SHOW TABLES') {
                    $once = true;
                    $schema->create($active, fn ($table) => $table->text('title'));
                }
            });
            $replacement = $schema->replacement($active, $staged, $backup);
            try {
                $replacement->switch(true);
                $this->fail('Conflicting second rename was accepted.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString('rename', $error->getSql());
                $this->assertInstanceOf(\PDOException::class, $error->getPrevious());
            }
            $this->assertSame('conflict', $replacement->inspect()->status);
            $this->assertSame('old', $db->table($backup)->find(1)->title);
            $this->assertSame('new', $db->table($staged)->find(1)->title);
            // Only the test/application removes the unexpected empty occupier after inspection.
            $schema->drop($active);
            $this->assertSame('switched', $replacement->switch(true)->status);
        } finally {
            foreach ([$active, $staged, $backup] as $name) { $schema->dropIfExists($name); }
        }
    }

    public function testUnsupportedConnectionScopeIsRejectedWithoutOpeningPdo(): void
    {
        $db = new \avadim\Manticore\Laravel\Database\ManticoreConnection(
            fn () => throw new \RuntimeException('Unexpected network'), '', '', ['read' => ['host' => 'other']]);
        $this->expectException(\avadim\Manticore\Laravel\Database\UnsupportedFeatureException::class);
        $db->getSchemaBuilder()->replacement('active', 'staged', 'backup')->inspect();
    }
}
