<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use Illuminate\Database\Grammar;
use InvalidArgumentException;

final class SearchOptions
{
    public static function compile(string $name, mixed $value, Grammar $grammar): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) {
            throw new InvalidArgumentException('Invalid Manticore option name.');
        }
        if ($name === 'ranker') {
            $ranker = $value instanceof Ranker ? $value : (is_string($value) ? new Ranker($value) : null);
            if ($ranker === null) {
                throw new InvalidArgumentException('A ranker requires a ranker name or Ranker object.');
            }
            return $name.'='.$ranker->name.($ranker->expression === null ? '' : '('.$grammar->escape($ranker->expression).')');
        }
        if (in_array($name, ['field_weights', 'index_weights'], true)) {
            if (!is_array($value) || !$value) {
                throw new InvalidArgumentException('Weights require a non-empty name => positive integer map.');
            }
            $weights = [];
            foreach ($value as $field => $weight) {
                if (!is_string($field) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $field) || !is_int($weight) || $weight <= 0) {
                    throw new InvalidArgumentException('Weights require valid names and positive integers.');
                }
                $weights[] = $field.'='.$weight;
            }
            return $name.'=('.implode(', ', $weights).')';
        }
        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return $name.'='.json_encode($value, JSON_THROW_ON_ERROR);
        }
        if (is_string($value)) {
            return $name.'='.$grammar->escape($value);
        }
        throw new InvalidArgumentException('An option requires a finite number, string or supported weight map.');
    }
}
