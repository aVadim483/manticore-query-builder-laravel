<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
use avadim\Manticore\Laravel\Database\HybridResult;

/** Execute the published guide snippets, so examples are checked against both server targets. */
class HybridGuideIntegrationTest extends DatabaseTestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('database.connections.manticore', [
            'driver' => 'manticore', 'host' => $this->serverHost(), 'port' => $this->serverPort(),
            'prefix' => $this->tableName('guide').'_',
        ]);
    }

    public function testEnglishAndRussianProgressiveExamplesRunAsDocumented(): void
    {
        $this->requiresServer();
        foreach (['docs/hybrid-search.md' => 'phone', 'docs/ru/hybrid-search.md' => 'телефон'] as $file => $word) {
            $markdown = file_get_contents(dirname(__DIR__).'/'.$file);
            preg_match_all('/<!-- example: ([a-z]+) -->\s*```php\s*\R(.*?)```/s', $markdown, $matches, PREG_SET_ORDER);
            $examples = [];
            foreach ($matches as $match) { $examples[$match[1]] = $match[2]; }
            $this->assertSame(['minimal', 'first', 'filters', 'weights', 'result', 'page', 'advanced', 'syntax', 'schema'], array_keys($examples));
            $schema = Schema::connection('manticore');
            try {
                $this->executeExample($examples['schema']);
                DB::connection('manticore')->table('products')->insert([
                    ['id' => 1, 'title' => $word, 'body' => 'travel поездка', 'brand' => 'acme', 'stock' => 1, 'category_id' => 1, 'price' => 200, 'tags' => [1], 'embedding' => [0, 1, 0]],
                    ['id' => 2, 'title' => $word, 'body' => 'travel поездка', 'brand' => 'acme', 'stock' => 1, 'category_id' => 2, 'price' => 300, 'tags' => [2], 'embedding' => [0.9, 0.1, 0]],
                    ['id' => 3, 'title' => 'semantic', 'body' => 'travel поездка', 'brand' => 'acme', 'stock' => 1, 'category_id' => 1, 'price' => 400, 'tags' => [1, 2], 'embedding' => [1, 0, 0]],
                    ['id' => 4, 'title' => $word, 'body' => 'travel поездка', 'brand' => 'other', 'stock' => 0, 'category_id' => 3, 'price' => 5000, 'tags' => [9], 'embedding' => [1, 0, 0]],
                ]);
                $db = DB::connection('manticore');
                $db->statement('FLUSH RAMCHUNK '.$db->getQueryGrammar()->wrapTable('products'));
                // Eval is limited to trusted, repository-owned documentation, in one shared scope.
                foreach (array_slice($examples, 0, -1, true) as $id => $code) {
                    $code = preg_replace('/^use [^;]+;\s*$/m', '', $code);
                    eval('use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Schema; '.$code);
                    if (in_array($id, ['minimal', 'filters', 'weights'], true)) {
                        $this->assertInstanceOf(Collection::class, $products);
                        $this->assertNotEmpty($products->all());
                        $this->assertContains(3, $products->pluck('id')->all(), 'Semantic-only document must enter the union.');
                        if ($id === 'filters') { $this->assertNotContains(4, $products->pluck('id')->all()); }
                    } elseif ($id === 'first') {
                        $this->assertInstanceOf(\stdClass::class, $product);
                    } else {
                        $this->assertInstanceOf(HybridResult::class, $result);
                        $this->assertGreaterThan(0, $result->windowTotal());
                        $this->assertNull($result->totalFound());
                        if ($id === 'page') {
                            $this->assertSame(2, $page->currentPage());
                            $this->assertSame($windowCount, $page->total());
                        }
                        if ($id === 'advanced') { $this->assertNotContains(4, $result->rows()->pluck('id')->all()); }
                    }
                }
            } finally {
                $schema->dropIfExists('products');
            }
        }
    }

    private function executeExample(string $code): void
    {
        eval($code);
    }
}
