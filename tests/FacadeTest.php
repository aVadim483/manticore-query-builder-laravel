<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Facade;
use avadim\Manticore\Laravel\Manager;
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use ReflectionClass;

class FacadeTest extends TestCase
{
    public function testTheFacadeResolvesToTheManager(): void
    {
        $root = Facade::getFacadeRoot();

        $this->assertInstanceOf(Manager::class, $root);
        $this->assertSame($this->app->make('manticore'), $root);
    }

    /**
     * composer.json registers "ManticoreDb" as an alias of the upstream builder,
     * not of the package facade
     */
    public function testTheShortAliasPointsToTheUpstreamBuilder(): void
    {
        $this->assertTrue(class_exists('ManticoreDb'));
        $this->assertSame(ManticoreDb::class, (new ReflectionClass('ManticoreDb'))->getName());
    }

    public function testTheFacadeProxiesCallsToTheDefaultConnection(): void
    {
        $this->requiresServer();

        $this->assertIsArray(Facade::showTables());
    }
}
