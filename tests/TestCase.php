<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\ServiceProvider;
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use Orchestra\Testbench\TestCase as BaseTestCase;

/**
 * Base test case: boots a minimal Laravel application with the package registered.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Name of the connection used as default in tests
     */
    protected const CONNECTION_MAIN = 'testing';

    /**
     * Name of the secondary connection, used to test named/multiple connections
     */
    protected const CONNECTION_ALT = 'testing_alt';

    /**
     * Cached result of the server availability check
     *
     * @var bool|null
     */
    private static $serverAvailable;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array
     */
    protected function getPackageProviders($app): array
    {
        return [
            ServiceProvider::class,
        ];
    }

    /**
     * The alias declared in composer.json points to the upstream builder, not to the package facade
     *
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array
     */
    protected function getPackageAliases($app): array
    {
        return [
            'ManticoreDb' => ManticoreDb::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return void
     */
    protected function getEnvironmentSetUp($app): void
    {
        $config = $this->manticoreConfig();
        if (null !== $config) {
            $app['config']->set('manticore', $config);
        }
    }

    /**
     * Config injected into the application before the service provider boots.
     * Return null to leave the published defaults untouched.
     *
     * @return array|null
     */
    protected function manticoreConfig(): ?array
    {
        return [
            'defaultConnection' => static::CONNECTION_MAIN,
            'connections' => [
                static::CONNECTION_MAIN => [
                    'host'         => $this->serverHost(),
                    'port'         => $this->serverPort(),
                    'username'     => null,
                    'password'     => null,
                    'timeout'      => 5,
                    'prefix'       => 'phpunit_',
                    'force_prefix' => false,
                ],
                static::CONNECTION_ALT => [
                    'host'         => $this->serverHost(),
                    'port'         => $this->serverPort(),
                    'prefix'       => 'phpunit_alt_',
                ],
            ],
        ];
    }

    /**
     * The static builder keeps its state between tests, so it must be reset explicitly
     *
     * @return void
     */
    protected function tearDown(): void
    {
        ManticoreDb::setLogger(null);
        ManticoreDb::init([]);

        parent::tearDown();
    }

    /**
     * @return string
     */
    protected function serverHost(): string
    {
        return getenv('MANTICORE_TEST_HOST') ?: '127.0.0.1';
    }

    /**
     * @return int
     */
    protected function serverPort(): int
    {
        return (int)(getenv('MANTICORE_TEST_PORT') ?: 9306);
    }

    /**
     * Skip the test when no ManticoreSearch server is listening.
     * Any call that resolves a connection opens a real PDO connection, hence the check.
     *
     * @return void
     */
    protected function requiresServer(): void
    {
        if (null === self::$serverAvailable) {
            $socket = @fsockopen($this->serverHost(), $this->serverPort(), $errno, $errstr, 1);
            self::$serverAvailable = (bool)$socket;
            if ($socket) {
                fclose($socket);
            }
        }

        if (!self::$serverAvailable) {
            $this->markTestSkipped(sprintf(
                'No ManticoreSearch server at %s:%d (set MANTICORE_TEST_HOST/MANTICORE_TEST_PORT to change it).',
                $this->serverHost(),
                $this->serverPort()
            ));
        }
    }

    /**
     * Unique table name for tests which create tables
     *
     * @param string $suffix
     *
     * @return string
     */
    protected function tableName(string $suffix = ''): string
    {
        return 'phpunit_' . uniqid() . ($suffix ? '_' . $suffix : '');
    }
}
