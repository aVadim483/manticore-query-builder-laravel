<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use InvalidArgumentException;

/** Compile Laravel arrow paths without treating JSON keys as SQL identifiers. */
final class JsonPath
{
    public static function compile(mixed $column, ManticoreGrammar $grammar): string
    {
        if (!is_string($column)) {
            throw new InvalidArgumentException('JSON filters require a column name with an optional -> path.');
        }
        $parts = explode('->', $column);
        $root = array_shift($parts);
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?$/D', $root)) {
            throw new InvalidArgumentException('Invalid JSON column name.');
        }
        // Manticore's WHERE parser does not accept a backtick-quoted root before ['key'].
        $sql = str_replace('`', '', $grammar->wrap($root));
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)?$/D', $sql)) {
            throw new InvalidArgumentException('JSON paths require a simple column/table prefix.');
        }
        foreach ($parts as $part) {
            if ($part === '' || str_contains($part, "\0")) {
                throw new InvalidArgumentException('JSON paths cannot contain empty segments or null bytes.');
            }
            if ($part[0] === '"') {
                try {
                    $key = json_decode($part, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new InvalidArgumentException('Invalid quoted JSON path key.', 0, $e);
                }
                if (!is_string($key) || $key === '' || str_contains($key, "\0")) {
                    throw new InvalidArgumentException('Invalid quoted JSON path key.');
                }
                $sql .= '['.$grammar->escape($key).']';
                continue;
            }
            if (ctype_digit($part)) {
                $sql .= self::index($part);
                continue;
            }
            if (!preg_match('/^([^\[\]*]+)((?:\[[0-9]+\])*)$/uD', $part, $match)) {
                throw new InvalidArgumentException('JSON paths require keys and non-negative array indexes; wildcards are not supported.');
            }
            $sql .= '['.$grammar->escape($match[1]).']';
            preg_match_all('/\[([0-9]+)\]/', $match[2], $indexes);
            foreach ($indexes[1] as $index) {
                $sql .= self::index($index);
            }
        }

        return $sql;
    }

    private static function index(string $value): string
    {
        $value = ltrim($value, '0') ?: '0';
        if (strlen($value) > 10 || (strlen($value) === 10 && strcmp($value, '2147483647') > 0)) {
            throw new InvalidArgumentException('JSON array indexes must fit a signed 32-bit integer.');
        }

        return '['.$value.']';
    }
}
