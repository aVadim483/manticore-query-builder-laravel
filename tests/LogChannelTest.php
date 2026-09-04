<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;

/**
 * MANTICORE_LOG_CHANNEL - the queries in the log of the application, without a line of code.
 *
 * The channel of Laravel is a PSR-3 logger and the builder takes any of those, so the whole of
 * the feature is the service provider handing one to the other. What is checked here is that it
 * happens at all, and that the queries really arrive.
 */
class LogChannelTest extends TestCase
{
    /**
     * Name of the logging channel this test writes to
     */
    private const CHANNEL = 'manticore_testing';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return void
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // a channel that keeps its records in memory instead of writing them anywhere
        $app['config']->set('logging.channels.' . self::CHANNEL, [
            'driver'  => 'monolog',
            'handler' => TestHandler::class,
        ]);

        $app['config']->set('manticore.logChannel', self::CHANNEL);
    }

    /**
     * The handler of the channel, holding whatever was written to it
     *
     * @return TestHandler
     */
    private function handler(): TestHandler
    {
        foreach (Log::channel(self::CHANNEL)->getLogger()->getHandlers() as $handler) {
            if ($handler instanceof TestHandler) {
                return $handler;
            }
        }

        $this->fail('The testing channel has no TestHandler behind it.');
    }

    public function testTheQueriesGoToTheChannelNamedByTheConfig(): void
    {
        $this->requiresServer();

        ManticoreDb::showTables();

        $records = $this->handler()->getRecords();

        $this->assertNotEmpty($records, 'The channel of the config received no query.');

        $messages = array_map(static function ($record) {
            return is_array($record) ? $record['message'] : $record->message;
        }, $records);

        $this->assertContains('Manticore Query:', $messages);
    }

    /**
     * The connections of the manager are made through the factory, which used to reconfigure the
     * builder and drop the logger of the channel along the way
     */
    public function testTheChannelSurvivesAConnectionOfTheManager(): void
    {
        $this->requiresServer();

        $this->app->make('manticore')->connection(static::CONNECTION_ALT);

        ManticoreDb::connection(static::CONNECTION_MAIN)->showTables();

        $this->assertNotEmpty($this->handler()->getRecords(), 'The logger of the channel was detached.');
    }
}
