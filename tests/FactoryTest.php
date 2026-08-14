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
     * make() calls Builder::init(), which resets the logger when none is given
     */
    public function testMakeWithoutALoggerDetachesThePreviouslySetOne(): void
    {
        $this->requiresServer();

        $logger = new ArrayLogger();
        ManticoreDb::setLogger($logger);

        $connection = $this->factory()->make(config('manticore'));
        $connection->showTables();

        $this->assertSame([], $logger->records);
    }
}
