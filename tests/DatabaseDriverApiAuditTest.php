<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Database\ManticoreConnection;
use avadim\Manticore\Laravel\Database\ManticoreQueryBuilder;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionUnionType;

class DatabaseDriverApiAuditTest extends DatabaseTestCase
{
    private function auditQuery(): ManticoreQueryBuilder
    {
        return (new ManticoreConnection(fn () => throw new LogicException('Unexpected PDO connection')))->table('products');
    }

    public function testEveryPublicRuntimeMethodHasOneReviewedSupportStatus(): void
    {
        $catalog = json_decode(file_get_contents(dirname(__DIR__).'/docs/laravel-query-builder-api.json'), true, 512, JSON_THROW_ON_ERROR);
        $reviewed = [];
        foreach ($catalog['groups'] as $group) {
            $this->assertNotSame('', $group['notes']);
            foreach ($group['methods'] as $method) {
                $this->assertArrayNotHasKey($method, $reviewed, 'Duplicate API status: '.$method);
                $reviewed[$method] = $group['status'];
            }
        }
        foreach ((new ReflectionClass(ManticoreQueryBuilder::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->name, '__') || ($method->isStatic() && $method->getDeclaringClass()->getName() !== ManticoreQueryBuilder::class)) {
                continue;
            }
            $this->assertArrayHasKey($method->name, $reviewed, 'Unreviewed Laravel/driver API: '.$method->name);
        }
    }

    public function testNewMethodParameterNamesDefaultsAndDeclaredTypesAreFrozen(): void
    {
        $contracts = json_decode(file_get_contents(dirname(__DIR__).'/docs/query-builder-contracts.json'), true, 512, JSON_THROW_ON_ERROR);
        $type = static function ($value): ?string {
            if ($value === null) return null;
            if ($value instanceof ReflectionUnionType) {
                $names = array_map('strval', $value->getTypes());
                sort($names);
                return implode('|', $names);
            }
            return (string) $value;
        };
        foreach ($contracts['methods'] as $name => $expected) {
            $method = new ReflectionMethod(ManticoreQueryBuilder::class, $name);
            $actual = [];
            foreach ($method->getParameters() as $parameter) {
                $item = ['name' => $parameter->name, 'type' => $type($parameter->getType())];
                if ($parameter->isDefaultValueAvailable()) $item['default'] = $parameter->getDefaultValue();
                $actual[] = $item;
            }
            $this->assertSame($expected['parameters'], $actual, $name);
            $this->assertSame($expected['declared_return'], $type($method->getReturnType()), $name);
        }
    }

    public function testReadOnlyClausesCanNeverBeIgnoredByWrites(): void
    {
        $clauses = [fn ($q) => $q->distinct(), fn ($q) => $q->groupBy('stock'), fn ($q) => $q->having('n', '>', 1),
            fn ($q) => $q->union($this->auditQuery()), fn ($q) => $q->lockForUpdate(), fn ($q) => $q->useIndex('stock'),
            fn ($q) => $q->select('id'), fn ($q) => $q->orderBy('id'), fn ($q) => $q->limit(1),
            fn ($q) => $q->offset(1), fn ($q) => $q->groupLimit(1, 'stock')];
        foreach ($clauses as $configure) {
            foreach (['update', 'delete', 'insert', 'replace', 'truncate'] as $operation) {
                try {
                    $query = $configure($this->auditQuery());
                    if ($operation === 'update') $query->update(['stock' => 1]);
                    elseif (in_array($operation, ['insert', 'replace'], true)) $query->$operation(['id' => 1]);
                    else $query->$operation();
                    $this->fail('A read-only clause was ignored by '.$operation);
                } catch (LogicException $error) {
                    $this->assertStringNotContainsString('Unexpected PDO', $error->getMessage());
                }
            }
        }
        foreach (['insert', 'replace', 'truncate'] as $operation) {
            try {
                $query = $this->auditQuery()->where('id', 1);
                $operation === 'truncate' ? $query->truncate() : $query->$operation(['id' => 2]);
                $this->fail('WHERE was ignored by '.$operation);
            } catch (LogicException $error) {
                $this->assertStringNotContainsString('Unexpected PDO', $error->getMessage());
            }
        }
    }

