<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use InvalidArgumentException;

final class ProcedureCall
{
    public static function compile(string $procedure, array $arguments, array $options): array
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $procedure) || !array_is_list($arguments)) {
            throw new InvalidArgumentException('A procedure requires a valid name and positional arguments.');
        }
        $bindings = [];
        $parameter = static function ($value) use (&$bindings): string {
            if ((!is_scalar($value) && $value !== null) || (is_float($value) && !is_finite($value))) {
                throw new InvalidArgumentException('CALL values must be finite scalar values or null.');
            }
            $bindings[] = $value;
            return '?';
        };
        $parts = [];
        foreach ($arguments as $value) {
            if (is_array($value)) {
                if (!array_is_list($value) || !$value) {
                    throw new InvalidArgumentException('CALL list arguments must be non-empty lists.');
                }
                $parts[] = '('.implode(', ', array_map($parameter, $value)).')';
            } else {
                $parts[] = $parameter($value);
            }
        }
        foreach ($options as $name => $value) {
            if (!is_string($name) || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) {
                throw new InvalidArgumentException('Invalid CALL option name.');
            }
            // Buddy's CALL parser rejects quoted option aliases. The identifier was validated above.
            $parts[] = $parameter($value).' AS '.$name;
        }
        return ['CALL '.strtoupper($procedure).'('.implode(', ', $parts).')', $bindings];
    }
}
