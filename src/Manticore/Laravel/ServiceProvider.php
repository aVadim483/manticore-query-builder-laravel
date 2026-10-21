<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel;

use avadim\Manticore\Laravel\Database\ManticoreConnection;
use avadim\Manticore\Laravel\Database\ManticoreConnector;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

/** Registers the native Manticore driver with Laravel's database manager. */
class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving('db', static function (DatabaseManager $database) {
            $database->extend('manticore', static function (array $config, string $name) {
                $config['name'] = $name;

                return new ManticoreConnection(
                    static fn () => (new ManticoreConnector())->connect($config),
                    $config['database'] ?? '',
                    $config['prefix'] ?? '',
                    $config,
                );
            });
        });
    }
}
