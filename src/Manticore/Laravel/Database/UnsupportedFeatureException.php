<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use LogicException;
use Throwable;

class UnsupportedFeatureException extends LogicException
{
    public function __construct(public readonly string $feature, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
