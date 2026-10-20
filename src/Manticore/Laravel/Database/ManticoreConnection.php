<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use LogicException;
use Illuminate\Support\Collection;
use PDO;
use Exception;
use Throwable;
use RuntimeException;
use InvalidArgumentException;

class ManticoreConnection extends Connection
{
    protected array $schemaCache = [];

    public function getTableSchema(string $table): array
    {
        if ($this->pretending()) {
            return [];
        }
        if (!isset($this->schemaCache[$table])) {
            $columns = [];
            foreach ($this->selectFromWriteConnection('DESCRIBE '.$this->getQueryGrammar()->wrapTable($table)) as $row) {
                $row = (array)$row;
                $columns[$row['Field']] = ['type' => strtolower($row['Type']), 'properties' => $row['Properties'] ?? ''];
            }
            $this->schemaCache[$table] = $columns;
        }

        return $this->schemaCache[$table];
    }

    public function forgetSchema(?string $table = null): static
    {
        if ($table === null) {
            $this->schemaCache = [];
        } else {
            unset($this->schemaCache[$table]);
        }

        return $this;
    }

    public function setPdo($pdo)
    {
        $this->forgetSchema();

        return parent::setPdo($pdo);
    }

    public function insertAndGetId(string $sql, array $bindings = []): int|string
    {
        $idError = null;
        $idTime = 0.0;
        $id = $this->run($sql, $bindings, function ($sql, $bindings) use (&$idError, &$idTime) {
            if ($this->pretending()) {
                return 0;
            }
            $pdo = $this->getPdo();
            $statement = $pdo->prepare($sql);
            $this->bindValues($statement, $this->prepareBindings($bindings));
            $statement->execute();
            $statement->closeCursor();
            $this->recordsHaveBeenModified();
            $start = microtime(true);
            try {
                $result = $pdo->query('SELECT LAST_INSERT_ID() AS id');
                $id = (string)$result->fetchColumn();
                $result->closeCursor();
                if (!preg_match('/^[0-9]+$/D', $id)) {
                    throw new RuntimeException('Manticore did not return a single document ID.');
                }

                return (string)(int)$id === $id ? (int)$id : $id;
            } catch (Exception $e) {
                $idError = new QueryException($this->getName(), 'SELECT LAST_INSERT_ID() AS id', [], $e);

                return 0;
            } finally {
                $idTime = (microtime(true) - $start) * 1000;
            }
        });
        if ($idError !== null) {
            throw $idError;
        }
        if (!$this->pretending()) {
            $this->logQuery('SELECT LAST_INSERT_ID() AS id', [], $idTime);
        }

        return $id;
    }

    public function getMeta(): Meta
    {
        return Meta::fromRows($this->select('SHOW META'));
    }

    /** Capture both answers before query-event listeners can change session metadata. */
    public function search(string $sql, array $bindings = [], bool $useReadPdo = true): SearchResult
    {
        $metaTime = 0.0;
        $metaError = null;
        $result = $this->run($sql, $bindings, function ($sql, $bindings) use ($useReadPdo, &$metaTime, &$metaError) {
            if ($this->pretending()) {
                return new SearchResult(new Collection(), new Meta());
            }

            $pdo = $this->getPdoForSelect($useReadPdo);
            $statement = $this->prepared($pdo->prepare($sql));
            $this->bindValues($statement, $this->prepareBindings($bindings));
            $statement->execute();
            $rows = $statement->fetchAll(PDO::FETCH_OBJ);
            $statement->closeCursor();

            $start = microtime(true);
            try {
                // Internal service query: no before/after hooks between the search and SHOW META.
                $metadata = $pdo->query('SHOW META');
                $meta = Meta::fromRows($metadata->fetchAll(PDO::FETCH_ASSOC));
                $metadata->closeCursor();
            } catch (Exception $e) {
                $metaError = new QueryException($this->getName(), 'SHOW META', [], $e);
                $meta = new Meta();
            }
            $metaTime = (microtime(true) - $start) * 1000;

            return new SearchResult(new Collection($rows), $meta);
        });

        if ($metaError !== null) {
            throw $metaError;
        }
        // Both events are emitted only after the metadata snapshot is safe. The SELECT duration
        // includes capture of its metadata; the service query also has its own timing entry.
        if (!$this->pretending()) {
            $this->logQuery('SHOW META', [], $metaTime);
        }

        return $result;
    }

    public function query()
    {
        return new ManticoreQueryBuilder($this, $this->getQueryGrammar(), $this->getPostProcessor());
    }

