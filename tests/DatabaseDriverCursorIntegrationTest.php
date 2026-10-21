<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;
use PDO;

class DatabaseDriverCursorIntegrationTest extends DatabaseTestCase
{
    private ?string $cursorTable = null;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $config = ['driver' => 'manticore', 'host' => $this->serverHost(), 'port' => $this->serverPort(), 'timeout' => 5];
        $app['config']->set('database.connections.manticore', $config);
        $app['config']->set('database.connections.unbuffered', $config + ['options' => [PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresServer();
        $this->cursorTable = $this->tableName('cursor');
        Schema::connection('manticore')->create($this->cursorTable, function ($table) {
            $table->text('title');
            $table->json('payload');
        });
        DB::connection('manticore')->table($this->cursorTable)->insert([
            ['id' => 1, 'title' => 'galaxy phone', 'payload' => ['id' => 1]],
            ['id' => 2, 'title' => 'galaxy tablet', 'payload' => ['id' => 2]],
            ['id' => 3, 'title' => 'apple phone', 'payload' => ['id' => 3]],
        ]);
    }

    protected function tearDown(): void
    {
        try {
            DB::disconnect('unbuffered');
            if ($this->cursorTable !== null) {
                Schema::connection('manticore')->dropIfExists($this->cursorTable);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testCursorIsDeferredUsesOneSelectAndAllowsBufferedQueriesDuringIteration(): void
    {
        $db = DB::connection('manticore');
        $db->enableQueryLog();
        $db->flushQueryLog();
        $cursor = $db->table($this->cursorTable)->match('galaxy')->orderBy('id')->cursor();
        $this->assertSame([], $db->getQueryLog());
        $ids = [];
        foreach ($cursor as $row) {
            $this->assertSame(['id' => $row->id], $row->payload);
            $ids[] = $row->id;
            $this->assertSame(3, $db->table($this->cursorTable)->count());
        }
        $this->assertSame([1, 2], $ids);
        $queries = array_filter($db->getQueryLog(), fn ($entry) => str_contains($entry['query'], 'MATCH('));
        $this->assertCount(1, $queries);
    }

    public function testUnbufferedCursorCanBeFullyConsumedOrStoppedEarly(): void
    {
        $db = DB::connection('unbuffered');
        $this->assertSame([1, 2, 3], $db->table($this->cursorTable)->orderBy('id')->cursor()->pluck('id')->all());
        $this->assertSame(3, $db->table($this->cursorTable)->count());
        $this->assertSame([1], $db->table($this->cursorTable)->orderBy('id')->cursor()->take(1)->pluck('id')->all());
        $this->assertSame(3, $db->table($this->cursorTable)->count());
        foreach ($db->table($this->cursorTable)->cursor() as $row) {
            break;
        }
        $this->assertSame(3, $db->table($this->cursorTable)->count());
    }

    public function testUnbufferedConsumerExceptionsAndRetainedIteratorsHaveExplicitLifetimes(): void
    {
        $db = DB::connection('unbuffered');
        try {
            $db->table($this->cursorTable)->cursor()->each(function () { throw new \RuntimeException('Stop consumer'); });
            $this->fail('The consumer did not throw.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Stop consumer', $error->getMessage());
        }
        $this->assertSame(3, $db->table($this->cursorTable)->count());
        $iterator = $db->table($this->cursorTable)->orderBy('id')->cursor()->getIterator();
        $this->assertSame(1, $iterator->current()->id);
        try {
            $db->table($this->cursorTable)->count();
            $this->fail('A query ran on a busy unbuffered connection.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('2014', $error->getMessage());
        } finally {
            unset($iterator);
        }
        $this->assertSame(3, $db->table($this->cursorTable)->count());
    }
}
