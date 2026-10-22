<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseDriverSchemaIntegrationTest extends DatabaseTestCase
{
    private ?string $schemaTable = null;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('database.connections.manticore', [
            'driver' => 'manticore', 'host' => $this->serverHost(), 'port' => $this->serverPort(), 'timeout' => 3,
        ]);
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresServer();
        $this->schemaTable = $this->tableName('schema');
    }

    private function createTable(): void
    {
        Schema::connection('manticore')->create($this->schemaTable, function ($table) {
            $table->id();
            $table->text('title');
            $table->json('info');
            $table->multi('tags');
            $table->multi64('groups');
            $table->floatVector('embedding');
            $table->boolean('active');
            $table->timestamp('published_at');
            $table->float('price');
        });
    }

    protected function tearDown(): void
    {
        try {
            if ($this->schemaTable !== null) {
                Schema::connection('manticore')->dropIfExists($this->schemaTable);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testCreateDescribeAlterAndDropUseTheSchemaFacade(): void
    {
        $schema = Schema::connection('manticore');
        $this->assertFalse($schema->hasTable($this->schemaTable));
        $this->createTable();
        $this->assertTrue($schema->hasTable($this->schemaTable));
        $this->assertTrue($schema->hasColumns($this->schemaTable, ['id', 'info', 'tags', 'embedding']));
        $this->assertSame('json', $schema->getColumnType($this->schemaTable, 'info'));
        $schema->table($this->schemaTable, function ($table) { $table->integer('qty'); $table->text('summary'); });
        $this->assertTrue($schema->hasColumns($this->schemaTable, ['qty', 'summary']));
        $schema->table($this->schemaTable, fn ($table) => $table->dropColumn(['qty', 'summary']));
        $this->assertFalse($schema->hasColumn($this->schemaTable, 'qty'));
        $schema->drop($this->schemaTable);
        $this->assertFalse($schema->hasTable($this->schemaTable));
        $schema->dropIfExists($this->schemaTable);
    }

    public function testAlterSettingsAreReadableAndApplyToNewDocuments(): void
    {
        $schema = Schema::connection('manticore');
        $schema->create($this->schemaTable, function ($table) {
            $table->text('title');
            $table->setting('min_infix_len', 2);
        });
        $db = DB::connection('manticore');
        $query = $db->table($this->schemaTable);
        $query->insert(['id' => 1, 'title' => 'running']);
        $this->assertSame('2', $schema->getSettings($this->schemaTable)['min_infix_len']);
        $schema->table($this->schemaTable, function ($table) {
            $table->setting('min_infix_len', 3)->setting('morphology', 'stem_en');
            $table->integer('qty');
            $table->setting('rt_mem_limit', '32M');
        });
        $settings = $schema->getSettings($this->schemaTable);
        $this->assertSame('3', $settings['min_infix_len']);
        $this->assertSame('stem_en', $settings['morphology']);
        $this->assertSame('33554432', $settings['rt_mem_limit']);
        $this->assertTrue($schema->hasColumn($this->schemaTable, 'qty'));
        $query->insert(['id' => 2, 'title' => 'running', 'qty' => 7]);
        $this->assertSame([1, 2], (clone $query)->orderBy('id')->pluck('id')->all());
        $this->assertSame([2], (clone $query)->match('run')->pluck('id')->all());
        $db->enableQueryLog();
        $schema->getSettings($this->schemaTable);
        $schema->getSettings($this->schemaTable);
        $this->assertCount(2, $db->getQueryLog());
    }

    public function testStructuredAttributesRoundTripThroughAllReadPaths(): void
    {
        $this->createTable();
        $query = DB::connection('manticore')->table($this->schemaTable);
        // JSON is deliberately first: an associative row with an array must not become a batch.
        $query->insert([
            'info' => ['text' => 'Привет', 'nested' => ['enabled' => true]], 'id' => 1, 'title' => 'galaxy',
            'tags' => [1, 2], 'groups' => [9007199254740993], 'embedding' => [1.5, -2.25, 0],
            'active' => true, 'published_at' => new DateTimeImmutable('@1700000000'), 'price' => 12.75,
        ]);
        foreach ([(clone $query)->first(), $query->search()->rows()->first(), $query->cursor()->first(), (clone $query)->orderBy('id')->lazy()->first()] as $row) {
            $this->assertSame(['text' => 'Привет', 'nested' => ['enabled' => true]], $row->info);
            $this->assertSame([1, 2], $row->tags);
            $this->assertSame([9007199254740993], $row->groups);
            $this->assertSame([1.5, -2.25, 0.0], $row->embedding);
            $this->assertTrue($row->active);
            $this->assertSame(1700000000, $row->published_at);
            $this->assertSame(12.75, $row->price);
        }
        $this->assertSame([['text' => 'Привет', 'nested' => ['enabled' => true]]], $query->pluck('info')->all());
        $alias = (clone $query)->select('info as details', 'tags as categories')->first();
        $this->assertIsArray($alias->details);
        $this->assertSame([1, 2], $alias->categories);
    }

    public function testStructuredAttributesCanBeUpdated(): void
    {
        $this->createTable();
        $query = DB::connection('manticore')->table($this->schemaTable);
        $query->insert(['id' => 1, 'title' => 'galaxy', 'tags' => [1]]);
        $this->assertSame(1, (clone $query)->where('id', 1)->update(['tags' => [2, 3], 'price' => 14.25]));
        $row = (clone $query)->find(1);
        $this->assertSame([2, 3], $row->tags);
        $this->assertSame(14.25, $row->price);
    }

    public function testFailedAlterKeepsAppliedColumnsAndRefreshesTheSchema(): void
    {
        $this->createTable();
        $schema = Schema::connection('manticore');
        $this->assertFalse($schema->hasColumn($this->schemaTable, 'qty'));
        try {
            $schema->table($this->schemaTable, function ($table) {
                $table->integer('qty');
                $table->dropColumn('missing_column');
            });
            $this->fail('The second ALTER must fail.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('missing_column', $error->getSql());
        }
        $this->assertTrue($schema->hasColumn($this->schemaTable, 'qty'));
        $schema->table($this->schemaTable, fn ($table) => $table->dropColumn('qty'));
        $this->assertFalse($schema->hasColumn($this->schemaTable, 'qty'));
    }

    public function testReplaceResetsOmittedFieldsAndAcceptsStructuredBatchRows(): void
    {
        $this->createTable();
        $query = DB::connection('manticore')->table($this->schemaTable);
        $query->insert(['id' => 1, 'title' => 'before', 'info' => ['a' => 1], 'tags' => [1], 'price' => 5]);
        $this->assertTrue($query->replace(['id' => 1, 'title' => 'after']));
        $row = (clone $query)->find(1);
        $this->assertSame('after', $row->title);
        $this->assertSame([], $row->tags);
        $this->assertNull($row->info);
        $this->assertSame(0.0, $row->price);
        $this->assertTrue($query->replace([
            ['id' => 1, 'title' => 'first', 'info' => ['x' => 1], 'tags' => [2]],
            ['tags' => [3], 'info' => ['x' => 2], 'title' => 'second', 'id' => 2],
        ]));
        $this->assertSame([['x' => 1], ['x' => 2]], (clone $query)->orderBy('id')->pluck('info')->all());
        $this->assertSame(2, $query->replaceGetId(['id' => 2, 'title' => 'replaced']));
    }

    public function testSchemaCacheIsSharedInvalidatedAndLoadedBeforeSearch(): void
    {
        $this->createTable();
        $db = DB::connection('manticore');
        $db->enableQueryLog();
        $db->flushQueryLog();
        $db->table($this->schemaTable)->insert(['id' => 1, 'title' => 'galaxy', 'tags' => []]);
        $db->table($this->schemaTable)->get();
        $db->table($this->schemaTable)->get();
        $describes = fn () => array_values(array_filter($db->getQueryLog(), fn ($entry) => str_starts_with($entry['query'], 'DESCRIBE ')));
        $this->assertCount(1, $describes());
        $db->forgetSchema($this->schemaTable);
        $db->flushQueryLog();
        $result = $db->table($this->schemaTable)->match('galaxy')->search();
        $this->assertSame(1, $result->meta()->totalFound());
        $this->assertStringStartsWith('DESCRIBE ', $db->getQueryLog()[0]['query']);
        Schema::connection('manticore')->table($this->schemaTable, fn ($table) => $table->multi('new_tags'));
        $this->assertTrue(Schema::connection('manticore')->hasColumn($this->schemaTable, 'new_tags'));
        $db->statement('ALTER TABLE `'.$this->schemaTable.'` DROP COLUMN new_tags');
        $this->assertFalse(Schema::connection('manticore')->hasColumn($this->schemaTable, 'new_tags'));
        DB::reconnect('manticore');
        $db->flushQueryLog();
        $db->table($this->schemaTable)->get();
        $this->assertCount(1, $describes());
    }

    public function testMigrationsUseTheMainSqlDatabaseForTheirRepository(): void
    {
        config(['manticore_driver_migration_table' => $this->schemaTable]);
        $repository = $this->app->make('migration.repository');
        $repository->createRepository();
        $migrator = $this->app->make('migrator');
        $paths = [__DIR__.'/fixtures/driver-migrations'];
        $migrator->run($paths);
        $this->assertTrue(Schema::connection('manticore')->hasTable($this->schemaTable));
        $this->assertCount(1, $repository->getRan());
        $this->assertSame('sqlite', $repository->getConnection()->getDriverName());
        $migrator->rollback($paths);
        $this->assertFalse(Schema::connection('manticore')->hasTable($this->schemaTable));
        $this->assertSame([], $repository->getRan());
    }

    public function testSchemaPrefixesAndCastingCanBeConfiguredPerConnection(): void
    {
        $baseConfig = config('database.connections.manticore');
        config(['database.connections.prefixed_search' => $baseConfig + ['prefix' => 'app_']]);
        $schema = Schema::connection('prefixed_search');
        try {
            $schema->create($this->schemaTable, fn ($table) => $table->json('info'));
            $db = DB::connection('prefixed_search');
            $db->table($this->schemaTable)->insert(['id' => 1, 'info' => ['enabled' => true]]);
            $this->assertTrue($schema->hasTable($this->schemaTable));
            $this->assertSame(['enabled' => true], $db->table($this->schemaTable)->value('info'));
            config(['database.connections.raw_search' => $baseConfig + ['prefix' => 'app_', 'cast_attributes' => false]]);
            $raw = DB::connection('raw_search');
            $raw->enableQueryLog();
            $this->assertIsString($raw->table($this->schemaTable)->value('info'));
            $this->assertCount(1, $raw->getQueryLog());
        } finally {
            $schema->dropIfExists($this->schemaTable);
        }
    }
}
