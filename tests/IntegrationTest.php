<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Facade;
use avadim\Manticore\Laravel\Tests\Support\ArrayLogger;
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use avadim\Manticore\QueryBuilder\Schema\SchemaTable;

/**
 * End to end scenario from the README, executed against a real server.
 * Skipped when no server is reachable.
 */
class IntegrationTest extends TestCase
{
    /**
     * @var string|null
     */
    protected $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresServer();

        $this->table = $this->tableName('products');

        ManticoreDb::create($this->table, function (SchemaTable $table) {
            $table->timestamp('created_at');
            $table->string('manufacturer');
            $table->text('title');
            $table->float('price');
            $table->bool('on_sale');
        });

        ManticoreDb::table($this->table)->insert([
            'created_at'   => 1700000000,
            'manufacturer' => 'Samsung',
            'title'        => 'Galaxy S10',
            'price'        => 900.0,
            'on_sale'      => true,
        ]);
        ManticoreDb::table($this->table)->insert([
            'created_at'   => 1700000001,
            'manufacturer' => 'Apple',
            'title'        => 'iPhone 12',
            'price'        => 1200.0,
            'on_sale'      => false,
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

    public function testTheTableIsVisibleThroughTheStaticBuilder(): void
    {
        $this->assertTrue(ManticoreDb::hasTable($this->table));
        $this->assertContains($this->table, array_column(ManticoreDb::showTables(), 'Table'));
    }

    public function testMatchAndWhereReturnTheMatchingRow(): void
    {
        $rows = ManticoreDb::table($this->table)
            ->match('galaxy')
            ->where('price', '<', 1000)
            ->get();

        $this->assertCount(1, $rows);

        $row = $rows->first();
        $this->assertSame('Samsung', $row->manufacturer);
        $this->assertSame('Galaxy S10', $row['title']);
    }

    public function testWhereFiltersWithoutFullTextMatch(): void
    {
        $rows = ManticoreDb::table($this->table)->where('price', '>', 1000)->get();

        $this->assertCount(1, $rows);
        $this->assertSame('Apple', $rows->first()->manufacturer);
    }

    /**
     * The container path (facade -> Manager -> connection) must see the same data
     * as the static builder used above
     */
    public function testTheSameDataIsReachableThroughTheFacade(): void
    {
        $rows = Facade::table($this->table)->where('manufacturer', 'Apple')->get();

        $this->assertCount(1, $rows);
        $this->assertSame('iPhone 12', $rows->first()->title);
    }

    public function testDropRemovesTheTable(): void
    {
        ManticoreDb::drop($this->table);

        $this->assertFalse(ManticoreDb::hasTable($this->table));

        $this->table = null;
    }

    public function testLoggerSetOnASingleQuery(): void
    {
        $logger = new ArrayLogger();

        ManticoreDb::table($this->table)->setLogger($logger)->where('manufacturer', 'Apple')->get();

        $this->assertNotEmpty($logger->recordsOfLevel('info'));

        $logger->reset();

        ManticoreDb::table($this->table)->where('manufacturer', 'Apple')->get();

        $this->assertSame([], $logger->records, 'The logger must not leak to the next query.');
    }
}
