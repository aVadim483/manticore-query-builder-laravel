<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use InvalidArgumentException;

final readonly class Ranker
{
    public function __construct(public string $name, public ?string $expression = null)
    {
        if (!in_array($name, ['proximity_bm25', 'bm25', 'none', 'wordcount', 'proximity', 'matchany', 'fieldmask', 'sph04', 'expr', 'export'], true)) {
            throw new InvalidArgumentException('Unsupported Manticore ranker.');
        }
        if (in_array($name, ['expr', 'export'], true)
            ? ($expression === null || trim($expression) === '') : $expression !== null) {
            throw new InvalidArgumentException('Only expr/export rankers require a non-empty expression.');
        }
    }
}
