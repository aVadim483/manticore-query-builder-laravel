<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use Illuminate\Support\Collection;

final readonly class SearchResult
{
    /** @param Collection<int, \stdClass> $rows */
    public function __construct(private Collection $rows, private Meta $meta, private array $facets = [])
    {
    }

    public function rows(): Collection
    {
        return $this->rows;
    }

    public function meta(): Meta
    {
        return $this->meta;
    }

    /** @return array<string, Collection<int, \stdClass>> */
    public function facets(): array
    {
        return $this->facets;
    }

    public function facet(string $name): Collection
    {
        if (!array_key_exists($name, $this->facets)) {
            throw new \OutOfBoundsException('Unknown facet: '.$name);
        }
        return $this->facets[$name];
    }
}
