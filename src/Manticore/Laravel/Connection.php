<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel;

use avadim\Manticore\QueryBuilder\Connection as BaseConnection;

/**
 * The connection of the package: the same connection, building the queries of Laravel\Query.
 *
 * Registered as the connection class of the builder in ServiceProvider::boot(), so that both
 * entry points - the \ManticoreDb alias and the container service - answer alike.
 *
 * Transactions and statement() live in the query builder itself, so they are inherited: see
 * avadim\Manticore\QueryBuilder\Connection.
 *
 * @package avadim\Manticore\Laravel
 */
class Connection extends BaseConnection
{
    /**
     * @var string
     */
    protected string $queryClass = Query::class;
}
