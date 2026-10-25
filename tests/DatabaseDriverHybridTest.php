<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Database\HybridSearch;
use avadim\Manticore\Laravel\Database\Meta;
use avadim\Manticore\Laravel\Database\SearchResult;
use avadim\Manticore\Laravel\Database\ManticoreConnection;
use Illuminate\Support\Collection;

class DatabaseDriverHybridTest extends DatabaseTestCase
{
    public function testRrfWeightsRanksTiesDeduplicationAndBoundedSnapshotPages(): void
    {
        $text = new SearchResult(new Collection([(object)['id' => '9007199254740993', 'title' => 'text', '__hybrid_text_score' => 123],
            (object)['id' => 2, 'title' => 'both']]), new Meta(['total_found' => 1000]));
        $vector = new SearchResult(new Collection([(object)['id' => 3, 'title' => 'semantic', '__hybrid_distance' => 0.1],
            (object)['id' => 2, 'title' => 'vector copy']]), new Meta(['total_found' => 2]));
        $result = HybridSearch::fuse($text, $vector);
        $this->assertSame([2, 3, '9007199254740993'], $result->hits()->pluck('id')->all());
        $this->assertEqualsWithDelta(2 / 62, $result->hits()[0]->score, 1e-12);
        $this->assertSame('both', $result->rows()[0]->title);
        $this->assertSame(2, $result->hits()[0]->textRank);
        $this->assertSame(2, $result->hits()[0]->vectorRank);
        $this->assertNull($result->hits()[1]->textRank);
        $this->assertSame(0.1, $result->hits()[1]->distance);
        $this->assertSame(123.0, $result->hits()[2]->textScore);
        $this->assertFalse(property_exists($result->rows()[2], '__hybrid_text_score'));
        $this->assertSame($text->meta(), $result->textMeta);
        $this->assertNull($result->totalFound());
        $this->assertSame(3, $result->windowTotal());
        $this->assertSame(['window' => ['value' => 3, 'accuracy' => 'exact'],
            'global' => ['value' => null, 'accuracy' => 'unavailable'],
            'text' => ['value' => 1000, 'accuracy' => 'unknown'], 'vector' => ['value' => 2, 'accuracy' => 'unknown']], $result->totals());
        $this->assertSame(['9007199254740993'], $result->paginateWindow(2, 2)->getCollection()->pluck('id')->all());
        $this->assertSame(3, $result->paginateWindow(2)->total());
        $this->assertSame('9007199254740993', HybridSearch::fuse($text, $vector, ['text_weight' => 100])->hits()[0]->id);
        $this->assertSame(3, HybridSearch::fuse($text, $vector, ['vector_weight' => 100])->hits()[0]->id);
        $this->assertSame(0, HybridSearch::fuse(null, null)->windowTotal());
        $this->assertSame(2, HybridSearch::fuse(null, $vector)->windowTotal());
        $this->assertSame([3, 2], HybridSearch::fuse($text, $vector, ['text_weight' => 0])->hits()->pluck('id')->all());
        $this->assertSame(2, HybridSearch::fuse($text, new SearchResult(new Collection(), new Meta(['total_found' => 0])))->windowTotal());
    }

    public function testStaticValidationRejectsUnsafeQueryShapesWithoutNetwork(): void
    {
        $db = new ManticoreConnection(fn () => throw new \LogicException('Unexpected network'));
        foreach ([fn ($q) => $q->match('text'), fn ($q) => $q->whereRaw('id>0'), fn ($q) => $q->orderBy('id'),
            fn ($q) => $q->limit(1), fn ($q) => $q->select('id'), fn ($q) => $q->afterQuery(fn ($r) => $r),
            fn ($q) => $q->where(fn ($nested) => $nested->where('stock', 1)->orWhere('stock', 2))] as $configure) {
            try {
                $configure($db->table('products'))->hybridSearch('phone', 'embedding', [1, 0, 0]);
                $this->fail('Unsupported common query accepted.');
            } catch (\LogicException $error) {
                $this->assertStringNotContainsString('Unexpected network', $error->getMessage());
            }
        }
        foreach ([['text_weight' => -1], ['vector_weight' => NAN], ['rank_constant' => -1],
            ['text_candidates' => 10001], ['text_weight' => 0, 'vector_weight' => 0], ['typo' => 1]] as $options) {
            try {
                $db->table('products')->hybridSearch('phone', 'embedding', [1, 0, 0], $options);
                $this->fail('Invalid fusion options accepted.');
            } catch (\InvalidArgumentException $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        }
    }

    public function testRemovedHybridMethodFailsInsteadOfStartingAnImplicitMode(): void
    {
        $db = new ManticoreConnection(fn () => throw new \LogicException('Unexpected network'));
        $query = $db->table('products');
        $this->assertFalse(method_exists($query, 'hybrid'));
        $this->expectException(\BadMethodCallException::class);
        $query->hybrid('phone', 'embedding', [1, 0, 0]);
    }
}