    protected function getDefaultQueryGrammar()
    {
        $grammar = new ManticoreGrammar($this);
        // Laravel 11 configures grammars through setters; 12+ takes the connection in the constructor.
        if (method_exists($grammar, 'setConnection')) {
            $grammar->setConnection($this);
            $grammar->setTablePrefix($this->getTablePrefix());
        }

        return $grammar;
    }

    protected function getDefaultPostProcessor()
    {
        return new ManticoreProcessor();
    }

    public function getSchemaBuilder()
    {
        if ($this->getSchemaGrammar() === null) {
            $this->useDefaultSchemaGrammar();
        }

        return new ManticoreSchemaBuilder($this);
    }

    protected function getDefaultSchemaGrammar()
    {
        $grammar = new ManticoreSchemaGrammar($this);
        if (method_exists($grammar, 'setConnection')) {
            $grammar->setConnection($this);
            $grammar->setTablePrefix($this->getTablePrefix());
        }

        return $grammar;
    }

    protected function escapeString($value)
    {
        // SQL rendering (toRawSql / pretend / query events) must not open a connection.
        return "'".str_replace(
            ["\\", "'", "\n", "\r", "\x1a"],
            ["\\\\", "\\'", '\\n', '\\r', '\\Z'],
            $value,
        )."'";
    }

    protected function createTransaction()
    {
        if ($this->transactionLevel() > 0) {
            throw new LogicException('Manticore does not support nested transactions or savepoints.');
        }

        $this->reconnectIfMissingConnection();
        try {
            if (!$this->getPdo()->beginTransaction()) {
                throw new RuntimeException('Manticore refused BEGIN.');
            }
        } catch (Throwable $e) {
            $this->disconnect();
            throw new QueryException($this->getName(), 'BEGIN', [], $e);
        }
    }

    public function transaction(Closure $callback, $attempts = 1)
    {
        if ($attempts !== 1) {
            throw new InvalidArgumentException('Automatic transaction retries are not supported by the Manticore driver.');
        }
        $this->beginTransaction();
        try {
            $result = $callback($this);
        } catch (Throwable $e) {
            if ($this->transactionLevel() > 0) {
                try {
                    $this->rollBack();
                } catch (Throwable $rollbackError) {
                    throw new TransactionRollbackException($e, $rollbackError);
                }
            }
            throw $e;
        }
        // A failed COMMIT has an unknown outcome. Disconnect and propagate; never replay.
        $this->commit();

        return $result;
    }

    public function commit()
    {
        if ($this->transactionLevel() !== 1) {
            throw new LogicException('There is no Manticore transaction to commit.');
        }
        $this->fireConnectionEvent('committing');
        try {
            if (!$this->getPdo()->commit()) {
                throw new RuntimeException('Manticore refused COMMIT.');
            }
        } catch (Throwable $e) {
            $this->disconnect();
            throw new QueryException($this->getName(), 'COMMIT', [], $e);
        }
        $this->transactions = 0;
        $this->transactionsManager?->commit($this->getName(), 1, 0);
        $this->fireConnectionEvent('committed');
    }

    public function rollBack($toLevel = null)
    {
        if ($this->transactionLevel() !== 1 || ($toLevel !== null && $toLevel !== 0)) {
            throw new LogicException('Manticore can only roll back an active transaction in full.');
        }
        try {
            if (!$this->getPdo()->rollBack()) {
                throw new RuntimeException('Manticore refused ROLLBACK.');
            }
        } catch (Throwable $e) {
            $this->disconnect();
            throw new QueryException($this->getName(), 'ROLLBACK', [], $e);
        }
        $this->transactions = 0;
        $this->transactionsManager?->rollback($this->getName(), 0);
        $this->fireConnectionEvent('rollingBack');
    }

    protected function run($query, $bindings, Closure $callback)
    {
        $ddl = preg_match('/^\s*(?:(?:\/\*.*?\*\/)\s*)*(CREATE|ALTER|DROP|TRUNCATE|RENAME)\b/is', $query) === 1;
        if ($ddl && $this->transactionLevel() > 0) {
            throw new LogicException('Manticore DDL cannot run inside a transaction.');
        }
        if ($ddl) {
            $this->forgetSchema();
        }
        try {
            return parent::run($query, $bindings, $callback);
        } finally {
            if ($ddl) {
                $this->forgetSchema();
            }
        }
    }

    protected function handleQueryException(QueryException $e, $query, $bindings, Closure $callback)
    {
        // A lost response does not prove that a write failed. Never replay it automatically.
        throw $e;
    }

    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        // Keep Laravel's original SQL and bindings in exceptions, events and the query log.
        return parent::runQueryCallback($query, $bindings, static function ($sql, $parameters) use ($callback) {
            [$sql, $parameters] = NumericBindings::prepare($sql, $parameters);

            return $callback($sql, $parameters);
        });
    }
}
