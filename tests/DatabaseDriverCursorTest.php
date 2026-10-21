<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Database\ManticoreConnection;
use Illuminate\Database\QueryException;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

class DatabaseDriverCursorTest extends TestCase
{
    private function cursorConnection(PDOStatement $statement): ManticoreConnection
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('prepare')->willReturn($statement);
        return new ManticoreConnection($pdo);
    }

    public function testExhaustionClosesTheCursorEvenWhenTheStatementIsRetainedElsewhere(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetch')->willReturnOnConsecutiveCalls((object)['id' => 1], false);
        $statement->expects($this->once())->method('closeCursor')->willReturn(true);
        $db = $this->cursorConnection($statement);
        $this->assertSame(1, iterator_to_array($db->cursor('select id from products'))[0]->id);
    }

    public function testDestroyingAnEarlyIteratorClosesItsCursor(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetch')->willReturn((object)['id' => 1]);
        $closed = false;
        $statement->expects($this->once())->method('closeCursor')->willReturnCallback(function () use (&$closed) { $closed = true; return true; });
        $iterator = $this->cursorConnection($statement)->cursor('select id from products');
        $this->assertSame(1, $iterator->current()->id);
        $this->assertFalse($closed);
        unset($iterator);
        $this->assertTrue($closed);
    }

    public function testFetchErrorsCloseTheCursorAndKeepTheOriginalSqlAndBindings(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('fetch')->willThrowException(new PDOException('Read interrupted'));
        $statement->expects($this->once())->method('closeCursor')->willReturn(true);
        $db = $this->cursorConnection($statement);
        try {
            iterator_to_array($db->cursor('select id from products where price > ?', [1.25]));
            $this->fail('A failed fetch was accepted.');
        } catch (QueryException $error) {
            $this->assertSame('select id from products where price > ?', $error->getSql());
            $this->assertSame([1.25], $error->getBindings());
        }
    }

    public function testExecutionErrorsAlsoCloseTheCursorWithoutDoubleWrapping(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $original = new PDOException('Execute failed');
        $statement->method('execute')->willThrowException($original);
        $statement->expects($this->once())->method('closeCursor')->willReturn(true);
        try {
            iterator_to_array($this->cursorConnection($statement)->cursor('select bad from products'));
            $this->fail('A failed execute was accepted.');
        } catch (QueryException $error) {
            $this->assertSame($original, $error->getPrevious());
        }
    }

    public function testPretendReturnsAnEmptyCursorWithoutOpeningPdo(): void
    {
        $db = new ManticoreConnection(fn () => $this->fail('Pretend opened a connection'));
        $log = $db->pretend(function () use ($db) {
            $this->assertSame([], iterator_to_array($db->cursor('select * from products')));
        });
        $this->assertCount(1, $log);
    }
}
