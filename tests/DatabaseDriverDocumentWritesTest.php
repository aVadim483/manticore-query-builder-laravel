<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Database\ManticoreConnection;
use Closure;
use InvalidArgumentException;
use LogicException;

class DatabaseDriverDocumentWritesTest extends DatabaseTestCase
{
    private function connection(): ManticoreConnection
    {
        return new class(fn () => throw new LogicException('Unexpected connection')) extends ManticoreConnection {
            public array $rows = [];
            public array $operations = [];
            public function getTableSchema(string $table): array
            {
                return ['id' => ['type' => 'bigint', 'properties' => ''], 'sku' => ['type' => 'string', 'properties' => ''],
                    'title' => ['type' => 'text', 'properties' => 'indexed stored'], 'stock' => ['type' => 'integer', 'properties' => ''],
                    'details' => ['type' => 'json', 'properties' => ''], 'tags' => ['type' => 'multi64', 'properties' => ''],
                    'embedding' => ['type' => 'float_vector', 'properties' => '']];
            }
            public function selectFromWriteConnection($query, $bindings = [])
            {
                $this->operations[] = ['select', $query, $bindings];
                return array_map(static fn ($row) => (object) $row, array_shift($this->rows) ?? []);
            }
            public function insert($query, $bindings = [])
            {
                $this->operations[] = [str_starts_with($query, 'replace') ? 'replace' : 'insert', $query, $bindings];
                return true;
            }
            public function update($query, $bindings = [])
            {
                $this->operations[] = ['update', $query, $bindings];
                return 1;
            }
            public function transaction(Closure $callback, $attempts = 1)
            {
                $this->operations[] = ['begin'];
                $this->transactions++;
                try {
                    $result = $callback($this);
                    $this->operations[] = ['commit'];
                    return $result;
                } catch (\Throwable $error) {
                    $this->operations[] = ['rollback'];
                    throw $error;
                } finally {
                    $this->transactions--;
                }
            }
        };
    }

    private function snapshot(): array
    {
        return ['id' => 1, 'sku' => 'A', 'title' => 'old', 'stock' => 5,
            'details' => '{"empty":{}}', 'tags' => '9007199254740993,2', 'embedding' => '0.25,1,2'];
    }

    public function testUpdateOrInsertResolvesIdentityAndCallsTheCallbackWithExistence(): void
    {
        $db = $this->connection();
        $db->rows = [[['id' => 1]], []];
        $exists = [];
        $query = $db->table('products')->where('stock', '>', 0);
        $original = $query->toSql();
        $this->assertTrue($query->updateOrInsert(['sku' => 'A'], function ($found) use (&$exists) {
            $exists[] = $found;
            return ['stock' => 7];
        }));
        $this->assertTrue($db->table('products')->updateOrInsert(['sku' => 'B'], function ($found) use (&$exists) {
            $exists[] = $found;
            return ['id' => 2, 'title' => 'new'];
        }));
        $this->assertSame([true, false], $exists);
        $this->assertSame(['select', 'update', 'select', 'insert'], array_column($db->operations, 0));
        $this->assertSame([7, 1], $db->operations[1][2]);
        $this->assertStringNotContainsString('limit', $db->operations[1][1]);
        $this->assertSame($original, $query->toSql());
    }

    public function testReplaceOrInsertUsesFullReplacementAndPreservesOnlyIdentity(): void
    {
        $db = $this->connection();
        $db->rows = [[['id' => 1]], []];
        $this->assertTrue($db->table('products')->replaceOrInsert(['sku' => 'A'], ['title' => 'new']));
        $this->assertSame('replace', $db->operations[1][0]);
        $this->assertSame([1, 'A', 'new'], $db->operations[1][2]);
        $this->assertStringNotContainsString('stock', $db->operations[1][1]);
        $this->assertTrue($db->table('products')->replaceOrInsert(['sku' => 'B'], ['id' => 2]));
        $this->assertSame('insert', $db->operations[3][0]);
    }

