<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

/**
 * Checks the config file shipped with the package and the way it is merged
 * into the application config. No connection is opened here.
 */
class ConfigurationTest extends TestCase
{
    /**
     * Use the published defaults instead of the test connections
     *
     * @return array|null
     */
    protected function manticoreConfig(): ?array
    {
        return null;
    }

    public function testItMergesThePackageConfig(): void
    {
        $config = config('manticore');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('defaultConnection', $config);
        $this->assertArrayHasKey('connections', $config);
        $this->assertArrayHasKey('default', $config['connections']);
    }

    public function testTheDefaultConnectionHasAllKeysExpectedByTheQueryBuilder(): void
    {
        $connection = config('manticore.connections.default');

        foreach (['host', 'port', 'username', 'password', 'timeout', 'prefix', 'force_prefix'] as $key) {
            $this->assertArrayHasKey($key, $connection, sprintf('Key "%s" is missing in the default connection.', $key));
        }

        $this->assertSame('', $connection['prefix']);
        $this->assertFalse($connection['force_prefix']);
    }

    /**
     * The logging channel is opt-in: a package that starts writing to the log of an application
     * it was just installed into would be a surprise
     */
    public function testNoLoggingChannelIsNamedByDefault(): void
    {
        $this->assertArrayHasKey('logChannel', config('manticore'));
        $this->assertNull(config('manticore.logChannel'));
    }

    public function testTheDefaultConnectionPointsToTheSphinxqlPort(): void
    {
        $this->assertSame(9306, (int)config('manticore.connections.default.port'));
    }
}
