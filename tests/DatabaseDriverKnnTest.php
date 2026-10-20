<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Database\ManticoreConnection;
use InvalidArgumentException;
use LogicException;

class DatabaseDriverKnnTest extends DatabaseTestCase
{
    private function knnQuery()
    {
        return (new ManticoreConnection(fn () => throw new LogicException('Unexpected connection')))->table('products');
    }

    public function testKnnCombinesWithBoundFiltersAndSurvivesSelect(): void
    {
        $query = $this->knnQuery()->knn('embedding', [1, -2.5, 0], 5, ['ef' => 100, 'rescore' => true])
            ->withKnnDistance()->select('id')->match('galaxy')->where('stock', '>', 0)->orderByKnnDistance();
        $this->assertStringContainsString('KNN(`embedding`, 5, (1, -2.5, 0), {ef=100, rescore=1})', $query->toSql());
        $this->assertStringContainsString('KNN_DIST() as `_knn_distance`', $query->toSql());
        $this->assertStringContainsString('limit 5', $query->toSql());
        $this->assertSame(['galaxy', 0], $query->getBindings());
    }

    public function testKnnRejectsInvalidVectorsAndOptions(): void
    {
        foreach ([['x', [], 1], ['x', [INF], 1], ['x', ['1'], 1], ['x', [1], 0], ['x);', [1], 1],
            ['x', [1], 1, ['ef' => 0]], ['x', [1], 1, ['unknown' => 1]]] as $args) {
            try {
                $this->knnQuery()->knn(...$args);
                $this->fail('Invalid KNN input was accepted.');
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testNestedOrAndDuplicateKnnAreRejected(): void
    {
        foreach ([fn () => $this->knnQuery()->knn('x', [1])->knn('x', [2]),
            fn () => $this->knnQuery()->where(fn ($q) => $q->knn('x', [1]))->toSql(),
            fn () => $this->knnQuery()->knn('x', [1])->orWhere('id', 1)->toSql(),
            fn () => $this->knnQuery()->knn('x', [1])->limit(null)->delete()] as $operation) {
            try {
                $operation();
                $this->fail('Unsupported KNN composition was accepted.');
            } catch (LogicException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }
}
