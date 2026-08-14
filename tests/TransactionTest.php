<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Connection;
use avadim\Manticore\Laravel\Facade;
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use LogicException;
use RuntimeException;

/**
 * transaction() and statement(). Manticore serves BEGIN / COMMIT / ROLLBACK on real-time
 * tables, so the Laravel way of writing a transaction works as it does elsewhere.
 */
class TransactionTest extends TestCase
{
    /**
     * @var string|null
     */
    protected $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresServer();

        $this->table = $this->tableName('tx');

        ManticoreDb::create($this->table, [
            'title' => 'text',
            'qty' => 'integer',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->table) {
            ManticoreDb::dropIfExists($this->table);
            $this->table = null;
        }

        parent::tearDown();
    }

    /**
     * @return Connection
     */
    protected function connection(): Connection
    {
        return $this->app->make('manticore')->connection();
    }

    /**
     * @return int
     */
    protected function rows(): int
    {
        return ManticoreDb::table($this->table)->count();
    }

    public function testStatementRunsRawSql(): void
    {
        $this->assertTrue($this->connection()->statement('SELECT 1'));
        $this->assertFalse($this->connection()->statement('SELECT * FROM table_that_is_not_there'));
    }

    public function testTransactionCommitsWhatTheCallbackWrote(): void
    {
        $result = $this->connection()->transaction(function (Connection $connection) {
            $connection->table($this->table)->insert(['title' => 'kept', 'qty' => 1]);

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(1, $this->rows());
    }

    public function testTransactionRollsBackOnAnException(): void
    {
        try {
            $this->connection()->transaction(function (Connection $connection) {
                $connection->table($this->table)->insert(['title' => 'gone', 'qty' => 1]);

                throw new RuntimeException('nope');
            });
            $this->fail('The exception of the callback must be rethrown');
        }
        catch (RuntimeException $e) {
            $this->assertSame('nope', $e->getMessage());
        }

        $this->assertSame(0, $this->rows());
        $this->assertSame(0, $this->connection()->transactionLevel());
    }

    public function testTransactionRetriesTheGivenNumberOfTimes(): void
    {
        $attempts = 0;

        try {
            $this->connection()->transaction(function () use (&$attempts) {
                $attempts++;

                throw new RuntimeException('always fails');
            }, 3);
        }
        catch (RuntimeException $e) {
            // the last failure is rethrown
        }

        $this->assertSame(3, $attempts);
    }

    public function testManualBeginAndRollback(): void
    {
        $connection = $this->connection();

        $connection->beginTransaction();
        $connection->table($this->table)->insert(['title' => 'gone', 'qty' => 1]);
        $connection->rollBack();

        $this->assertSame(0, $this->rows());
    }

    public function testManualBeginAndCommit(): void
    {
        $connection = $this->connection();

        $connection->beginTransaction();
        $connection->table($this->table)->insert(['title' => 'kept', 'qty' => 1]);
        $connection->commit();

        $this->assertSame(1, $this->rows());
    }

    /**
     * Manticore has no savepoints, so a nested call only counts a level - the outermost commit
     * is the one that writes
     */
    public function testNestedTransactionsCountLevels(): void
    {
        $connection = $this->connection();

        $connection->transaction(function (Connection $connection) {
            $this->assertSame(1, $connection->transactionLevel());

            $connection->transaction(function (Connection $connection) {
                $this->assertSame(2, $connection->transactionLevel());
                $connection->table($this->table)->insert(['title' => 'kept', 'qty' => 1]);
            });
        });

        $this->assertSame(0, $connection->transactionLevel());
        $this->assertSame(1, $this->rows());
    }

    public function testCommitWithoutATransactionThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no transaction to commit');

        $this->connection()->commit();
    }

    public function testRollbackWithoutATransactionThrows(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no transaction to roll back');

        $this->connection()->rollBack();
    }

    /**
     * Both are reachable through the facade, which proxies to the default connection
     */
    public function testTheFacadeProxiesTransactionAndStatement(): void
    {
        $this->assertTrue(Facade::statement('SELECT 1'));

        Facade::transaction(function (Connection $connection) {
            $connection->table($this->table)->insert(['title' => 'via facade', 'qty' => 1]);
        });

        $this->assertSame(1, $this->rows());
    }
}
