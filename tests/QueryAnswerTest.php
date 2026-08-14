<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Connection as LaravelConnection;
use avadim\Manticore\Laravel\Facade;
use avadim\Manticore\Laravel\Query as LaravelQuery;
use avadim\Manticore\Laravel\Row;
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use Illuminate\Support\Collection;

/**
 * What the reads answer with inside Laravel: a Collection of Row objects instead of the plain
 * array of the standalone builder. Both entry points must answer alike.
 */
class QueryAnswerTest extends TestCase
{
    /**
     * @var string|null
     */
    protected $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresServer();

        $this->table = $this->tableName('answers');

        ManticoreDb::create($this->table, [
            'title' => 'text',
            'brand' => 'string',
            'price' => 'float',
        ]);

        ManticoreDb::table($this->table)->insert(['title' => 'red apple', 'brand' => 'acme', 'price' => 10.0]);
        ManticoreDb::table($this->table)->insert(['title' => 'green pear', 'brand' => 'other', 'price' => 20.0]);
    }

    protected function tearDown(): void
    {
        if ($this->table) {
            ManticoreDb::dropIfExists($this->table);
            $this->table = null;
        }

        parent::tearDown();
    }

    public function testTheBuilderHandsOutThePackageClasses(): void
    {
        $this->assertInstanceOf(LaravelConnection::class, ManticoreDb::connection());
        $this->assertInstanceOf(LaravelQuery::class, ManticoreDb::table($this->table));
        $this->assertInstanceOf(LaravelQuery::class, Facade::table($this->table));
    }

    public function testGetReturnsACollectionOfRows(): void
    {
        $rows = ManticoreDb::table($this->table)->get();

        $this->assertInstanceOf(Collection::class, $rows);
        $this->assertCount(2, $rows);
        $this->assertContainsOnlyInstancesOf(Row::class, $rows);
    }

    /**
     * The builder keys its array by the document id; a Collection of a Laravel query is a list,
     * so json_encode() gives an array and $rows[0] is the first row
     */
    public function testTheCollectionIsKeyedFromZero(): void
    {
        $rows = ManticoreDb::table($this->table)->get();

        $this->assertSame([0, 1], $rows->keys()->all());
        $this->assertInstanceOf(Row::class, $rows[0]);
        $this->assertStringStartsWith('[', (string)$rows->toJson());
    }

    public function testTheCollectionApiIsAvailable(): void
    {
        $titles = ManticoreDb::table($this->table)
            ->orderBy('price')
            ->get()
            ->map(function (Row $row) {
                return $row->title;
            })
            ->all();

        $this->assertSame(['red apple', 'green pear'], $titles);
    }

    public function testGetWithColumnsSelectsThem(): void
    {
        $row = ManticoreDb::table($this->table)->get('title')->first();

        $this->assertSame(['title'], array_keys($row->toArray()));
    }

    public function testPluckReturnsACollection(): void
    {
        $titles = ManticoreDb::table($this->table)->orderBy('price')->pluck('title');

        $this->assertInstanceOf(Collection::class, $titles);
        $this->assertSame(['red apple', 'green pear'], $titles->all());
    }

    public function testPluckKeyedByAnotherColumn(): void
    {
        $titles = ManticoreDb::table($this->table)->pluck('title', 'brand');

        $this->assertInstanceOf(Collection::class, $titles);
        $this->assertSame('red apple', $titles->get('acme'));
        $this->assertSame('green pear', $titles->get('other'));
    }

    public function testFirstReturnsARow(): void
    {
        $row = ManticoreDb::table($this->table)->where('brand', 'acme')->first();

        $this->assertInstanceOf(Row::class, $row);
        $this->assertSame('red apple', $row->title);
        $this->assertSame('red apple', $row['title']);
    }

    public function testFirstOfAnEmptyResultIsNull(): void
    {
        $this->assertNull(ManticoreDb::table($this->table)->where('brand', 'nobody')->first());
    }

    public function testFindReturnsARow(): void
    {
        $id = ManticoreDb::table($this->table)->where('brand', 'acme')->first()->id;

        $found = ManticoreDb::table($this->table)->find((int)$id);

        $this->assertInstanceOf(Row::class, $found);
        $this->assertSame('red apple', $found->title);
    }

    public function testTheFacadeAnswersTheSameWay(): void
    {
        $rows = Facade::table($this->table)->get();

        $this->assertInstanceOf(Collection::class, $rows);
        $this->assertContainsOnlyInstancesOf(Row::class, $rows);
    }

    /**
     * The writes are inherited untouched, so they keep the scalar answers of the builder
     */
    public function testWritesStillAnswerWithScalars(): void
    {
        $this->assertTrue(ManticoreDb::table($this->table)->insert(['title' => 'blue plum', 'price' => 30.0]));
        $this->assertSame(1, ManticoreDb::table($this->table)->where('brand', 'other')->update(['price' => 25.0]));
        $this->assertSame(1, ManticoreDb::table($this->table)->where('brand', 'other')->delete());
    }
}
