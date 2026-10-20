<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use RuntimeException;
use Throwable;

final class TransactionRollbackException extends RuntimeException
{
    public function __construct(public readonly Throwable $callbackError, public readonly Throwable $rollbackError)
    {
        parent::__construct('Rollback failed after transaction callback error: '.$callbackError->getMessage(), 0, $rollbackError);
    }
}
