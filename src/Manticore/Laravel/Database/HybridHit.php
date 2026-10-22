<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

final readonly class HybridHit
{
    public function __construct(public int|string $id, public \stdClass $document, public float $score,
        public ?int $textRank, public ?int $vectorRank, public ?float $textScore, public ?float $distance)
    {
    }
}
