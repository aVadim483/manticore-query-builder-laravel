<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Facade;
use avadim\Manticore\Laravel\Manager;
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\QueryBuilder\Connection;
use ReflectionProperty;

/**
 * The Laravel layer only forwards calls, so every method added to the query builder must be
 * reachable through both entry points without any change here. These tests guard that contract
 * for the API added after v1.15.0 (imperative ALTER statements and the connection schema cache).
 */
class UpstreamCompatibilityTest extends TestCase
{
    /**
     * @var string|null
     */
    protected $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresServer();

        $this->table = $this->tableName('compat');

        ManticoreDb::create($this->table, [
            'title' => 'text',
            'price' => 'float',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->table) {
            ManticoreDb::dropIfExists($this->table);
            $this->table = null;
        }

        parent::tearDown();
    }

    /**
     * @return Connection
     */
    protected function connection(): Connection
    {
        return $this->app->make('manticore')->connection();
    }

    /**
     * Tables whose schema the connection has cached.
     * The pool is private and DESCRIBE is sent as a service query, i.e. it is not logged,
     * so reflection is the only way to observe it.
     *
     * @param Connection $connection
     *
     * @return array
     */
    protected function cachedSchemas(Connection $connection): array
    {
        $property = new ReflectionProperty(Connection::class, 'schemaPool');
        $property->setAccessible(true);

        return array_keys($property->getValue($connection));
    }

    public function testAlterMethodsAreProxiedByTheManager(): void
    {
        $result = Facade::addColumn($this->table, 'qty', 'integer');
        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertArrayHasKey('qty', Facade::tableDescribe($this->table));

        $this->assertTrue(Facade::modifyColumn($this->table, 'qty', 'bigint')->success());
        $this->assertSame('bigint', Facade::tableDescribe($this->table)['qty']['Type']);

        $this->assertTrue(Facade::dropColumn($this->table, 'qty')->success());
        $this->assertArrayNotHasKey('qty', Facade::tableDescribe($this->table));
    }

    public function testAlterSettingsIsProxiedByTheManager(): void
    {
        $result = Facade::alterSettings($this->table, ['html_strip' => 1]);

        $this->assertTrue($result->success(), (string)$result->error());
        $this->assertSame(1, Facade::tableSettings($this->table)['html_strip'] ?? null);
    }

    public function testAlterMethodsAreAvailableThroughTheStaticAlias(): void
    {
        $this->assertTrue(ManticoreDb::addColumn($this->table, 'group_id', 'integer')->success());
        $this->assertArrayHasKey('group_id', ManticoreDb::tableDescribe($this->table));

        $this->assertTrue(ManticoreDb::dropColumn($this->table, 'group_id')->success());
        $this->assertArrayNotHasKey('group_id', ManticoreDb::tableDescribe($this->table));
    }

    /**
     * The schema cache lives in the Connection, and the Manager keeps that Connection for the
     * whole lifetime of the application - so DESCRIBE must be sent once, not per query.
     */
    public function testTheSchemaCacheOutlivesASingleQuery(): void
    {
        $connection = $this->connection();

        $this->assertNotContains($this->table, $this->cachedSchemas($connection));

        $connection->table($this->table)->insert(['title' => 'first', 'price' => 1.0]);

        $this->assertContains($this->table, $this->cachedSchemas($connection));

        // a second query gets a brand new Query object, the cache must still be there
        $connection->table($this->table)->get();

        $this->assertContains($this->table, $this->cachedSchemas($connection));
    }

    public function testForgetSchemaIsProxiedAndDropsTheCache(): void
    {
        $connection = $this->connection();
        $connection->table($this->table)->insert(['title' => 'first', 'price' => 1.0]);

        $this->assertContains($this->table, $this->cachedSchemas($connection));

        $this->assertInstanceOf(Connection::class, Facade::forgetSchema());

        $this->assertSame([], $this->cachedSchemas($connection));
    }

    /**
     * forgetSchema() reaches the default connection alone, which is not enough for a worker that
     * outlived a migration: every connection it holds has a cache of its own
     */
    public function testForgetSchemasDropsTheCacheOfEveryConnectionOfTheManager(): void
    {
        $manager = $this->app->make('manticore');

        $main = $manager->connection(static::CONNECTION_MAIN);
        $alt = $manager->connection(static::CONNECTION_ALT);

        $altTable = $this->tableName('compat_alt');

        $main->table($this->table)->insert(['title' => 'first', 'price' => 1.0]);
        $alt->create($altTable, ['title' => 'text']);
        $alt->table($altTable)->insert(['title' => 'of the other connection']);

        $this->assertNotSame([], $this->cachedSchemas($main));
        $this->assertNotSame([], $this->cachedSchemas($alt));

        // the default connection alone, the way the facade reaches it
        Facade::forgetSchema();

        $this->assertSame([], $this->cachedSchemas($main));
        $this->assertNotSame([], $this->cachedSchemas($alt), 'forgetSchema() must not reach the other connection.');

        $main->table($this->table)->get();
        $this->assertNotSame([], $this->cachedSchemas($main));

        $this->assertInstanceOf(Manager::class, $manager->forgetSchemas());

        $this->assertSame([], $this->cachedSchemas($main));
        $this->assertSame([], $this->cachedSchemas($alt));

        $alt->drop($altTable, true);
    }

    /**
     * Manager::connection() is memoized, so forgetSchema() through the facade must hit the very
     * same object the application queries with
     */
    public function testTheFacadeAndTheManagerShareOneConnection(): void
    {
        $this->assertSame($this->connection(), Facade::connection());
    }
}
