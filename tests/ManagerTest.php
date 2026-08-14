<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Manager;
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

    public function testUnknownMethodsAreProxiedToTheDefaultConnection(): void
    {
        $this->requiresServer();

        $this->assertIsArray($this->manager()->showTables());
    }
}
