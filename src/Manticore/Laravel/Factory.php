<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel;

use \avadim\Manticore\QueryBuilder\Builder as ManticoreBuilder;
use avadim\Manticore\QueryBuilder\Connection;
use Psr\Log\LoggerInterface;

class Factory
{
    /**
     * Make client and return Index object with all client methods
     *
     * @param array $connection List of settings for connecting to server(s)
     * @param \Psr\Log\LoggerInterface|null $logger You may use any PSR logger in your application
     *
     * @return Connection
     */
    public function make(array $connection, LoggerInterface $logger = null)
    {
        ManticoreBuilder::init($connection, $logger);

        return ManticoreBuilder::connection();
    }
}