    public function testUpsertResolvesTheWholeBatchBeforeWritingAndPreservesStoredTypes(): void
    {
        $db = $this->connection();
        $db->rows = [[$this->snapshot()], []];
        $this->assertSame(2, $db->table('products')->upsert([
            ['id' => 1, 'title' => 'changed'], ['id' => 2, 'title' => 'created'],
        ], 'id', ['title']));
        $this->assertSame(['begin', 'select', 'select', 'replace', 'insert', 'commit'], array_column($db->operations, 0));
        $this->assertStringContainsString('(9007199254740993, 2)', $db->operations[3][1]);
        $this->assertStringContainsString('(0.25, 1.0, 2.0)', $db->operations[3][1]);
        $this->assertSame(['{"empty":{}}', 1, 'A', 5, 'changed'], $db->operations[3][2]);
        $this->assertSame(0, $db->transactionLevel());
    }

    public function testEmptyUpdateColumnsUseInsertWithoutLookupAndEmptyValuesDoNothing(): void
    {
        $db = $this->connection();
        $this->assertSame(0, $db->table('products')->upsert([], 'id'));
        $this->assertSame([], $db->operations);
        $this->assertSame(1, $db->table('products')->upsert(['id' => 1, 'title' => 'new'], 'id', []));
        $this->assertSame(['begin', 'insert', 'commit'], array_column($db->operations, 0));
    }

    public function testAmbiguousLookupsRollBackBeforeAnyWrite(): void
    {
        $db = $this->connection();
        $db->rows = [[], [['id' => 1], ['id' => 2]]];
        try {
            $db->table('products')->upsert([['sku' => 'A'], ['sku' => 'B']], 'sku');
            $this->fail('An ambiguous match was accepted.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('multiple documents', $error->getMessage());
        }
        $this->assertSame(['begin', 'select', 'select', 'rollback'], array_column($db->operations, 0));
    }

    public function testInvalidInputsFailBeforeAnyLookupOrWrite(): void
    {
        $cases = [
            ['upsert', [[['id' => 1]], []]],
            ['upsert', [[['id' => 1], ['id' => 1]], 'id']],
            ['upsert', [[['id' => 1], ['id' => 2, 'title' => 'new']], 'id']],
            ['upsert', [[['id' => 1]], 'sku']],
            ['upsert', [[['id' => 1]], 'id', ['unknown']]],
            ['upsert', [[['id' => 1]], 'id', ['stock' => 2]]],
            ['upsert', [[['id' => 0]], 'id']],
            ['upsert', [[['id' => '18446744073709551616']], 'id']],
            ['upsert', [[['id' => 1, 'unknown' => 1]], 'id']],
            ['updateOrInsert', [[], ['stock' => 1]]],
            ['updateOrInsert', [['id' => 1], ['id' => 2]]],
            ['replaceOrInsert', [['id' => 1], ['title' => new \Illuminate\Database\Query\Expression('WEIGHT()')]]],
        ];
        foreach ($cases as [$method, $arguments]) {
            $db = $this->connection();
            try {
                $db->table('products')->$method(...$arguments);
                $this->fail('Invalid write input was accepted.');
            } catch (InvalidArgumentException $error) {
                $this->assertNotSame('', $error->getMessage());
                $this->assertSame([], $db->operations);
            }
        }
    }

    public function testScopedUpsertAndAfterQueryCallbacksAreRejected(): void
    {
        $db = $this->connection();
        foreach ([$db->table('products')->where('stock', 1),
            $db->table('products')->afterQuery(fn ($rows) => $rows), $db->table('products')->limit(1)] as $query) {
            try {
                $query->upsert([['id' => 1]], 'id');
                $this->fail('An unsupported query shape was accepted.');
            } catch (LogicException $error) {
                $this->assertNotSame('', $error->getMessage());
                $this->assertSame([], $db->operations);
            }
        }
    }
}
