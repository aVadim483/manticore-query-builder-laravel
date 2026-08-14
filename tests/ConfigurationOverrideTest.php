<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

/**
 * mergeConfigFrom() merges the top level keys only, so an application which
 * defines its own "connections" replaces the packaged ones entirely.
 */
class ConfigurationOverrideTest extends TestCase
{
    public function testApplicationConnectionsReplaceThePackagedOnes(): void
    {
        $connections = config('manticore.connections');

        $this->assertArrayHasKey(static::CONNECTION_MAIN, $connections);
        $this->assertArrayHasKey(static::CONNECTION_ALT, $connections);
        $this->assertArrayNotHasKey('default', $connections);
    }

    public function testApplicationDefaultConnectionWins(): void
    {
        $this->assertSame(static::CONNECTION_MAIN, config('manticore.defaultConnection'));
    }
}
