<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Database\ManticoreConnection;
use InvalidArgumentException;

class DatabaseDriverCallsTest extends DatabaseTestCase
{
    public function testGenericCallCapturesAllResultSetsAndClosesTheCursorBeforeEvents(): void
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects($this->once())->method('execute')->willReturn(true);
        $statement->method('columnCount')->willReturn(1);
        $statement->method('fetchAll')->willReturnOnConsecutiveCalls([(object)['value' => 'first']], [], [(object)['value' => 'third']]);
        $statement->method('nextRowset')->willReturnOnConsecutiveCalls(true, true, false);
        $closed = false;
        $statement->expects($this->once())->method('closeCursor')->willReturnCallback(function () use (&$closed) { $closed = true; return true; });
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects($this->once())->method('prepare')->with('CALL CUSTOM(?)')->willReturn($statement);
        $db = new ManticoreConnection($pdo);
        $events = new \Illuminate\Events\Dispatcher();
        $events->listen(\Illuminate\Database\Events\QueryExecuted::class, function () use (&$closed) { $this->assertTrue($closed); });
        $db->setEventDispatcher($events);
        $sets = $db->call('custom', ['text']);
        $this->assertCount(3, $sets);
        $this->assertSame('first', $sets[0][0]->value);
        $this->assertTrue($sets[1]->isEmpty());
        $this->assertSame('third', $sets[2][0]->value);
    }

    public function testCallErrorsRetainSqlAndBindingsWithoutReplaying(): void
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects($this->once())->method('execute')->willThrowException(new \PDOException('Buddy unavailable'));
        $statement->expects($this->once())->method('closeCursor')->willReturn(true);
        $pdo = $this->createMock(\PDO::class);
        $pdo->expects($this->once())->method('prepare')->willReturn($statement);
        $db = new ManticoreConnection($pdo);
        try {
            $db->autocomplete("gal'", 'products', ['fuzziness' => 0]);
            $this->fail('An unavailable procedure was accepted.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertSame('CALL AUTOCOMPLETE(?, ?, ? AS fuzziness)', $e->getSql());
            $this->assertSame(["gal'", 'products', 0], $e->getBindings());
        }
    }

    public function testCallHelpersUseBindingsPrefixesAndPretendWithoutConnecting(): void
    {
        $db = new ManticoreConnection(fn () => throw new \LogicException('Unexpected connection'), '', 'app_');
        $log = $db->pretend(function () use ($db) {
            $this->assertSame([], $db->keywords("a'", 'products', ['stats' => 1])->all());
            $db->suggest('galaxi', 'products');
            $db->qsuggest('a galaxi', 'products');
            $db->autocomplete('gal', 'products', ['fuzziness' => 0]);
            $db->snippets(['galaxy one', 'galaxy two'], 'products', 'galaxy', ['before_match' => '<em>']);
        });
        $this->assertStringContainsString('CALL KEYWORDS(', $log[0]['query']);
        $this->assertSame(["a'", 'app_products', 1], $log[0]['bindings']);
        $compiled = \avadim\Manticore\Laravel\Database\ProcedureCall::compile('SNIPPETS', [['one', 'two'], 'products', 'word'], ['before_match' => '<em>']);
        $this->assertSame('CALL SNIPPETS((?, ?), ?, ?, ? AS before_match)', $compiled[0]);
        $this->assertSame(['galaxy one', 'galaxy two', 'app_products', 'galaxy', '<em>'], $log[4]['bindings']);
        $this->assertStringContainsString('AUTOCOMPLETE', $log[3]['query']);
    }

    public function testCallRejectsSqlFragmentsAndStructuredOptions(): void
    {
        $db = new ManticoreConnection(fn () => throw new \LogicException('Unexpected connection'));
        foreach ([['KEYWORDS);', []], ['KEYWORDS', ['x'], ['x);' => 1]],
            ['KEYWORDS', [[]]], ['KEYWORDS', ['x'], ['stats' => []]], ['KEYWORDS', [INF]]] as $arguments) {
            try {
                $db->call(...$arguments);
                $this->fail('Invalid CALL input was accepted.');
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }
}
