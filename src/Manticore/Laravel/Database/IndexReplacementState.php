<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

/** Server-observed layout; a gap can be resumed forward OR rolled back explicitly. */
final readonly class IndexReplacementState
{
    public function __construct(public string $status, public string $active, public string $staged,
        public string $backup, public array $present)
    {
    }
}
