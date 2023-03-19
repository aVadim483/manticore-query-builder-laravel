<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel;

use avadim\Manticore\QueryBuilder\Builder as ManticoreBuilder;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Container\Container;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Laravel\Lumen\Application as LumenApplication;

/**
 * Class ServiceProvider
 *
 * @package avadim\Manticore\Laravel
 * @codeCoverageIgnore
 */
class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register the application services.
     *
     * @return void
     */
    public function boot(): void
    {
        $source = __DIR__ . '/../../../config/manticore.php';

        if ($this->app instanceof LaravelApplication) {
            $this->publishes([$source => config_path('manticore.php')], 'config');
        }
        elseif ($this->app instanceof LumenApplication) {
            $this->app->configure('manticore');
        }

        $this->mergeConfigFrom($source, 'manticore');

        ManticoreBuilder::init(config('manticore'));
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        $app = $this->app;

        $app->singleton('manticore.factory', static function () {
            return new Factory();
        });

        $app->singleton('manticore', static function (Container $app) {
            return new Manager($app, $app['manticore.factory']);
        });

        $app->alias('manticore', Manager::class);

    }
}
