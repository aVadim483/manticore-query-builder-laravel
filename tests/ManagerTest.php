<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Manager;
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\QueryBuilder\Connection;
use InvalidArgumentException;

class ManagerTest extends TestCase
{
    /**
     * @return Manager
     */
    protected function manager(): Manager
    {
        return $this->app->make('manticore');
    }

    public function testItReadsTheDefaultConnectionNameFromConfig(): void
    {
        $this->assertSame(static::CONNECTION_MAIN, $this->manager()->getDefaultConnection());
    }

    public function testSetDefaultConnectionUpdatesTheConfig(): void
    {
        $manager = $this->manager();
        $manager->setDefaultConnection(static::CONNECTION_ALT);

        $this->assertSame(static::CONNECTION_ALT, config('manticore.defaultConnection'));
        $this->assertSame(static::CONNECTION_ALT, $manager->getDefaultConnection());
    }

    /**
     * The name is validated before any connection is opened, so no server is needed here
     */
    public function testUnknownConnectionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ManticoreSearch connection [nowhere] not configured.');

        $this->manager()->connection('nowhere');
    }

    public function testItReturnsAConnectionInstance(): void
    {
        $this->requiresServer();

        $this->assertInstanceOf(Connection::class, $this->manager()->connection());
    }

    public function testConnectionsAreResolvedOnceAndCached(): void
    {
        $this->requiresServer();

        $manager = $this->manager();

        $this->assertSame($manager->connection(), $manager->connection());
        $this->assertSame($manager->connection(), $manager->connection(static::CONNECTION_MAIN));
    }

    public function testNamedConnectionsAreKeptSeparately(): void
    {
        $this->requiresServer();

        $manager = $this->manager();
        $main = $manager->connection(static::CONNECTION_MAIN);
        $alt  = $manager->connection(static::CONNECTION_ALT);

        $this->assertNotSame($main, $alt);
        $this->assertSame(
            [static::CONNECTION_MAIN, static::CONNECTION_ALT],
            array_keys($manager->getConnections())
        );
    }

    /**
     * A worker whose handle the server closed overnight has to be able to open a new one, and
     * the pool of the builder is the one connection() reads from - forgetting the manager's own
     * cache alone would hand out the same dead object again
     */
    public function testPurgeForgetsTheConnectionEverywhere(): void
    {
        $this->requiresServer();

        $manager = $this->manager();
        $first = $manager->connection();

        $this->assertTrue($manager->purge());

        $this->assertArrayNotHasKey(static::CONNECTION_MAIN, $manager->getConnections());
        $this->assertNotSame($first, ManticoreDb::connection(static::CONNECTION_MAIN));
        $this->assertNotSame($first, $manager->connection());
    }

    public function testPurgeOfANameThatWasNeverOpenedIsNotAnError(): void
    {
        $this->requiresServer();

        $this->assertFalse($this->manager()->purge(static::CONNECTION_ALT));
    }

    public function testPurgeLeavesTheOtherConnectionsAlone(): void
    {
        $this->requiresServer();

        $manager = $this->manager();
        $main = $manager->connection(static::CONNECTION_MAIN);
        $alt = $manager->connection(static::CONNECTION_ALT);

        $manager->purge(static::CONNECTION_ALT);

        $this->assertSame($main, $manager->connection(static::CONNECTION_MAIN));
        $this->assertNotSame($alt, $manager->connection(static::CONNECTION_ALT));
    }

    public function testReconnectAnswersWithANewConnectionThatWorks(): void
    {
        $this->requiresServer();

        $manager = $this->manager();
        $first = $manager->connection();

        $second = $manager->reconnect();

        $this->assertInstanceOf(Connection::class, $second);
        $this->assertNotSame($first, $second);
        $this->assertSame($second, $manager->connection());
        $this->assertIsArray($second->showTables());
    }

    public function testUnknownMethodsAreProxiedToTheDefaultConnection(): void
    {
        $this->requiresServer();

        $this->assertIsArray($this->manager()->showTables());
    }
}
