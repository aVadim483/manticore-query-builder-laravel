<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use InvalidArgumentException;

/** PDO has no PARAM_FLOAT. Keep other parameters bound, and render only finite PHP floats. */
final class NumericBindings
{
    public static function prepare(string $sql, array $bindings): array
    {
        if (!array_filter($bindings, 'is_float')) {
            return [$sql, $bindings];
        }
        foreach ($bindings as $value) {
            if (is_float($value) && !is_finite($value)) {
                throw new InvalidArgumentException('Manticore parameters must be finite numbers.');
            }
        }

        $result = '';
        $remaining = $bindings;
        $position = 0;
        $length = strlen($sql);
        for ($i = 0; $i < $length; ++$i) {
            $character = $sql[$i];
            if (in_array($character, ["'", '"', '`'], true)) {
                $quote = $character;
                $result .= $character;
                while (++$i < $length) {
                    $result .= $sql[$i];
                    if ($sql[$i] === '\\' && $i + 1 < $length) {
                        $result .= $sql[++$i];
                    } elseif ($sql[$i] === $quote) {
                        if (($sql[$i + 1] ?? '') === $quote) {
                            $result .= $sql[++$i];
                        } else {
                            break;
                        }
                    }
                }
                continue;
            }
            if (substr($sql, $i, 2) === '/*') {
                $end = strpos($sql, '*/', $i + 2);
                $end = $end === false ? $length : $end + 2;
                $result .= substr($sql, $i, $end - $i);
                $i = $end - 1;
                continue;
            }
            if ($character === '#' || (substr($sql, $i, 2) === '--' && ctype_space($sql[$i + 2] ?? ' '))) {
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $length : $end;
                $result .= substr($sql, $i, $end - $i);
                $i = $end - 1;
                continue;
            }

            $key = null;
            $placeholderLength = 1;
            if ($character === '?') {
                $key = $position++;
            } elseif ($character === ':' && ($i === 0 || !preg_match('/[a-zA-Z0-9_:]/', $sql[$i - 1]))
                && preg_match('/^:([a-zA-Z_][a-zA-Z0-9_]*)/', substr($sql, $i), $match)) {
                $key = array_key_exists($match[1], $bindings) ? $match[1] : $match[0];
                $placeholderLength = strlen($match[0]);
            }

            if ($key !== null && array_key_exists($key, $bindings) && is_float($bindings[$key])) {
                $result .= json_encode($bindings[$key], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                unset($remaining[$key]);
                $i += $placeholderLength - 1;
            } else {
                $result .= $character;
            }
        }

        return [$result, array_is_list($bindings) ? array_values($remaining) : $remaining];
    }
}
