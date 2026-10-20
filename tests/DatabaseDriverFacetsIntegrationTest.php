<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseDriverFacetsIntegrationTest extends DatabaseTestCase
{
    private ?string $facetTable = null;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('database.connections.manticore', [
            'driver' => 'manticore', 'host' => $this->serverHost(), 'port' => $this->serverPort(), 'timeout' => 3,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresServer();
        $this->facetTable = $this->tableName('facets');
        Schema::connection('manticore')->create($this->facetTable, function ($table) {
            $table->text('title');
            $table->integer('category');
            $table->multi('tags');
            $table->json('info');
            $table->setting('min_infix_len', 2);
        });
        DB::connection('manticore')->table($this->facetTable)->insert([
            ['id' => 1, 'title' => 'galaxy phone', 'category' => 1, 'tags' => [1, 2], 'info' => ['brand' => 'Samsung']],
            ['id' => 2, 'title' => 'galaxy tablet', 'category' => 2, 'tags' => [2], 'info' => ['brand' => 'Samsung']],
            ['id' => 3, 'title' => 'apple phone', 'category' => 1, 'tags' => [3], 'info' => ['brand' => 'Apple']],
        ]);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->facetTable !== null) {
                Schema::connection('manticore')->dropIfExists($this->facetTable);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testFacetsAndMetadataAreCapturedBeforeListenersAndKeepTheConnectionUsable(): void
    {
        $db = DB::connection('manticore');
        $listenerRan = false;
        DB::listen(function (QueryExecuted $event) use (&$listenerRan, $db) {
            if (!$listenerRan && str_contains($event->sql, ' facet ')) {
                $listenerRan = true;
                $db->table($this->facetTable)->where('id', 3)->get();
            }
        });
        $result = $db->table($this->facetTable)->select('id')->orderBy('id')->limit(1)
            ->facet('category', 'categories', 10, 'value', 'asc')
            ->facet('tags', null, 1)->facet('info.brand', 'brands')->search();
        $this->assertTrue($listenerRan);
        $this->assertSame([1], $result->rows()->pluck('id')->all());
        $this->assertSame([1, 2], $result->facet('categories')->pluck('categories')->all());
        $this->assertSame([2, 1], $result->facet('categories')->pluck('count(*)')->all());
        $this->assertSame([2], $result->facet('tags')->pluck('tags')->all());
        $this->assertSame('Samsung', $result->facet('brands')[0]->brands);
        $this->assertSame(3, $result->meta()->totalFound());
        $this->assertSame(3, $db->table($this->facetTable)->count());
        $this->assertCount(3, $result->facets());
    }

    public function testHighlightSupportsStoredFieldsOptionsAndExplicitQuery(): void
    {
        $query = DB::connection('manticore')->table($this->facetTable)->match('galaxy')->orderBy('id');
        $rows = (clone $query)->withHighlight('title', ['before_match' => '<em>', 'after_match' => '</em>'])->select('id')->get();
        $this->assertSame('<em>galaxy</em> phone', $rows[0]->_highlight);
        $explicit = (clone $query)->withHighlight('title', [], 'phone')->search(['id']);
        $this->assertSame('galaxy <b>phone</b>', $explicit->rows()[0]->_highlight);
        $this->assertSame(2, $explicit->meta()->totalFound());
        $this->assertStringContainsString('<b>galaxy</b>', $query->withHighlight('title')->cursor()->first()->_highlight);
    }

    public function testFacetsRespectFiltersAndReturnEmptySets(): void
    {
        $query = DB::connection('manticore')->table($this->facetTable)->match('galaxy')->where('category', 1)->facet('category');
        $result = $query->search(['id']);
        $this->assertSame([1], $result->rows()->pluck('id')->all());
        $this->assertSame([1], $result->facet('category')->pluck('count(*)')->all());
        $empty = $query->where('id', 999)->search(['id']);
        $this->assertTrue($empty->rows()->isEmpty());
        $this->assertTrue($empty->facet('category')->isEmpty());
    }

    public function testKeywordsSuggestionsAndSnippetsUseRealProcedures(): void
    {
        $db = DB::connection('manticore');
        $keywords = $db->keywords('galaxy', $this->facetTable, ['stats' => 1]);
        $this->assertSame('galaxy', $keywords[0]->normalized);
        $this->assertSame(2, (int)$keywords[0]->docs);
        $this->assertSame('galaxy', $db->suggest('galaxi', $this->facetTable)[0]->suggest);
        $this->assertSame('galaxy', $db->qsuggest('a galaxi', $this->facetTable)[0]->suggest);
        $snippets = $db->snippets(['galaxy phone', 'galaxy tablet'], $this->facetTable, 'galaxy');
        $this->assertCount(2, $snippets);
        $this->assertStringContainsString('<b>galaxy</b>', (string)array_values((array)$snippets[0])[0]);
        $this->assertSame(3, $db->table($this->facetTable)->count());
    }

    public function testAutocompleteThroughBuddyReturnsCompletions(): void
    {
        if (getenv('MANTICORE_TEST_BUDDY') !== '1') {
            $this->markTestSkipped('Set MANTICORE_TEST_BUDDY=1 to require a live Buddy autocomplete test.');
        }
        $rows = DB::connection('manticore')->autocomplete('gal', $this->facetTable, ['fuzziness' => 0]);
        $this->assertContains('galaxy', $rows->pluck('query')->all());
    }
}
