<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use Illuminate\Database\Connectors\Connector;
use Illuminate\Database\Connectors\ConnectorInterface;
use InvalidArgumentException;
use PDO;

class ManticoreConnector extends Connector implements ConnectorInterface
{
    public function connect(array $config)
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = filter_var($config['port'] ?? 9306, FILTER_VALIDATE_INT);

        if (!is_string($host) || $host === '' || strpbrk($host, ";\0") !== false
            || $port === false || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Invalid Manticore host or port.');
        }

        $options = $this->getOptions($config);
        if (array_key_exists(PDO::ATTR_EMULATE_PREPARES, $config['options'] ?? [])
            && !$config['options'][PDO::ATTR_EMULATE_PREPARES]) {
            throw new InvalidArgumentException('The Manticore driver requires emulated prepares to preserve floating-point writes.');
        }
        // Manticore 28.6.6 rounds fractional UPDATE literals in native prepared statements.
        // NumericBindings additionally preserves PHP floats that PDO would bind as strings.
        $options[PDO::ATTR_EMULATE_PREPARES] = true;
        if (isset($config['timeout'])) {
            $options[PDO::ATTR_TIMEOUT] = max(1, (int)$config['timeout']);
        }

        // Manticore speaks the MySQL protocol, but needs no USE, SET NAMES or sql_mode.
        return $this->createConnection("mysql:host={$host};port={$port}", $config, $options);
    }
}
