<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel;

use \avadim\Manticore\QueryBuilder\Builder as ManticoreBuilder;
use avadim\Manticore\QueryBuilder\Connection;
use avadim\Manticore\QueryBuilder\ResultSet;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Class Manager
 *
 * Unknown methods are proxied to the default connection by __call(), which is what the
 * annotations below describe - they are the methods of Connection, so that an IDE knows about
 * them. Reads of a query answer with a Collection of Row objects, see Laravel\Query.
 *
 * @method Query table(string $name)                                        table to build a query for
 * @method Query index(string $name)                                        alias of table()
 * @method Query sql(string $sql)                                           query out of raw SQL
 * @method Query query()                                                    empty query of this connection
 * @method array select(string $sql, ?array $bindings = [])                 run raw SQL and take the rows
 * @method bool statement(string $sql)                                      run raw SQL, true when accepted
 *
 * @method mixed transaction(callable $callback, ?int $attempts = 1)        run the callback in a transaction
 * @method bool beginTransaction()
 * @method bool commit()
 * @method bool rollBack()
 * @method int transactionLevel()
 *
 * @method ResultSet create(string $name, $schema, ?array $options = [], ?bool $ifNotExists = false)
 * @method ResultSet drop(string $name, ?bool $ifExists = false)
 * @method bool hasTable(string $name)
 * @method ResultSet addColumn(string $tableName, string $columnName, $type, $options = null)
 * @method ResultSet dropColumn(string $tableName, $columnName)
 * @method ResultSet modifyColumn(string $tableName, string $columnName, string $type)
 * @method ResultSet alterSettings(string $tableName, array $settings)
 * @method ResultSet rename(string $tableName, string $newName)             needs Manticore Buddy
 *
 * @method array showTables(?string $pattern = null)
 * @method string showCreate(string $tableName)
 * @method string showCreateTable(string $tableName)
 * @method array showVariables(?string $pattern = null)
 * @method array tableDescribe(string $tableName)
 * @method array tableStatus(string $tableName)
 * @method array tableSettings(string $tableName)
 *
 * @method ResultSet|null lastResultSet()                                   answer of the last statement
 * @method Connection forgetSchema()                                        drop the cached DESCRIBE
 * @method Connection setLogger($logger)
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
    public function connection(?string $name = null, ?LoggerInterface $logger = null): Connection
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
    protected function makeConnection(string $name, ?LoggerInterface $logger = null)
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
     * Drop the cached DESCRIBE of every connection this manager built.
     *
     * forgetSchema() proxied to the default connection drops the schemas of that one alone, which
     * is not what a worker that outlived a migration needs. Connections opened through the static
     * \ManticoreDb rather than through the manager are not in its list and keep their cache.
     *
     * @return $this
     */
    public function forgetSchemas(): Manager
    {
        foreach ($this->connections as $connection) {
            $connection->forgetSchema();
        }

        return $this;
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
