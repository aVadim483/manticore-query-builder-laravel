<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use LogicException;
use Traversable;

/** An immutable snapshot; reading it never issues SQL. */
final readonly class Meta implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable
{
    public function __construct(private array $values = [])
    {
    }

    public static function fromRows(array $rows): self
    {
        $values = [];
        foreach ($rows as $row) {
            $row = (array)$row;
            $name = $row['Variable_name'];
            $value = $row['Value'];
            if ($name === 'time') {
                $value = (float)$value;
            } elseif (in_array($name, ['total', 'total_found'], true)
                || str_starts_with($name, 'docs[') || str_starts_with($name, 'hits[')) {
                $value = (int)$value;
            }
            $values[$name] = $value;
        }

        return new self($values);
    }

    public function totalFound(): ?int
    {
        return isset($this->values['total_found']) ? (int)$this->values['total_found'] : null;
    }

    public function total(): ?int
    {
        return isset($this->values['total']) ? (int)$this->values['total'] : null;
    }

    public function time(): ?float
    {
        return isset($this->values['time']) ? (float)$this->values['time'] : null;
    }

    public function toArray(): array
    {
        return $this->values;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->values[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->values[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Search metadata is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Search metadata is immutable.');
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->values);
    }

    public function count(): int
    {
        return count($this->values);
    }

    public function jsonSerialize(): array
    {
        return $this->values;
    }
}
