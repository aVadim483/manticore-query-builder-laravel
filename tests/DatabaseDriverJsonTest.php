<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Database\ManticoreConnection;
use avadim\Manticore\Laravel\Database\ManticoreQueryBuilder;
use InvalidArgumentException;
use LogicException;

class DatabaseDriverJsonTest extends DatabaseTestCase
{
    private function jsonQuery(): ManticoreQueryBuilder
    {
        return (new ManticoreConnection(fn () => throw new LogicException('Unexpected connection')))->table('products');
    }

    public function testArrowPathsSupportNestedKeysArrayIndexesAndSafeQuoting(): void
    {
        $query = $this->jsonQuery()->where('details->color', 'red')->where('details->sizes[0]->width', '>', 10)
            ->whereIn('details->tags->1', ['sale', 'new'])->whereNull('details->"odd.key"');
        $this->assertSame("select * from `products` where details['color'] = ? and details['sizes'][0]['width'] > ? and details['tags'][1] in (?, ?) and details['odd.key'] is null", $query->toSql());
        $this->assertSame(['red', 10, 'sale', 'new'], $query->getBindings());
        $escaped = $this->jsonQuery()->where("details->o'brien", "x' OR 1=1");
        $this->assertStringContainsString("['o\\'brien'] = ?", $escaped->toSql());
        $this->assertSame(["x' OR 1=1"], $escaped->getBindings());
    }

    public function testComputedMembershipBindingsSurviveSelectClonesAndNestedGroups(): void
    {
        $query = $this->jsonQuery()->where('stock', 1)->where(function ($q) {
            $q->whereJsonContains('details->tags', ['sale', 'new'])
                ->orWhereJsonOverlaps('details->codes', [2, 3]);
        })->whereFullText('title', 'phone')->selectRaw('? as marker', [99]);
        $this->assertSame([99, 'sale', 'new', 2, 3, '((@title (phone)))', 1, 1, 1], $query->getBindings());
        $sql = $query->toSql();
        $this->assertSame(4, substr_count($sql, 'ANY('));
        $this->assertSame(1, substr_count($sql, 'MATCH('));
        $this->assertStringContainsString(' OR ', $sql);
        $clone = (clone $query)->select('id');
        $this->assertSame(['sale', 'new', 2, 3, '((@title (phone)))', 1, 1, 1], $clone->getBindings());
        $this->assertSame($sql, $query->toSql());
        $this->assertSame(4, substr_count($clone->toSql(), 'ANY('));
    }

    public function testAllNegativeAndOrVariantsReturnTheBuilder(): void
    {
        $query = $this->jsonQuery();
        foreach (['whereJsonContains', 'orWhereJsonContains', 'whereJsonDoesntContain', 'orWhereJsonDoesntContain',
            'whereJsonOverlaps', 'orWhereJsonOverlaps', 'whereJsonDoesntOverlap', 'orWhereJsonDoesntOverlap'] as $method) {
            $this->assertSame($query, $query->$method('details->tags', 'sale'));
        }
        foreach (['whereJsonContainsKey', 'orWhereJsonContainsKey', 'whereJsonDoesntContainKey', 'orWhereJsonDoesntContainKey'] as $method) {
            $this->assertSame($query, $query->$method('details->color'));
        }
        $this->assertSame($query, $query->whereJsonLength('details->tags', 2));
        $this->assertSame($query, $query->orWhereJsonLength('details->tags', '>', 1));
        $this->assertStringContainsString('LENGTH(', $query->toSql());
    }

    public function testJsonBooleansAndKeysDoNotNeedJsonStringBindings(): void
    {
        $query = $this->jsonQuery()->where('details->active', false)
            ->whereJsonContainsKey('details->color')->orWhereJsonDoesntContainKey('details->tags[1]');
        $this->assertSame("select * from `products` where (details['active'] IS NOT NULL and details['active'] = 0) and details['color'] is not null or details['tags'][1] is null", $query->toSql());
        $this->assertSame([], $query->getBindings());
    }

    public function testUnsupportedValuesAndPathsFailBeforeConnecting(): void
    {
        foreach ([
            ['whereJsonContains', ['details->tags', null]],
            ['whereJsonContains', ['details->tags', []]],
            ['whereJsonContains', ['details->tags', ['a' => 1]]],
            ['whereJsonOverlaps', ['details->tags', [[1]]]],
            ['whereJsonContains', ['details->tags', INF]],
            ['whereJsonLength', ['details->tags', 'like', 2]],
            ['whereJsonLength', ['details->tags', -1]],
            ['whereJsonLength', ['details->tags', '2']],
            ['whereJsonContainsKey', ['details->']],
            ['whereJsonContainsKey', ['details->tags[-1]']],
            ['whereJsonContainsKey', ['details->tags[*]']],
            ['whereJsonContainsKey', ['details->tags[2147483648]']],
            ['whereJsonContainsKey', ['details;DROP TABLE products->color']],
        ] as [$method, $arguments]) {
            $query = $this->jsonQuery();
            try {
                $query->$method(...$arguments);
                $this->fail('Invalid JSON input was accepted.');
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
                $this->assertEmpty($query->wheres);
                $this->assertEmpty($query->getSearchExpressions());
            }
        }
    }

    public function testComputedJsonFiltersRejectWritesBeforeConnecting(): void
    {
        $query = $this->jsonQuery()->whereJsonContains('details->tags', 'sale');
        $this->expectException(LogicException::class);
        $query->delete();
    }
}
