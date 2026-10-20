<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Database\ManticoreConnection;
use avadim\Manticore\Laravel\Database\TransactionRollbackException;
use Illuminate\Database\QueryException;
use LogicException;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use InvalidArgumentException;

class DatabaseDriverTransactionsTest extends TestCase
{
    public function testBeginFailureDoesNotRunTheCallbackOrLeaveATransactionOpen(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('beginTransaction')->willReturn(false);
        $db = new ManticoreConnection($pdo);
        try {
            $db->transaction(fn () => $this->fail('A failed BEGIN ran the callback'));
            $this->fail('A failed BEGIN was accepted.');
        } catch (QueryException $e) {
            $this->assertSame('BEGIN', $e->getSql());
        }
        $this->assertSame(0, $db->transactionLevel());
        $this->assertNull($db->getRawPdo());
    }

    public function testCommitFailureDisconnectsWithoutReplayingOrRollingBackAnUnknownOutcome(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('beginTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('commit')->willThrowException(new PDOException('Lost COMMIT response'));
        $pdo->expects($this->never())->method('rollBack');
        $db = new ManticoreConnection($pdo);
        $calls = 0;
        try {
            $db->transaction(function () use (&$calls) { ++$calls; });
            $this->fail('A lost COMMIT response was accepted.');
        } catch (QueryException $e) {
            $this->assertSame('COMMIT', $e->getSql());
        }
        $this->assertSame(1, $calls);
        $this->assertNull($db->getRawPdo());
        $this->assertSame(0, $db->transactionLevel());
    }

    public function testRollbackFailurePreservesBothErrors(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('rollBack')->willReturn(false);
        $db = new ManticoreConnection($pdo);
        $error = new RuntimeException('Callback failed');
        try {
            $db->transaction(static fn () => throw $error);
            $this->fail('A failed rollback was accepted.');
        } catch (TransactionRollbackException $e) {
            $this->assertSame($error, $e->callbackError);
            $this->assertInstanceOf(QueryException::class, $e->rollbackError);
            $this->assertSame('ROLLBACK', $e->rollbackError->getSql());
        }
        $this->assertNull($db->getRawPdo());
        $this->assertSame(0, $db->transactionLevel());
    }

    public function testNestedTransactionsAreRejectedAndOuterTransactionRollsBack(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('beginTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('rollBack')->willReturn(true);
        $pdo->expects($this->never())->method('commit');
        $db = new ManticoreConnection($pdo);
        $this->expectException(LogicException::class);
        $db->transaction(fn () => $db->transaction(fn () => null));
    }

    public function testIdRetrievalFailureNeverRepeatsASuccessfulInsert(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->expects($this->once())->method('execute')->willReturn(true);
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('prepare')->willReturn($statement);
        $pdo->expects($this->once())->method('query')->with('SELECT LAST_INSERT_ID() AS id')
            ->willThrowException(new PDOException('ID retrieval failed'));
        $db = new ManticoreConnection($pdo);
        try {
            $db->insertAndGetId('INSERT INTO products (id) VALUES (?)', [42]);
            $this->fail('ID retrieval failure was accepted.');
        } catch (QueryException $e) {
            $this->assertSame('SELECT LAST_INSERT_ID() AS id', $e->getSql());
        }
        $this->assertTrue($db->hasModifiedRecords());
    }

    public function testRetryRequestsAreRejectedBeforeConnecting(): void
    {
        $db = new ManticoreConnection(fn () => $this->fail('Retry validation connected to PDO'));
        $this->expectException(InvalidArgumentException::class);
        $db->transaction(fn () => null, 2);
    }

    public function testDdlIsRejectedWithoutAStatementInsideATransaction(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('rollBack')->willReturn(true);
        $pdo->expects($this->never())->method('prepare');
        $db = new ManticoreConnection($pdo);
        $this->expectException(LogicException::class);
        $db->transaction(fn () => $db->statement('/* migration */ CREATE TABLE products (title text)'));
    }
}
