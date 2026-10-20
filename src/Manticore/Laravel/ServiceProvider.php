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
 */
class ServiceProvider extends BaseServiceProvider
{
    /**
     * The configuration file of the package, published as a stub and merged as the defaults
     *
     * @return string
     */
    protected function configPath(): string
    {
        return __DIR__ . '/../../../config/manticore.php';
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->app instanceof LaravelApplication) {
            $this->publishes([$this->configPath() => config_path('manticore.php')], 'config');
        }

        // every connection of the builder is built of Laravel\Connection, and every query of it
        // of Laravel\Query - so that reads answer with a Collection whichever entry point was
        // used, the \ManticoreDb alias or the container service
        ManticoreBuilder::setConnectionClass(Connection::class);

        ManticoreBuilder::init(config('manticore'));

        $this->attachLogger();
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        $app = $this->app;

        // Lumen reads no configuration of its own accord, and its configure() replaces the whole
        // key rather than merging into it - so it goes first, and the defaults of the package
        // fill in what the application left out
        if ($app instanceof LumenApplication) {
            $app->configure('manticore');
        }

        // merged here rather than in boot(), so that the configuration is there for whoever reads
        // it while the providers are still registering
        $this->mergeConfigFrom($this->configPath(), 'manticore');

        $this->app->register(DatabaseServiceProvider::class);

        $app->singleton('manticore.factory', static function () {
            return new Factory();
        });

        $app->singleton('manticore', static function (Container $app) {
            return new Manager($app, $app['manticore.factory']);
        });

        $app->alias('manticore', Manager::class);

    }

    /**
     * Send the queries of the builder to a logging channel of the application.
     *
     * Nothing is logged unless a channel is named - MANTICORE_LOG_CHANNEL of the environment, or
     * "logChannel" of the configuration file. The logger of Laravel is a PSR-3 one, which is what
     * the builder takes, so the channel is handed over as it is.
     *
     * @return void
     */
    protected function attachLogger(): void
    {
        $channel = config('manticore.logChannel');

        if (!$channel || !$this->app->bound('log')) {
            return;
        }

        ManticoreBuilder::setLogger($this->app->make('log')->channel($channel));
    }
}
