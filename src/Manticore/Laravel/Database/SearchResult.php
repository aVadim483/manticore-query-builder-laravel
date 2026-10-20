<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use Illuminate\Support\Collection;

final readonly class SearchResult
{
    /** @param Collection<int, \stdClass> $rows */
    public function __construct(private Collection $rows, private Meta $meta)
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
}
