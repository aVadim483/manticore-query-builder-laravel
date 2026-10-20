<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel;

use avadim\Manticore\Laravel\Database\ManticoreConnection;
use avadim\Manticore\Laravel\Database\ManticoreConnector;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

/** Registers the native Laravel driver without initializing the legacy PHP builder. */
class DatabaseServiceProvider extends ServiceProvider
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
