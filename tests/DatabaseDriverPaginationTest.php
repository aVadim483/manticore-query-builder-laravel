<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Database\ManticoreConnection;
use Illuminate\Pagination\Cursor;
use InvalidArgumentException;
use LogicException;

class DatabaseDriverPaginationTest extends DatabaseTestCase
{
    private function paginationQuery()
    {
        $db = new class(fn () => throw new LogicException('Unexpected connection')) extends ManticoreConnection {
            public function getTableSchema(string $table): array
            {
                return ['id' => ['type' => 'bigint'], 'category' => ['type' => 'integer'],
                    'price' => ['type' => 'float'], 'title' => ['type' => 'string'], 'tags' => ['type' => 'mva']];
            }
        };
        return $db->table('products');
    }

    public function testCursorPaginationAddsAStableIdTieBreakerWithoutMutatingTheSource(): void
    {
        $query = $this->paginationQuery()->select('id', 'category')->orderBy('category');
        $sql = $query->toSql();
        $log = $query->getConnection()->pretend(fn () => $query->cursorPaginate(2, ['*'], 'cursor', new Cursor(['category' => 1, 'id' => 5])));
        $this->assertStringContainsString('order by `category` asc, `id` asc limit 3', $log[0]['query']);
        $this->assertSame([1, 1, 5], $log[0]['bindings']);
        $this->assertSame($sql, $query->toSql());
        $this->assertSame([], $query->getBindings());
    }

    public function testUnsupportedCursorOrdersAndQueryShapesFailExplicitly(): void
    {
        foreach ([fn ($q) => $q->orderBy('title'), fn ($q) => $q->orderBy('tags'), fn ($q) => $q->orderBy('price'),
            fn ($q) => $q->orderByRaw('id desc'), fn ($q) => $q->select('category')->orderBy('category'),
            fn ($q) => $q->selectRaw('id + 1 as id')->orderBy('id'),
            fn ($q) => $q->groupBy('category'), fn ($q) => $q->facet('category'),
            fn ($q) => $q->knn('embedding', [1]), fn ($q) => $q->offset(2)] as $configure) {
            try {
                $configure($this->paginationQuery())->cursorPaginate(2);
                $this->fail('Unsupported cursor pagination was accepted.');
            } catch (LogicException $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testCursorValuesAndSizesAreValidatedBeforeExecution(): void
    {
        foreach ([new Cursor(['id' => null]), new Cursor(['other' => 1]), new Cursor(['id' => '1 OR 1']),
            new Cursor(['id' => 1.5]), new Cursor(['id' => '9223372036854775808']), 'not-a-cursor'] as $cursor) {
            try {
                $this->paginationQuery()->cursorPaginate(2, ['*'], 'cursor', $cursor);
                $this->fail('Invalid cursor input was accepted.');
            } catch (InvalidArgumentException $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        }
        foreach ([0, -1, PHP_INT_MAX] as $size) {
            try {
                $this->paginationQuery()->cursorPaginate($size);
                $this->fail('Invalid cursor page size was accepted.');
            } catch (InvalidArgumentException $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testExactCountsRejectCandidateQueriesAndEarlyCutoffs(): void
    {
        foreach ([fn ($q) => $q->knn('embedding', [1]), fn ($q) => $q->facet('category'),
            fn ($q) => $q->option('cutoff', 10), fn ($q) => $q->groupBy('tags')] as $configure) {
            try {
                $configure($this->paginationQuery())->getCountForPagination();
                $this->fail('An unsupported exact count was accepted.');
            } catch (LogicException $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testMultipleHavingConditionsAreRejectedBeforeExecution(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('single HAVING');
        $this->paginationQuery()->select('category')->selectRaw('count(*) as n')->groupBy('category')
            ->having('n', '>', 1)->having('n', '<', 10)->toSql();
    }
}
