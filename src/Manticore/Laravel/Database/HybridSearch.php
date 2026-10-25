<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use LogicException;

/**
 * Independent bounded branches; this is not MATCH-filtered KNN or SQL UNION.
 * @internal Execution helper for ManticoreQueryBuilder's terminal hybrid methods.
 */
final class HybridSearch
{
    private ManticoreQueryBuilder $base;
    private array $options;

    public function __construct(ManticoreQueryBuilder $base, private string $text, private string $column,
        private array $vector, array $options = [])
    {
        $this->options = self::normalizeOptions($options);
        $this->base = $this->copyCommonQuery($base);
        // Validate all static KNN inputs and both SQL trees before executing either branch.
        $this->vectorQuery()->toSql();
        $this->textQuery()->toSql();
    }

    private static function normalizeOptions(array $options): array
    {
        $defaults = ['text_weight' => 1, 'vector_weight' => 1, 'rank_constant' => 60,
            'text_candidates' => 100, 'vector_candidates' => 100, 'escape' => true, 'knn_options' => []];
        if (array_diff(array_keys($options), array_keys($defaults))) {
            throw new InvalidArgumentException('Unknown hybrid option.');
        }
        $options += $defaults;
        foreach (['text_weight', 'vector_weight'] as $key) {
            $value = $options[$key];
            if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < 0 || $value > 1000000) {
                throw new InvalidArgumentException('Hybrid weights must be finite numbers between 0 and 1000000.');
            }
        }
        foreach (['text_candidates', 'vector_candidates'] as $key) {
            if (!is_int($options[$key]) || $options[$key] < 1 || $options[$key] > 10000) {
                throw new InvalidArgumentException('Each hybrid candidate limit must be between 1 and 10000.');
            }
        }
        if ($options['text_weight'] + $options['vector_weight'] <= 0
            || !is_int($options['rank_constant']) || $options['rank_constant'] < 0
            || $options['rank_constant'] > 1000000 || !is_bool($options['escape'])
            || !is_array($options['knn_options'])) {
            throw new InvalidArgumentException('Invalid hybrid weights, rank constant, escaping or KNN options.');
        }
        return $options;
    }

    private function copyCommonQuery(ManticoreQueryBuilder $base): ManticoreQueryBuilder
    {
        if (!is_string($base->from) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $base->from)
            || ($base->columns !== null && $base->columns !== ['*']) || $base->orders || $base->limit !== null
            || $base->offset !== null || $base->groups || $base->havings || $base->joins || $base->unions
            || $base->distinct || $base->lock || $base->aggregate || $base->getFacets() || $base->getSearchExpressions()
            || $base->hasQueryCallbacks() || array_intersect(array_keys($base->manticoreOptions), ['fusion_method', 'fusion_weights', 'rank_constant', 'window_size'])) {
            throw new LogicException('Hybrid requires a plain common attribute query without projection, ordering, callbacks or result windows.');
        }
        $query = clone $base;
        foreach ($query->wheres ?? [] as $index => $where) {
            if (strtolower($where['boolean']) !== 'and'
                || !in_array($where['type'], ['Basic', 'Column', 'In', 'NotIn', 'IntegerInRaw', 'IntegerNotInRaw',
                    'between', 'Between', 'NotBetween', 'Null', 'NotNull', 'Mva', 'Nested'], true)) {
                throw new LogicException('Hybrid common predicates must be AND attribute conditions, not MATCH/KNN/raw expressions.');
            }
            if ($where['type'] === 'Nested') {
                $nested = clone $where['query'];
                $nested->from = $base->from;
                $query->wheres[$index]['query'] = $this->copyCommonQuery($nested);
            }
            if (isset($where['column']) && !is_string($where['column'])) {
                throw new LogicException('Hybrid does not accept SQL expression predicates.');
            }
        }
        return $query;
    }

    private function textQuery(): ManticoreQueryBuilder
    {
        return (clone $this->base)->useWritePdo()->match($this->options['escape'] ? ManticoreQueryBuilder::escapeMatch($this->text) : $this->text)
            ->withScore('__hybrid_text_score')->orderBy('__hybrid_text_score', 'desc')->orderBy('id')
            ->limit($this->options['text_candidates']);
    }

    private function vectorQuery(): ManticoreQueryBuilder
    {
        return (clone $this->base)->useWritePdo()->knn($this->column, $this->vector, $this->options['vector_candidates'], $this->options['knn_options'])
            ->withKnnDistance('__hybrid_distance')->orderBy('__hybrid_distance')->orderBy('id');
    }

    public function search(): HybridResult
    {
        $schema = $this->base->getConnection()->getSchemaBuilder();
        $columns = $this->base->getConnection()->getTableSchema($this->base->from);
        if (isset($columns['__hybrid_text_score']) || isset($columns['__hybrid_distance'])) {
            throw new LogicException('Table columns conflict with reserved hybrid transport aliases.');
        }
        if ($this->options['vector_weight'] > 0) {
            $schema->validateKnnVector($this->base->from, $this->column, $this->vector);
        }
        $text = $this->options['text_weight'] > 0 && $this->text !== '' ? $this->textQuery()->search() : null;
        $vector = $this->options['vector_weight'] > 0 ? $this->vectorQuery()->search() : null;
        return self::fuse($text, $vector, $this->options);
    }

    /** Pure fusion is public to permit reproducible relevance checks without a live server. */
    public static function fuse(?SearchResult $text, ?SearchResult $vector, array $options = []): HybridResult
    {
        $options = self::normalizeOptions($options);
        $text = $options['text_weight'] > 0 ? $text : null;
        $vector = $options['vector_weight'] > 0 ? $vector : null;
        $hits = [];
        foreach (['text' => $text, 'vector' => $vector] as $branch => $result) {
            if ($result !== null && $result->rows()->count() > $options[$branch.'_candidates']) {
                throw new InvalidArgumentException('A fusion branch exceeds its declared candidate window.');
            }
            $seen = [];
            foreach ($result?->rows()->values() ?? [] as $rank => $document) {
                if (!isset($document->id) || !preg_match('/^[0-9]+$/D', (string)$document->id)) {
                    throw new LogicException('Hybrid branch rows must contain unsigned document IDs.');
                }
                $key = ltrim((string)$document->id, '0') ?: '0';
                if (isset($seen[$key])) {
                    throw new LogicException('A hybrid branch returned duplicate IDs.');
                }
                $seen[$key] = true;
                $row = clone $document;
                unset($row->__hybrid_text_score, $row->__hybrid_distance);
                $hit = $hits[$key] ?? ['id' => $document->id, 'document' => $row, 'score' => 0.0,
                    'textRank' => null, 'vectorRank' => null, 'textScore' => null, 'distance' => null];
                $hit['score'] += $options[$branch.'_weight'] / ($options['rank_constant'] + $rank + 1);
                $hit[$branch.'Rank'] = $rank + 1;
                if ($branch === 'text') {
                    $hit['textScore'] = isset($document->__hybrid_text_score) ? (float)$document->__hybrid_text_score : null;
                } else {
                    $hit['distance'] = isset($document->__hybrid_distance) ? (float)$document->__hybrid_distance : null;
                }
                $hits[$key] = $hit;
            }
        }
        $hits = array_map(static fn ($hit) => new HybridHit(...$hit), array_values($hits));
        usort($hits, static function ($left, $right) {
            $a = ltrim((string)$left->id, '0') ?: '0';
            $b = ltrim((string)$right->id, '0') ?: '0';
            return ($right->score <=> $left->score) ?: ((strlen($a) <=> strlen($b)) ?: strcmp($a, $b));
        });
        return new HybridResult(new Collection($hits), $text?->meta(), $vector?->meta(), $options);
    }
}
