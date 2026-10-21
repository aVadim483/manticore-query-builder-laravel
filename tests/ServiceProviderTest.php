<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Database\ManticoreConnection;
use avadim\Manticore\Laravel\DatabaseServiceProvider;
use avadim\Manticore\Laravel\ServiceProvider;
use Orchestra\Testbench\TestCase;

class ServiceProviderTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.connections.search', [
            'driver' => 'manticore', 'host' => '127.0.0.1', 'port' => 1, 'prefix' => 'app_',
        ]);
    }

    public function testRegistrationBeforeDatabaseResolutionIsLazyAndKeepsTheDefault(): void
    {
        $default = config('database.default');
        $this->app->register(ServiceProvider::class);
        $connection = $this->app->make('db')->connection('search');
        $this->assertInstanceOf(ManticoreConnection::class, $connection);
        $this->assertInstanceOf(\Closure::class, $connection->getRawPdo());
        $this->assertSame('search', $connection->getName());
        $this->assertSame('select * from `app_products`', $connection->table('products')->toSql());
        $this->assertSame($default, config('database.default'));
    }

    public function testRegistrationAfterDatabaseResolutionInstallsTheDriver(): void
    {
        $database = $this->app->make('db');
        $this->app->register(ServiceProvider::class);
        $this->assertInstanceOf(ManticoreConnection::class, $database->connection('search'));
    }

    public function testProviderDoesNotRegisterLegacyServicesOrConfiguration(): void
    {
        $this->app->register(ServiceProvider::class);
        $this->assertFalse($this->app->bound('manticore'));
        $this->assertFalse($this->app->bound('manticore.factory'));
        $this->assertNull(config('manticore'));
        $manifest = json_decode(file_get_contents(dirname(__DIR__).'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([ServiceProvider::class], $manifest['extra']['laravel']['providers']);
    }

    public function testExplicitNativeProviderStillUsesTheSameConnectionPool(): void
    {
        $this->app->register(DatabaseServiceProvider::class);
        $database = $this->app->make('db');
        $connection = $database->connection('search');
        $this->app->register(ServiceProvider::class);
        $this->assertSame($connection, $database->connection('search'));
        $database->purge('search');
        $this->assertInstanceOf(ManticoreConnection::class, $database->connection('search'));
        $this->assertNotSame($connection, $database->connection('search'));
    }
}
