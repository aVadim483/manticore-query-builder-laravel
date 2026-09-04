<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Factory;
use avadim\Manticore\Laravel\Tests\Support\ArrayLogger;
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\QueryBuilder\Connection;

class FactoryTest extends TestCase
{
    /**
     * @return Factory
     */
    protected function factory(): Factory
    {
        return $this->app->make('manticore.factory');
    }

    public function testMakeStoresTheConfigInTheStaticBuilder(): void
    {
        $this->requiresServer();

        $config = config('manticore');
        $this->factory()->make($config);

        $this->assertSame($config, ManticoreDb::currentConfig());
    }

    public function testMakeReturnsTheDefaultConnection(): void
    {
        $this->requiresServer();

        $connection = $this->factory()->make(config('manticore'));

        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertSame($connection, ManticoreDb::connection(static::CONNECTION_MAIN));
    }

    public function testTheLoggerPassedToMakeReceivesQueries(): void
    {
        $this->requiresServer();

        $logger = new ArrayLogger();
        $connection = $this->factory()->make(config('manticore'), $logger);

        $connection->showTables();

        $queries = $logger->recordsOfLevel('info');

        $this->assertNotEmpty($queries, 'The logger did not receive any query record.');
        $this->assertSame('Manticore Query:', $queries[0]['message']);
    }

    /**
     * make() of the config the builder already holds changes nothing about it, so a logger set
     * through ManticoreDb::setLogger() stays where it was
     */
    public function testMakeWithoutALoggerKeepsThePreviouslySetOne(): void
    {
        $this->requiresServer();

        $logger = new ArrayLogger();
        $this->factory()->make(config('manticore'));
        ManticoreDb::setLogger($logger);

        $connection = $this->factory()->make(config('manticore'));
        $connection->showTables();

        $this->assertNotEmpty($logger->records, 'The logger was detached by a repeated make().');
    }

    /**
     * ... and the connections built so far are not thrown away either: Builder::init() empties
     * the pool, so a second make() used to leave the caller of the first holding an object the
     * builder no longer knows
     */
    public function testMakeOfTheSameConfigKeepsTheConnectionsAlreadyBuilt(): void
    {
        $this->requiresServer();

        $factory = $this->factory();
        $first = $factory->make(config('manticore'));

        $second = $factory->make(config('manticore'));

        $this->assertSame($first, $second);
        $this->assertSame($first, ManticoreDb::connection(static::CONNECTION_MAIN));
    }

    /**
     * A config that really differs is a reconfiguration, and that does rebuild the pool
     */
    public function testMakeOfAnotherConfigRebuildsTheConnections(): void
    {
        $this->requiresServer();

        $factory = $this->factory();
        $first = $factory->make(config('manticore'));

        $config = config('manticore');
        $config['defaultConnection'] = static::CONNECTION_ALT;
        $second = $factory->make($config);

        $this->assertNotSame($first, $second);
        $this->assertSame($config, ManticoreDb::currentConfig());
    }
}