    public function testUnsupportedInheritedFamiliesFailBeforeAnyConnection(): void
    {
        $cases = [
            fn ($q) => $q->where('title', 'like', '%phone%')->get(),
            fn ($q) => $q->whereLike('title', 'phone')->get(),
            fn ($q) => $q->whereDate('created', '2026-01-01')->get(),
            fn ($q) => $q->whereYear('created', 2026)->get(),
            fn ($q) => $q->whereColumn('stock', 'price')->get(),
            fn ($q) => $q->whereBetweenColumns('stock', ['min', 'max'])->get(),
            fn ($q) => $q->whereRowValues(['stock', 'price'], '=', [1, 2])->get(),
            fn ($q) => $q->whereIn('id', $this->auditQuery()->select('id'))->get(),
            fn ($q) => $q->where('stock', '=', fn ($sub) => $sub->from('products')->select('stock'))->get(),
            fn ($q) => $q->selectSub($this->auditQuery(), 'x')->get(),
            fn ($q) => $q->fromSub($this->auditQuery(), 'x')->get(),
            fn ($q) => $q->inRandomOrder(42)->get(),
            fn ($q) => $q->inOrderOf('id', [2, 1])->get(),
            fn ($q) => $q->explain(),
            fn ($q) => $q->timeout(5)->get(),
            fn ($q) => $q->increment('stock'),
            fn ($q) => $q->decrementEach(['stock' => 1]),
            fn ($q) => $q->insertUsing(['id'], $this->auditQuery()->select('id')),
            fn ($q) => $q->insertOrIgnore(['id' => 1]),
            fn ($q) => $q->forceIndex('stock')->get(),
            fn ($q) => $q->havingNull('n')->get(),
        ];
        if (method_exists(Builder::class, 'wherePast')) $cases[] = fn ($q) => $q->wherePast('created')->get();
        if (method_exists(Builder::class, 'whereBinary')) $cases[] = fn ($q) => $q->whereBinary('sku', 'A')->get();
        if (method_exists(Builder::class, 'whereValueBetween')) $cases[] = fn ($q) => $q->whereValueBetween(1, ['min', 'max'])->get();
        if (method_exists(Builder::class, 'whereVectorSimilarTo')) $cases[] = fn ($q) => $q->whereVectorSimilarTo('embedding', 'must not request embeddings');
        if (method_exists(Builder::class, 'fetchUsing')) {
            $cases[] = fn ($q) => $q->fetchUsing(\PDO::FETCH_ASSOC)->search();
            $cases[] = fn ($q) => $q->beforeQuery(fn ($builder) => $builder->fetchUsing(\PDO::FETCH_ASSOC))->search();
            $cases[] = fn ($q) => $q->fetchUsing(\PDO::FETCH_ASSOC)->hybridGet('phone', 'embedding', [1, 0, 0]);
        }
        foreach ($cases as $case) {
            try {
                $case($this->auditQuery());
                $this->fail('An unsupported inherited method was accepted.');
            } catch (LogicException $error) {
                $this->assertStringNotContainsString('Unexpected PDO', $error->getMessage());
            }
        }
    }

    public function testCompiledBindingGroupsAndNestedSearchConditionsStayAligned(): void
    {
        $query = $this->auditQuery()->where('stock', 2)->where(function ($nested) {
            $nested->whereFullText('title', 'phone')->whereJsonContains('details->tags', 'sale');
        });
        $groups = $query->getRawBindings();
        $this->assertSame(['sale'], $groups['select']);
        $this->assertSame(3, count($groups['where']));
        $this->assertSame([2, 1], array_slice($groups['where'], 1));
        $this->assertSame(array_merge($groups['select'], $groups['where']), $query->getBindings());
        $this->assertSame($groups, $query->getRawBindings());
        $this->assertSame(4, substr_count($query->toSql(), '?'));
        $this->expectException(LogicException::class);
        $this->auditQuery()->mergeBindings($query);
    }

    public function testMalformedPayloadsAndJsonAssignmentsCannotProduceMisalignedWrites(): void
    {
        foreach (['insert', 'replace'] as $method) {
            try {
                $this->auditQuery()->$method([['id' => 1, 'title' => 'one'], ['id' => 2, 'stock' => 9]]);
                $this->fail('Different batch columns were accepted.');
            } catch (InvalidArgumentException $error) {
                $this->assertStringContainsString('identical column sets', $error->getMessage());
            }
        }
        foreach ([fn ($q) => $q->update(['details->color' => 'red']), fn ($q) => $q->update(['id' => 2]),
            fn ($q) => $q->match('one')->match('two')->get()] as $operation) {
            try {
                $operation($this->auditQuery());
                $this->fail('An unsupported write/search contract was accepted.');
            } catch (LogicException $error) {
                $this->assertStringNotContainsString('Unexpected PDO', $error->getMessage());
            }
        }
        $this->assertSame(0, $this->auditQuery()->upsert([], []));
        $this->assertTrue($this->auditQuery()->insert([]));
        $this->assertTrue($this->auditQuery()->replace([]));
    }
}
