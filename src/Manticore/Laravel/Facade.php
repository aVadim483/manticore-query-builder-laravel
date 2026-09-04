<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel;

use avadim\Manticore\QueryBuilder\Connection as BaseConnection;
use avadim\Manticore\QueryBuilder\ResultSet;
use Illuminate\Support\Facades\Facade as BaseFacade;

/**
 * Class Facade
 *
 * Resolves to Manager, which answers the calls of its own and proxies the rest to the default
 * connection. Both are listed below, so that an IDE knows what the facade takes. Reads of a
 * query answer with a Collection of Row objects, see Laravel\Query.
 *
 * @method static BaseConnection connection(?string $name = null, $logger = null)  named connection
 * @method static string getDefaultConnection()
 * @method static void setDefaultConnection(string $connection)
 * @method static array getConnections()                                    connections built so far
 * @method static Manager forgetSchemas()                                   drop the cached DESCRIBE of every connection
 *
 * @method static Query table(string $name)                                 table to build a query for
 * @method static Query index(string $name)                                 alias of table()
 * @method static Query sql(string $sql)                                    query out of raw SQL
 * @method static Query query()                                             empty query of this connection
 * @method static array select(string $sql, ?array $bindings = [])          run raw SQL and take the rows
 * @method static bool statement(string $sql)                               run raw SQL, true when accepted
 *
 * @method static mixed transaction(callable $callback, ?int $attempts = 1) run the callback in a transaction
 * @method static bool beginTransaction()
 * @method static bool commit()
 * @method static bool rollBack()
 * @method static int transactionLevel()
 *
 * @method static ResultSet create(string $name, $schema, ?array $options = [], ?bool $ifNotExists = false)
 * @method static ResultSet drop(string $name, ?bool $ifExists = false)
 * @method static bool hasTable(string $name)
 * @method static ResultSet addColumn(string $tableName, string $columnName, $type, $options = null)
 * @method static ResultSet dropColumn(string $tableName, $columnName)
 * @method static ResultSet modifyColumn(string $tableName, string $columnName, string $type)
 * @method static ResultSet alterSettings(string $tableName, array $settings)
 * @method static ResultSet rename(string $tableName, string $newName)      needs Manticore Buddy
 *
 * @method static array showTables(?string $pattern = null)
 * @method static string showCreate(string $tableName)
 * @method static string showCreateTable(string $tableName)
 * @method static array showVariables(?string $pattern = null)
 * @method static array tableDescribe(string $tableName)
 * @method static array tableStatus(string $tableName)
 * @method static array tableSettings(string $tableName)
 *
 * @method static ResultSet|null lastResultSet()                            answer of the last statement
 * @method static BaseConnection forgetSchema()                             drop the cached DESCRIBE
 * @method static BaseConnection setLogger($logger)
 *
 * @see Manager
 *
 * @package avadim\Manticore\Laravel
 */
class Facade extends BaseFacade
{
    /**
     * {@inheritdoc}
     */
    protected static function getFacadeAccessor(): string
    {
        return 'manticore';
    }
}
