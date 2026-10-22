<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/** A bounded snapshot. Window totals must never be presented as corpus-wide totals. */
final readonly class HybridResult
{
    public function __construct(private Collection $hits, public ?Meta $textMeta, public ?Meta $vectorMeta,
        public array $options)
    {
    }

    /** @return Collection<int, HybridHit> */
    public function hits(): Collection { return new Collection($this->hits->all()); }

    /** @return Collection<int, \stdClass> Document fields without overwriting application attributes. */
    public function rows(): Collection { return $this->hits->map(static fn ($hit) => clone $hit->document); }

    public function windowTotal(): int { return $this->hits->count(); }

    /** The exact full-union total cannot be inferred from two truncated candidate lists. */
    public function totalFound(): ?int { return null; }

    public function totals(): array
    {
        return [
            'window' => ['value' => $this->windowTotal(), 'accuracy' => 'exact'],
            'global' => ['value' => null, 'accuracy' => 'unavailable'],
            'text' => ['value' => $this->textMeta?->totalFound(), 'accuracy' => $this->textMeta === null ? 'unavailable' : 'unknown'],
            'vector' => ['value' => $this->vectorMeta?->totalFound(), 'accuracy' => $this->vectorMeta === null ? 'unavailable' : 'unknown'],
        ];
    }

    public function paginateWindow(int $perPage = 15, int $page = 1, array $options = []): LengthAwarePaginator
    {
        if ($perPage < 1 || $page < 1 || $perPage > intdiv(PHP_INT_MAX, $page)) {
            throw new InvalidArgumentException('Window pagination requires positive, bounded page numbers and sizes.');
        }
        return new LengthAwarePaginator($this->hits->slice(($page - 1) * $perPage, $perPage)->values(),
            $this->windowTotal(), $perPage, $page, $options);
    }
}
