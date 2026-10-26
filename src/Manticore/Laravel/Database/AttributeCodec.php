<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Query\Expression;
use InvalidArgumentException;

final class AttributeCodec
{
    public static function encode(string $type, mixed $value): mixed
    {
        // Single values use the same handlers without allocating a callable.
        return match ($type) {
            'json' => self::encodeJson($value),
            'timestamp' => self::encodeTimestamp($value),
            'float_vector' => self::encodeVector($value),
            'multi', 'multi64', 'mva', 'mva64' => self::encodeMva($value),
            default => self::encodeScalar($value),
        };
    }

    /** @internal Select once per column in a write batch, never cache across queries. */
    public static function encoder(string $type): Closure
    {
        return match ($type) {
            'json' => self::encodeJson(...),
            'timestamp' => self::encodeTimestamp(...),
            'float_vector' => self::encodeVector(...),
            'multi', 'multi64', 'mva', 'mva64' => self::encodeMva(...),
            default => self::encodeScalar(...),
        };
    }

    private static function encodeScalar(mixed $value): mixed
    {
        if (!$value instanceof ExpressionContract && (is_array($value) || is_object($value))) {
            throw new InvalidArgumentException('Structured values require a JSON, MVA or vector column.');
        }

        return $value;
    }

    private static function encodeTimestamp(mixed $value): mixed
    {
        return !$value instanceof ExpressionContract && $value instanceof DateTimeInterface
            ? $value->getTimestamp() : self::encodeScalar($value);
    }

    private static function encodeJson(mixed $value): mixed
    {
        if ($value instanceof ExpressionContract) {
            return $value;
        }
        if (is_string($value)) {
            json_decode($value, true, 512, JSON_THROW_ON_ERROR);

            return $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function encodeMva(mixed $value): mixed
    {
        if ($value instanceof ExpressionContract) {
            return $value;
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('MVA and vector values must be lists.');
        }
        $numbers = [];
        foreach ($value as $number) {
            if (!is_int($number) && !(is_string($number) && preg_match('/^-?[0-9]+$/D', $number))) {
                throw new InvalidArgumentException('MVA values must be integers.');
            }
            // Numeric strings remain exact SQL literals, without a lossy integer cast.
            $numbers[] = (string)$number;
        }

        return new Expression('('.implode(', ', $numbers).')');
    }

    private static function encodeVector(mixed $value): mixed
    {
        if ($value instanceof ExpressionContract) {
            return $value;
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('MVA and vector values must be lists.');
        }
        $numbers = [];
        foreach ($value as $number) {
            if ((!is_int($number) && !is_float($number)) || !is_finite((float)$number)) {
                throw new InvalidArgumentException('Vectors require finite numeric values.');
            }
            $numbers[] = json_encode($number, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        }

        return new Expression('('.implode(', ', $numbers).')');
    }

    public static function decode(string $type, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($type === 'json') {
            return is_string($value) ? ($value === '' ? [] : json_decode($value, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING)) : $value;
        }
        if (in_array($type, ['multi', 'multi64', 'mva', 'mva64', 'float_vector'], true)) {
            if (is_array($value)) {
                return $value;
            }
            $items = (string)$value === '' ? [] : explode(',', (string)$value);

            return array_map($type === 'float_vector' ? static fn ($item) => (float)$item : self::integer(...), $items);
        }

        return match ($type) {
            'bool', 'boolean' => (bool)$value,
            'int', 'integer', 'bigint', 'uint', 'uint32', 'uint64', 'timestamp' => self::integer($value),
            'float', 'double' => (float)$value,
            default => $value,
        };
    }

    private static function integer(mixed $value): int|string
    {
        $value = trim((string)$value);

        return (string)(int)$value === $value ? (int)$value : $value;
    }
}
