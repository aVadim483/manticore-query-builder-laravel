<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests\Support;

use avadim\Manticore\Laravel\Database\ManticoreQueryBuilder;
use ReflectionClass;
use ReflectionMethod;
use ReflectionUnionType;

/** Shared by PHPUnit and isolated production consumers at the minimum Illuminate versions. */
final class ApiContractInspector
{
    public static function catalogErrors(): array
    {
        $catalog = self::read('laravel-query-builder-api.json');
        $errors = [];
        $reviewed = [];
        foreach ($catalog['groups'] as $id => $group) {
            if (!$group['notes']) $errors[] = 'Missing notes for '.$id;
            foreach ($group['methods'] as $method) {
                if (isset($reviewed[$method])) $errors[] = 'Duplicate API status: '.$method;
                $reviewed[$method] = $group['status'];
            }
        }
        foreach ((new ReflectionClass(ManticoreQueryBuilder::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->name, '__') || ($method->isStatic() && $method->getDeclaringClass()->getName() !== ManticoreQueryBuilder::class)) continue;
            if (!isset($reviewed[$method->name])) $errors[] = 'Unreviewed Laravel/driver API: '.$method->name;
        }
        return $errors;
    }

    public static function signatureErrors(): array
    {
        $errors = [];
        foreach (self::read('query-builder-contracts.json')['methods'] as $name => $expected) {
            if (!method_exists(ManticoreQueryBuilder::class, $name)) {
                $errors[] = 'Missing method: '.$name;
                continue;
            }
            $method = new ReflectionMethod(ManticoreQueryBuilder::class, $name);
            $actual = [];
            foreach ($method->getParameters() as $parameter) {
                $item = ['name' => $parameter->name, 'type' => self::type($parameter->getType())];
                if ($parameter->isDefaultValueAvailable()) $item['default'] = $parameter->getDefaultValue();
                $actual[] = $item;
            }
            if ($actual !== $expected['parameters']) $errors[] = 'Parameter contract changed: '.$name;
            if (self::type($method->getReturnType()) !== $expected['declared_return']) $errors[] = 'Declared return changed: '.$name;
        }
        return $errors;
    }

    private static function type($value): ?string
    {
        if ($value === null) return null;
        if ($value instanceof ReflectionUnionType) {
            $names = array_map('strval', $value->getTypes());
            sort($names);
            return implode('|', $names);
        }
        return (string) $value;
    }

    private static function read(string $name): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/'.$name), true, 512, JSON_THROW_ON_ERROR);
    }
}
