<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Database\Meta;
use avadim\Manticore\Laravel\Database\ManticoreConnection;
use avadim\Manticore\Laravel\Database\ManticoreQueryBuilder;
use InvalidArgumentException;
use LogicException;

class DatabaseDriverSearchTest extends DatabaseTestCase
{
    private function searchQuery(): ManticoreQueryBuilder
    {
        return (new ManticoreConnection(fn () => throw new LogicException('Unexpected connection')))->table('products');
    }

    public function testMvaBindingsFollowNestedLaravelConditions(): void
    {
        $query = $this->searchQuery()->where('id', '>', 1)->where(function ($q) {
            $q->whereMvaAny('tags', [2, 3])->orWhereMvaAll('tags', 'BETWEEN', [4, 6]);
        });
        $this->assertSame('select * from `products` where `id` > ? and (ANY(`tags`) IN (?, ?) or ALL(`tags`) BETWEEN ? AND ?)', $query->toSql());
        $this->assertSame([1, 2, 3, 4, 6], $query->getBindings());
    }

    public function testMvaValuesDoNotLoseIntegerPrecision(): void
    {
        $query = $this->searchQuery()->whereMvaAny('tags', '9007199254740993');
        $this->assertSame([9007199254740993], $query->getBindings());
        $this->assertSame('select * from `products` where ANY(`tags`) = ?', $query->toSql());
    }

    public function testMvaValidationRejectsMalformedConditions(): void
    {
        $cases = [
            ['tags', 'IN', []], ['tags', 'BETWEEN', [1]], ['tags', '=', 1.5],
            ['tags', 'LIKE', 1], ['tags); DROP TABLE products', '=', 1], ['tags', '=', true],
            ['tags', '=', '9223372036854775808'], ['tags', [], 1],
        ];
        foreach ($cases as $case) {
            try {
                $this->searchQuery()->whereMvaAny(...$case);
                $this->fail('An invalid MVA comparison was accepted.');
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testDistanceSurvivesSelectAndAddsNoUnboundValues(): void
    {
        $query = $this->searchQuery()->withDistance('lat', 'lon', 55.75, 37.6, 'km')->select('id')
            ->selectRaw('price * ? as price', [2])->whereGeoDistance('lat', 'lon', 55.75, 37.6, 10, 'km')
            ->orderByDistance('lat', 'lon', 55.75, 37.6, 'km');
        $this->assertSame('select `id`, price * ? as price, GEODIST(`lat`, `lon`, 55.75, 37.6, {in=deg, out=km}) as `_distance` from `products` where `_distance` <= ? order by `_distance` asc', $query->toSql());
        $this->assertSame([2, 10.0], $query->getBindings());
        $this->assertSame($query->toSql(), (clone $query)->toSql());
    }

    public function testNestedGeoFiltersContributeTheirExpressionsToTheParent(): void
    {
        $query = $this->searchQuery()->where(fn ($q) => $q->whereGeoDistance('lat', 'lon', 0, 0, 100, 'km'));
        $this->assertStringContainsString('GEODIST(`lat`, `lon`, 0, 0, {in=deg, out=km}) as `__manticore_distance_', $query->toSql());
        $this->assertSame([100.0], $query->getBindings());
        $this->assertCount(1, $query->getSearchExpressions());
    }

    public function testGeoValidationRejectsInvalidCoordinatesAndAliases(): void
    {
        $cases = [
            ['lat', 'lon', 91, 0], ['lat', 'lon', 0, INF], ['lat', 'lon', NAN, 0],
            ['lat', 'lon', 0, 0, 'feet'], ['lat; DROP TABLE x', 'lon', 0, 0],
            ['lat', 'lon', 0, 0, 'km', 'id'], ['lat', 'lon', 0, 0, 'km', '__manticore_x'],
        ];
        foreach ($cases as $case) {
            try {
                $this->searchQuery()->withDistance(...$case);
                $this->fail('An invalid distance expression was accepted.');
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testMetadataIsTypedSerializableAndImmutable(): void
    {
        $meta = Meta::fromRows([
            ['Variable_name' => 'total_found', 'Value' => '42'],
            (object)['Variable_name' => 'time', 'Value' => '0.003'],
            ['Variable_name' => 'keyword[0]', 'Value' => 'galaxy'],
        ]);
        $this->assertSame(42, $meta->totalFound());
        $this->assertSame(0.003, $meta->time());
        $this->assertSame('galaxy', $meta['keyword[0]']);
        $this->assertNull($meta->total());
        $this->assertNull($meta['missing']);
        $this->assertCount(3, $meta);
        $this->assertSame($meta->toArray(), iterator_to_array($meta));
        $this->assertSame($meta->toArray(), json_decode(json_encode($meta), true));
        $this->expectException(LogicException::class);
        $meta['total_found'] = 10;
    }

    public function testSearchPretendDoesNotConnectOrChangeSelectedColumns(): void
    {
        $query = $this->searchQuery()->match('galaxy');
        $query->getConnection()->pretend(function () use ($query) {
            $result = $query->search(['id']);
            $this->assertTrue($result->rows()->isEmpty());
            $this->assertNull($result->meta()->totalFound());
        });
        $this->assertNull($query->columns);
    }

    public function testDeepWindowsRaiseMaxMatchesWithoutMutatingOptions(): void
    {
        $query = $this->searchQuery()->offset(1020)->limit(10);
        $this->assertStringEndsWith('limit 1020, 10 option max_matches=1030', $query->toSql());
        $this->assertSame([], $query->manticoreOptions);
        $query->option('max_matches', 500);
        $this->assertStringEndsWith('option max_matches=500', $query->toSql());
        $this->assertSame('select * from `products`', $this->searchQuery()->toSql());
    }
}
