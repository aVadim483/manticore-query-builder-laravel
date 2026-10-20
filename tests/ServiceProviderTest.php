<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Factory;
use avadim\Manticore\Laravel\Manager;
use avadim\Manticore\Laravel\ServiceProvider;
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProviderTest extends TestCase
{
    public function testThePackageAlsoRegistersTheNativeConnection(): void
    {
        config(['database.connections.manticore' => ['driver' => 'manticore', 'host' => '127.0.0.1', 'port' => 1]]);
        $connection = $this->app->make('db')->connection('manticore');
        $this->assertInstanceOf(\avadim\Manticore\Laravel\Database\ManticoreConnection::class, $connection);
        $this->assertInstanceOf(\Closure::class, $connection->getRawPdo());
    }

    public function testItBindsTheManagerAsSingleton(): void
    {
        $this->assertTrue($this->app->bound('manticore'));

        $manager = $this->app->make('manticore');

        $this->assertInstanceOf(Manager::class, $manager);
        $this->assertSame($manager, $this->app->make('manticore'));
    }

    public function testItBindsTheFactoryAsSingleton(): void
    {
        $this->assertTrue($this->app->bound('manticore.factory'));

        $factory = $this->app->make('manticore.factory');

        $this->assertInstanceOf(Factory::class, $factory);
        $this->assertSame($factory, $this->app->make('manticore.factory'));
    }

    public function testTheManagerClassIsAliasedToTheSameInstance(): void
    {
        $this->assertSame($this->app->make('manticore'), $this->app->make(Manager::class));
    }

    /**
     * boot() primes the static builder, this is what makes the \ManticoreDb alias work
     * without touching the container at all
     */
    public function testItInitialisesTheStaticBuilderOnBoot(): void
    {
        $this->assertSame(config('manticore'), ManticoreDb::currentConfig());
        $this->assertSame(static::CONNECTION_MAIN, ManticoreDb::currentConfig()['defaultConnection']);
    }

    public function testItPublishesTheConfigFile(): void
    {
        $paths = BaseServiceProvider::pathsToPublish(ServiceProvider::class, 'config');

        $this->assertNotEmpty($paths, 'The config file is not registered for publishing.');

        $sources = array_map('realpath', array_keys($paths));
        $expected = realpath(__DIR__ . '/../config/manticore.php');

        $this->assertNotFalse($expected, 'The package config file is missing.');
        $this->assertContains($expected, $sources);
        $this->assertContains(config_path('manticore.php'), array_values($paths));
    }
}
