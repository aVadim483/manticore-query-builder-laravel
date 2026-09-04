<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel;

use \avadim\Manticore\QueryBuilder\Builder as ManticoreBuilder;
use avadim\Manticore\QueryBuilder\Connection;
use Psr\Log\LoggerInterface;

class Factory
{
    /**
     * Make client and return Index object with all client methods.
     *
     * Builder::init() is the reconfiguration of the whole static builder: it throws away every
     * connection built so far and the logger along with them. Calling it again with the config it
     * already holds - which is what every Manager::connection() of a named connection used to do -
     * costs a connection other code is holding a reference to, and detaches a logger that was set
     * through ManticoreDb::setLogger(). So it is called when there is something to change, and a
     * logger of its own is attached without touching the rest.
     *
     * @param array $connection List of settings for connecting to server(s)
     * @param \Psr\Log\LoggerInterface|null $logger You may use any PSR logger in your application
     *
     * @return Connection
     */
    public function make(array $connection, ?LoggerInterface $logger = null)
    {
        if (ManticoreBuilder::currentConfig() !== $connection) {
            ManticoreBuilder::init($connection, $logger);
        }
        elseif (null !== $logger) {
            ManticoreBuilder::setLogger($logger);
        }

        return ManticoreBuilder::connection();
    }
}
