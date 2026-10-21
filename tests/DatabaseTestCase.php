<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\ServiceProvider;
use Orchestra\Testbench\TestCase;

/** Tests boot through the same provider Laravel discovers in installed applications. */
abstract class DatabaseTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ServiceProvider::class];
    }

    protected function serverHost(): string
    {
        return getenv('MANTICORE_TEST_HOST') ?: '127.0.0.1';
    }

    protected function serverPort(): int
    {
        return (int)(getenv('MANTICORE_TEST_PORT') ?: 9306);
    }

    protected function requiresServer(): void
    {
        $socket = @fsockopen($this->serverHost(), $this->serverPort(), $errno, $errstr, 1);
        if (!$socket) {
            $this->markTestSkipped('Manticore is unavailable at '.$this->serverHost().':'.$this->serverPort());
        }
        fclose($socket);
    }

    protected function tableName(string $suffix): string
    {
        return 'phpunit_'.bin2hex(random_bytes(8)).'_'.$suffix;
    }
}
