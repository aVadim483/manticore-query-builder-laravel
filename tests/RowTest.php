<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Row;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;

/**
 * The row object. No server is involved.
 */
class RowTest extends TestCase
{
    /**
     * @return Row
     */
    protected function row(): Row
    {
        return new Row(['id' => 12, 'title' => 'Galaxy S10', 'price' => 900.0]);
    }

    public function testColumnsAreReadableAsProperties(): void
    {
        $row = $this->row();

        $this->assertSame('Galaxy S10', $row->title);
        $this->assertSame(900.0, $row->price);
    }

    /**
     * The array form is what the standalone builder answers with, code written against it
     * must keep working
     */
    public function testColumnsAreReadableAsArrayKeys(): void
    {
        $row = $this->row();

        $this->assertSame('Galaxy S10', $row['title']);
        $this->assertTrue(isset($row['title']));
        $this->assertFalse(isset($row['nope']));
    }

    public function testMissingColumnIsNull(): void
    {
        $row = $this->row();

        $this->assertNull($row->nope);
        $this->assertNull($row['nope']);
        $this->assertSame('fallback', $row->get('nope', 'fallback'));
        $this->assertFalse($row->has('nope'));
    }

    public function testItIsCountableAndIterable(): void
    {
        $row = $this->row();

        $this->assertCount(3, $row);
        $this->assertSame(['id', 'title', 'price'], array_keys(iterator_to_array($row)));
    }

    public function testItConvertsToArrayAndJson(): void
    {
        $row = $this->row();

        $this->assertInstanceOf(Arrayable::class, $row);
        $this->assertInstanceOf(Jsonable::class, $row);
        $this->assertSame(['id' => 12, 'title' => 'Galaxy S10', 'price' => 900.0], $row->toArray());
        $this->assertSame('{"id":12,"title":"Galaxy S10","price":900}', $row->toJson());
        $this->assertSame('{"id":12,"title":"Galaxy S10","price":900}', json_encode($row));
    }

    public function testValuesCanBeChanged(): void
    {
        $row = $this->row();

        $row->title = 'Galaxy S20';
        $row['price'] = 1000.0;
        unset($row['id']);

        $this->assertSame(['title' => 'Galaxy S20', 'price' => 1000.0], $row->toArray());
    }
}
