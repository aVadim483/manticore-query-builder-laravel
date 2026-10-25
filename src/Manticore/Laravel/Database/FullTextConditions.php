<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use LogicException;

/** Separates Laravel conditions into one MATCH expression and attribute filters. */
final class FullTextConditions
{
    public static function normalize(ManticoreQueryBuilder $query): ?ManticoreQueryBuilder
    {
        [$text, $wheres, $hasMatch] = self::partition($query->wheres ?? []);
        if ($text === null) {
            return null;
        }
        if ($hasMatch) {
            throw new LogicException('whereFullText() cannot be combined with match(). Use one full-text API per query.');
        }

        $normalized = clone $query;
        $normalized->wheres = array_merge([['type' => 'Match', 'value' => $text, 'boolean' => 'and']], $wheres);
        array_unshift($normalized->bindings['where'], $text);

        return $normalized;
    }

    /** @return array{?string, array, bool} Text expression, attribute conditions, native MATCH present. */
    private static function partition(array $wheres): array
    {
        $texts = [];
        $attributes = [];
        $hasMatch = false;
        $hasOr = false;
        foreach ($wheres as $index => $where) {
            $boolean = strtolower($where['boolean']);
            $hasOr = $hasOr || ($index > 0 && str_starts_with($boolean, 'or'));
            $text = null;
            if ($where['type'] === 'Fulltext') {
                $text = $where['expression'];
                $attribute = null;
            } elseif ($where['type'] === 'Nested') {
                [$text, $children, $childMatch] = self::partition($where['query']->wheres ?? []);
                $hasMatch = $hasMatch || $childMatch;
                $attribute = null;
                if ($children) {
                    $attribute = $where;
                    $attribute['query'] = clone $where['query'];
                    $attribute['query']->wheres = $children;
                }
            } else {
                $attribute = $where;
                $hasMatch = $hasMatch || $where['type'] === 'Match';
            }
            if ($text !== null) {
                if (!in_array($boolean, ['and', 'or'], true)) {
                    throw new LogicException('Negated whereFullText() groups are not supported.');
                }
                $texts[] = [$boolean, $text];
            }
            if ($attribute !== null) {
                $attributes[] = $attribute;
            }
        }
        if ($texts && $attributes && $hasOr) {
            throw new LogicException('Manticore cannot combine whereFullText() and attribute filters with OR. Use AND and separate nested groups.');
        }
        if (!$texts) {
            return [null, $attributes, $hasMatch];
        }

        // SQL AND binds more tightly than OR; explicit groups preserve that in MATCH.
        $groups = [];
        $terms = [];
        foreach ($texts as [$boolean, $text]) {
            if ($boolean === 'or' && $terms) {
                $groups[] = '('.implode(' ', $terms).')';
                $terms = [];
            }
            $terms[] = '('.$text.')';
        }
        $groups[] = '('.implode(' ', $terms).')';

        return [implode(' | ', $groups), $attributes, $hasMatch];
    }
}
