<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel;

use \avadim\Manticore\QueryBuilder\Builder as ManticoreBuilder;
use avadim\Manticore\QueryBuilder\Connection;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Class Manager
 *
 * @package avadim\Manticore\Laravel
 */
class Manager
{
    /**
     * The application instance.
     *
     * @var \Illuminate\Contracts\Container\Container
     */
    protected $app;

    /**
     * The Manticoresearch connection factory instance.
     *
     * @var \avadim\Manticore\Laravel\Factory
     */
    protected $factory;

    /**
     * The active connection instances.
     *
     * @var array
     */
    protected $connections = [];

    /**
     * @param \Illuminate\Contracts\Container\Container $app
     * @param \avadim\Manticore\Laravel\Factory $factory
     */
    public function __construct(Container $app, Factory $factory)
    {
        $this->app     = $app;
        $this->factory = $factory;
    }

    /**
     * Retrieve or build the named connection.
     *
     * @param string|null $name Name of connection
     * @param LoggerInterface|null $logger You may use any PSR logger in your application
     *
     * @return Connection
     */
    public function connection(string $name = null, LoggerInterface $logger = null): Connection
    {
        $name = $name ?: $this->getDefaultConnection();

        if (!isset($this->connections[$name])) {
            $connection = $this->makeConnection($name, $logger);

            $this->connections[$name] = $connection;
        }

        return $this->connections[$name];
    }

    /**
     * Get the default connection.
     *
     * @return string
     */
    public function getDefaultConnection(): string
    {
        return $this->app['config']['manticore.defaultConnection'];
    }

    /**
     * Set the default connection.
     *
     * @param string $connection
     */
    public function setDefaultConnection(string $connection): void
    {
        $this->app['config']['manticore.defaultConnection'] = $connection;
    }

    /**
     * Make a new connection.
     *
     * @param string $name Name of connection
     * @param \Psr\Log\LoggerInterface|null $logger You may use any PSR logger in your application
     *
     * @return Connection
     */
    protected function makeConnection(string $name, LoggerInterface $logger = null)
    {
        $config = $this->getConfig();
        if (null === Arr::get($config, 'connections.' . $name)) {
            throw new InvalidArgumentException("ManticoreSearch connection [$name] not configured.");
        }
        $this->factory->make($config, $logger);

        return ManticoreBuilder::connection($name);
    }

    /**
     * Get the configuration for connections
     *
     * @return mixed
     * @throws \InvalidArgumentException
     */
    protected function getConfig()
    {

        return $this->app['config']['manticore'];
    }

    /**
     * Return all of the created connections.
     *
     * @return array
     */
    public function getConnections(): array
    {
        return $this->connections;
    }

    /**
     * Dynamically pass methods to the default connection.
     *
     * @param string $method
     * @param array $parameters
     *
     * @return mixed
     */
    public function __call(string $method, array $parameters)
    {
        return call_user_func_array([$this->connection(), $method], $parameters);
    }
}
