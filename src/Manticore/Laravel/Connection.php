<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel;

use avadim\Manticore\QueryBuilder\Connection as BaseConnection;
use LogicException;
use Throwable;

/**
 * The connection of the package: the same connection, building the queries of Laravel\Query.
 *
 * Registered as the connection class of the builder in ServiceProvider::boot(), so that both
 * entry points - the \ManticoreDb alias and the container service - answer alike.
 *
 * @package avadim\Manticore\Laravel
 */
class Connection extends BaseConnection
{
    /**
     * @var string
     */
    protected string $queryClass = Query::class;

    /**
     * How deep the code currently is in nested transaction() calls
     *
     * @var int
     */
    protected $transactions = 0;

    /**
     * Run an SQL statement and tell whether the server accepted it
     *
     * @param string $sql
     *
     * @return bool
     */
    public function statement(string $sql): bool
    {
        return $this->sql($sql)->exec()->success();
    }

    /**
     * @return int how many transactions are open
     */
    public function transactionLevel(): int
    {
        return $this->transactions;
    }

    /**
     * BEGIN, unless a transaction is already open - Manticore has no savepoints, so a nested
     * call only counts one level deeper
     *
     * @return bool
     */
    public function beginTransaction(): bool
    {
        if ($this->transactions === 0 && !$this->statement('BEGIN')) {
            return false;
        }
        $this->transactions++;

        return true;
    }

    /**
     * COMMIT, when the outermost transaction is the one being closed
     *
     * @return bool
     * @throws \LogicException when no transaction is open
     */
    public function commit(): bool
    {
        if ($this->transactions === 0) {
            throw new LogicException('There is no transaction to commit');
        }
        $this->transactions--;

        return $this->transactions === 0 ? $this->statement('COMMIT') : true;
    }

    /**
     * ROLLBACK. Without savepoints there is nothing partial to roll back to, so this always
     * undoes the whole transaction, however deeply nested the call was
     *
     * @return bool
     * @throws \LogicException when no transaction is open
     */
    public function rollBack(): bool
    {
        if ($this->transactions === 0) {
            throw new LogicException('There is no transaction to roll back');
        }
        $this->transactions = 0;

        return $this->statement('ROLLBACK');
    }

    /**
     * Run the callback inside a transaction, committing when it returns and rolling back when
     * it throws.
     *
     * @param callable $callback receives this connection
     * @param int|null $attempts how many times to try before giving up
     *
     * @return mixed whatever the callback returned
     * @throws \Throwable the last exception the callback threw
     */
    public function transaction(callable $callback, ?int $attempts = 1)
    {
        $attempts = max(1, (int)$attempts);

        for ($attempt = 1; ; $attempt++) {
            $this->beginTransaction();

            try {
                $result = $callback($this);
                $this->commit();

                return $result;
            }
            catch (Throwable $e) {
                $this->rollBack();

                if ($attempt >= $attempts) {
                    throw $e;
                }
            }
        }
    }
}
