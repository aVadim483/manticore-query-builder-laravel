<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Connection as LaravelConnection;
use avadim\Manticore\Laravel\Facade;
use avadim\Manticore\Laravel\Query as LaravelQuery;
use avadim\Manticore\Laravel\Row;
use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Database\RecordsNotFoundException;
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

    public function testFirstOrFailReturnsARow(): void
    {
        $row = ManticoreDb::table($this->table)->where('brand', 'acme')->firstOrFail();

        $this->assertInstanceOf(Row::class, $row);
        $this->assertSame('red apple', $row->title);
    }

    /**
     * The exception is the one Laravel answers with a 404, so a controller needs no check of its
     * own - and it is a RuntimeException, so code catching that keeps working
     */
    public function testFirstOrFailThrowsWhenNothingMatches(): void
    {
        $this->expectException(RecordsNotFoundException::class);

        ManticoreDb::table($this->table)->where('brand', 'nobody')->firstOrFail();
    }

    public function testFindOrFailReturnsARow(): void
    {
        $id = (int)ManticoreDb::table($this->table)->where('brand', 'acme')->first()->id;

        $this->assertSame('red apple', ManticoreDb::table($this->table)->findOrFail($id)->title);
    }

    public function testFindOrFailThrowsWhenThereIsNoSuchId(): void
    {
        $this->expectException(RecordsNotFoundException::class);

        ManticoreDb::table($this->table)->findOrFail(999999);
    }

    public function testSoleTellsTheTwoFailuresApart(): void
    {
        $row = ManticoreDb::table($this->table)->where('brand', 'acme')->sole();
        $this->assertSame('red apple', $row->title);

        try {
            ManticoreDb::table($this->table)->where('price', '>', 1)->sole();
            $this->fail('Two matching rows did not raise MultipleRecordsFoundException.');
        }
        catch (MultipleRecordsFoundException $e) {
            $this->assertSame(2, $e->count);
        }

        $this->expectException(RecordsNotFoundException::class);
        ManticoreDb::table($this->table)->where('brand', 'nobody')->sole();
    }

    public function testTheFacadeAnswersTheSameWay(): void
    {
        $rows = Facade::table($this->table)->get();

        $this->assertInstanceOf(Collection::class, $rows);
        $this->assertContainsOnlyInstancesOf(Row::class, $rows);
    }

    /**
     * weight() is 1 without a full-text match, so the column is not selected there - the row
     * of a plain read matches the table and can be handed over as it is
     */
    public function testARowOfAPlainReadCarriesNoScore(): void
    {
        $row = ManticoreDb::table($this->table)->where('brand', 'acme')->first();

        $this->assertFalse($row->has('_score'));
        $this->assertSame(['id', 'title', 'brand', 'price'], array_keys($row->toArray()));
    }

    public function testARowOfAFullTextQueryCarriesTheScore(): void
    {
        $row = ManticoreDb::table($this->table)->match('apple')->first();

        $this->assertTrue($row->has('_score'));
        $this->assertGreaterThan(0, $row->_score);
    }

    public function testTheScoreCanBeAskedForAndDroppedExplicitly(): void
    {
        $withScore = ManticoreDb::table($this->table)->where('brand', 'acme')->withScore()->first();
        $withoutScore = ManticoreDb::table($this->table)->match('apple')->withoutScore()->first();

        $this->assertTrue($withScore->has('_score'));
        $this->assertFalse($withoutScore->has('_score'));
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
