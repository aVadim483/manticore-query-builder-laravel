<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use Illuminate\Support\Facades\DB;

class DatabaseDriverFullTextIntegrationTest extends DatabaseTestCase
{
    private ?string $fullTextTable = null;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('database.connections.manticore', [
            'driver' => 'manticore', 'host' => $this->serverHost(), 'port' => $this->serverPort(), 'timeout' => 3,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresServer();
        $this->fullTextTable = $this->tableName('fulltext');
        $db = DB::connection('manticore');
        $db->statement("CREATE TABLE {$this->fullTextTable} (title text, body text, stock int)");
        $db->table($this->fullTextTable)->insert([
            ['id' => 1, 'title' => 'galaxy phone', 'body' => 'new device', 'stock' => 1],
            ['id' => 2, 'title' => 'galaxy tablet', 'body' => 'phone accessory', 'stock' => 2],
            ['id' => 3, 'title' => 'apple phone', 'body' => 'old device', 'stock' => 0],
        ]);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->fullTextTable !== null) {
                DB::connection('manticore')->statement("DROP TABLE IF EXISTS {$this->fullTextTable}");
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testFieldScopingRepeatedPredicatesAndTerminalMethods(): void
    {
        $query = DB::connection('manticore')->table($this->fullTextTable);
        $this->assertSame([1, 3], (clone $query)->whereFullText('title', 'phone')->orderBy('id')->pluck('id')->all());
        $this->assertSame([1, 2, 3], (clone $query)->whereFullText(['title', 'body'], 'phone')->orderBy('id')->pluck('id')->all());
        $and = (clone $query)->whereFullText('title', 'galaxy')->whereFullText('body', 'phone');
        $this->assertSame(2, $and->first()->id);
        $this->assertSame(1, $and->count());
        $this->assertSame(1, $and->search()->meta()->totalFound());
        $this->assertSame(2, $and->paginate(1)->items()[0]->id);
        $this->assertSame([1, 2], (clone $query)->where('stock', '>', 0)->where(function ($q) {
            $q->whereFullText('title', 'phone')->orWhereFullText('body', 'phone');
        })->orderBy('id')->pluck('id')->all());
    }

    public function testOrPrecedenceAndLiteralOperators(): void
    {
        $query = DB::connection('manticore')->table($this->fullTextTable);
        // A OR (B AND C), not (A OR B) AND C.
        $this->assertSame([1, 2], (clone $query)->whereFullText('title', 'tablet')
            ->orWhereFullText('title', 'phone')->whereFullText('body', 'new')->orderBy('id')->pluck('id')->all());
        $this->assertSame([1], (clone $query)->whereFullText('title', 'galaxy | phone')->pluck('id')->all());
        $this->assertSame([1, 2, 3], (clone $query)->whereFullText('title', 'galaxy | phone', ['escape' => false])->orderBy('id')->pluck('id')->all());
    }

    public function testFullTextWritesAndCursorPreserveTheSourceQuery(): void
    {
        $query = DB::connection('manticore')->table($this->fullTextTable)->where('stock', '>', 0)->whereFullText('title', 'phone');
        $this->assertSame(1, (clone $query)->update(['stock' => 7]));
        $this->assertSame(7, (clone $query)->first()->stock);
        $this->assertSame([1], (clone $query)->orderBy('id')->cursor()->pluck('id')->all());
        $this->assertSame(1, (clone $query)->delete());
        $this->assertCount(0, $query->get());
    }
}
