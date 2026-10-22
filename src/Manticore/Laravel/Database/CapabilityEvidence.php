<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use Throwable;

/** Evidence for exactly the probed operation/table/parameters, not every possible query. */
final readonly class CapabilityEvidence
{
    public function __construct(public string $feature, public string $table, public bool $supported,
        public ?Throwable $cause = null)
    {
    }
}
