<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use DateTimeInterface;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Query\Expression;
use InvalidArgumentException;

final class AttributeCodec
{
    public static function encode(string $type, mixed $value): mixed
    {
        if ($value instanceof ExpressionContract) {
            return $value;
        }
        if ($type === 'timestamp' && $value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }
        if ($type === 'json') {
            if (is_string($value)) {
                json_decode($value, true, 512, JSON_THROW_ON_ERROR);

                return $value;
            }

            return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        }
        if (in_array($type, ['multi', 'multi64', 'mva', 'mva64', 'float_vector'], true)) {
            if (!is_array($value) || !array_is_list($value)) {
                throw new InvalidArgumentException('MVA and vector values must be lists.');
            }
            $numbers = [];
            foreach ($value as $number) {
                if ($type === 'float_vector') {
                    if ((!is_int($number) && !is_float($number)) || !is_finite((float)$number)) {
                        throw new InvalidArgumentException('Vectors require finite numeric values.');
                    }
                    $numbers[] = json_encode($number, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                } else {
                    if (!is_int($number) && !(is_string($number) && preg_match('/^-?[0-9]+$/D', $number))) {
                        throw new InvalidArgumentException('MVA values must be integers.');
                    }
                    // Numeric strings remain exact SQL literals, without a lossy integer cast.
                    $numbers[] = (string)$number;
                }
            }

            return new Expression('('.implode(', ', $numbers).')');
        }
        if (is_array($value) || is_object($value)) {
            throw new InvalidArgumentException('Structured values require a JSON, MVA or vector column.');
        }

        return $value;
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
