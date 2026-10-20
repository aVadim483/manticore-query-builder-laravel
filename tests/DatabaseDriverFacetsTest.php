<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Database\ManticoreConnection;
use InvalidArgumentException;
use LogicException;

class DatabaseDriverFacetsTest extends DatabaseTestCase
{
    private function facetQuery()
    {
        return (new ManticoreConnection(fn () => throw new LogicException('Unexpected connection')))->table('products');
    }

    public function testFacetsFollowOptionsAndPreserveBindings(): void
    {
        $query = $this->facetQuery()->match('galaxy')->limit(5)->option('max_matches', 100)
            ->facet('category', 'categories', 10)->facet('info.brand', 'brands', 4, 'value', 'asc');
        $this->assertStringContainsString('limit 5 option max_matches=100 facet `category` as `categories` order by count(*) desc limit 10 facet info.brand as `brands` order by `brands` asc limit 4', $query->toSql());
        $this->assertSame(['galaxy'], $query->getBindings());
        $this->assertSame($query->toSql(), (clone $query)->toSql());
    }

    public function testEmptyFacetQueriesKeepNamedResultsAndUnknownNamesFail(): void
    {
        $query = $this->facetQuery()->facet('category');
        $result = null;
        $query->getConnection()->pretend(function () use ($query, &$result) { $result = $query->search(); });
        $this->assertTrue($result->rows()->isEmpty());
        $this->assertTrue($result->facet('category')->isEmpty());
        $this->expectException(\OutOfBoundsException::class);
        $result->facet('unknown');
    }

    public function testFacetsRequireSearchAndDoNotSilentlyDiscardResultSets(): void
    {
        foreach ([fn ($q) => $q->get(), fn ($q) => $q->cursor(), fn ($q) => $q->count(), fn ($q) => $q->delete()] as $operation) {
            try {
                $operation($this->facetQuery()->facet('category'));
                $this->fail('A facet result would be discarded.');
            } catch (LogicException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testBeforeQueryCallbacksCannotDiscardFacetResults(): void
    {
        foreach ([fn ($q) => $q->get(), fn ($q) => $q->cursor()->collect()] as $operation) {
            $query = $this->facetQuery()->beforeQuery(fn ($q) => $q->facet('category'));
            try {
                $operation($query);
                $this->fail('A before-query facet result would be discarded.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('search()', $e->getMessage());
            }
        }
    }

    public function testHighlightQuotesQueryAndOptionsAndSurvivesSelect(): void
    {
        $query = $this->facetQuery()->withHighlight(['title'], ['before_match' => "<'", 'limit' => 20], "a' OR b")
            ->select('id')->match('galaxy');
        $this->assertStringContainsString("HIGHLIGHT({before_match='<\\'', limit=20}, 'title', 'a\\' OR b') as `_highlight`", $query->toSql());
        $this->assertSame(['galaxy'], $query->getBindings());
    }

    public function testMalformedFacetAndHighlightInputsAreRejected(): void
    {
        foreach ([fn ($q) => $q->facet('x);'), fn ($q) => $q->facet('x', 'x', 0),
            fn ($q) => $q->facet('x')->facet('x'), fn ($q) => $q->withHighlight(['x);']),
            fn ($q) => $q->withHighlight([], ['limit);' => 1])] as $operation) {
            try {
                $operation($this->facetQuery());
                $this->fail('Invalid search input was accepted.');
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }
}
