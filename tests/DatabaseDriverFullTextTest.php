<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Database\ManticoreConnection;
use avadim\Manticore\Laravel\Database\ManticoreQueryBuilder;
use InvalidArgumentException;
use LogicException;

class DatabaseDriverFullTextTest extends DatabaseTestCase
{
    private function fullTextQuery(): ManticoreQueryBuilder
    {
        return (new ManticoreConnection(fn () => throw new LogicException('Unexpected connection')))->table('products');
    }

    public function testFullTextIsFluentAndKeepsBindingsInSqlOrderWithoutMutatingTheBuilder(): void
    {
        $query = $this->fullTextQuery()->selectRaw('? as marker', [99])->where('stock', '>', 2);
        $this->assertSame($query, $query->whereFullText(['title', 'body'], 'phone | tablet'));
        $query->where(fn ($q) => $q->where('id', 1)->orWhere('id', 3));
        $this->assertSame('select ? as marker from `products` where MATCH(?) and `stock` > ? and (`id` = ? or `id` = ?)', $query->toSql());
        $expected = [99, '((@(title,body) (phone \\| tablet)))', 2, 1, 3];
        $this->assertSame($expected, $query->getBindings());
        $this->assertSame($expected, $query->getBindings());
        $clone = (clone $query)->whereFullText('title', 'galaxy');
        $this->assertSame(1, substr_count($clone->toSql(), 'MATCH('));
        $this->assertSame($expected, $query->getBindings());
        $this->assertStringContainsString('@title (galaxy)', $clone->getBindings()[1]);
    }

    public function testTextOrGroupsKeepSqlPrecedenceAndNestedFieldScope(): void
    {
        $query = $this->fullTextQuery()->where(function ($q) {
            $q->whereFullText('title', 'phone')->orWhereFullText('body', 'tablet');
        })->whereFullText('body', 'galaxy', ['escape' => false])->where('stock', 1);
        $this->assertSame('select * from `products` where MATCH(?) and `stock` = ?', $query->toSql());
        $this->assertSame(['((((@title (phone))) | ((@body (tablet)))) (@body (galaxy)))', 1], $query->getBindings());
    }

    public function testUnsupportedBooleanTreesAndRawMatchMixingFailBeforeConnecting(): void
    {
        $queries = [
            $this->fullTextQuery()->whereFullText('title', 'phone')->orWhere('stock', 1),
            $this->fullTextQuery()->where('stock', 1)->orWhereFullText('title', 'phone'),
            $this->fullTextQuery()->where(fn ($q) => $q->whereFullText('title', 'phone')->where('stock', 1))->orWhereFullText('body', 'tablet'),
            $this->fullTextQuery()->match('phone')->whereFullText('title', 'phone'),
            $this->fullTextQuery()->whereNot(fn ($q) => $q->whereFullText('title', 'phone')),
            $this->fullTextQuery()->whereFullText('title', 'phone')->orWhereNot(fn ($q) => $q->where('stock', 1)),
        ];
        foreach ($queries as $query) {
            try {
                $query->toSql();
                $this->fail('An unsupported full-text condition was accepted.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('whereFullText()', $e->getMessage());
            }
        }
    }

    public function testValidationAndLiteralEscaping(): void
    {
        foreach ([
            [[], 'phone'], [['title' => 'body'], 'phone'], ['title) | @body', 'phone'],
            ['title', ' '], ['title', 123], ['title', 'phone', ['language' => 'en']],
            ['title', 'phone', ['mode' => 'boolean']], ['title', 'phone', ['escape' => null]],
            ['title', 'phone', ['escape' => 1]], ['title', 'phone', [], 'xor'],
        ] as $arguments) {
            $query = $this->fullTextQuery();
            try {
                $query->whereFullText(...$arguments);
                $this->fail('Invalid full-text input was accepted.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('whereFullText()', $e->getMessage());
                $this->assertEmpty($query->wheres);
            }
        }
        $text = 'phone | tablet -old @body (new)';
        $this->assertSame(['((@title (phone \\| tablet \\-old \\@body \\(new\\))))'], $this->fullTextQuery()->whereFullText('title', $text)->getBindings());
        $this->assertSame(['((@title (phone | tablet)))'], $this->fullTextQuery()->whereFullText('title', 'phone | tablet', ['escape' => false])->getBindings());
    }

    public function testWritesUseTheSameNormalizedBindings(): void
    {
        $query = $this->fullTextQuery()->where('stock', '>', 2)->whereFullText('title', 'phone');
        $log = $query->getConnection()->pretend(function () use ($query) {
            (clone $query)->update(['stock' => 4]);
            (clone $query)->delete(7);
        });
        $this->assertStringContainsString('update `products` set `stock` =', $log[0]['query']);
        $this->assertSame([4, '((@title (phone)))', 2], $log[0]['bindings']);
        $this->assertStringContainsString('delete from `products` where MATCH(', $log[1]['query']);
        $this->assertSame(['((@title (phone)))', 2, 7], $log[1]['bindings']);
    }

    public function testBeforeQueryCallbacksCanAddFullTextToWrites(): void
    {
        $query = $this->fullTextQuery()->where('stock', 1)
            ->beforeQuery(fn ($q) => $q->whereFullText('title', 'phone'));
        $log = $query->getConnection()->pretend(function () use ($query) {
            (clone $query)->update(['stock' => 4]);
            (clone $query)->delete();
        });
        $this->assertSame([4, '((@title (phone)))', 1], $log[0]['bindings']);
        $this->assertSame(['((@title (phone)))', 1], $log[1]['bindings']);
    }
}
