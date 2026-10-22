<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use Closure;
use Illuminate\Database\Schema\Builder;
use LogicException;
use UnexpectedValueException;
use InvalidArgumentException;

class ManticoreSchemaBuilder extends Builder
{
    public function getGrammar(): ManticoreSchemaGrammar
    {
        return $this->grammar;
    }

    public function getCreateStatement(string $table): string
    {
        $row = $this->connection->selectFromWriteConnection('SHOW CREATE TABLE '.$this->grammar->wrapTable($table))[0] ?? null;
        if ($row === null || !isset($row->{'Create Table'})) {
            throw new UnexpectedValueException('SHOW CREATE TABLE did not return a definition.');
        }
        return $row->{'Create Table'};
    }

    public function replacement(string $active, string $staged, string $backup): IndexReplacement
    {
        return new IndexReplacement($this, $active, $staged, $backup);
    }

    /** Empty, server-side schema clone on the same connection; never copies documents. */
    public function createLike(string $table, string $source): void
    {
        foreach ([$table, $source] as $name) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) {
                throw new InvalidArgumentException('CREATE LIKE requires logical table names.');
            }
        }
        if ($this->connection->getConfig('read') || $this->connection->getConfig('write') || $this->connection->forgetCapabilities()->capabilities()->hasClusters) {
            throw new UnsupportedFeatureException('create_like', 'Schema cloning supports a single non-clustered local connection.');
        }
        $physical = $this->connection->getTablePrefix().$source;
        $found = false;
        foreach ($this->getTables() as $candidate) {
            if (strcasecmp($candidate['name'], $physical) === 0) {
                $found = true;
                if ($candidate['engine'] !== 'rt') {
                    throw new UnsupportedFeatureException('create_like', 'Only local RT schemas can be cloned.');
                }
            }
        }
        if (!$found) {
            // Preserve the native SQL error for a missing source.
            $this->getCreateStatement($source);
        }
        $this->connection->statement('CREATE TABLE '.$this->grammar->wrapTable($table).' LIKE '.$this->grammar->wrapTable($source));
    }

    /** HNSW metadata from the server's canonical CREATE, never inferred from module presence. */
    public function getVectorDefinition(string $table, string $column): array
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $column)) {
            throw new InvalidArgumentException('A vector column must be an attribute name.');
        }
        $ddl = $this->getCreateStatement($table);
        if (!preg_match('/^\s*`?'.preg_quote($column, '/').'`?\s+float_vector\s+([^\r\n]+)/mi', $ddl, $line)
            || !preg_match('/\bknn_type\s*=\s*\x27hnsw\x27/i', $line[1])
            || !preg_match('/\bknn_dims\s*=\s*\x27([0-9]+)\x27/i', $line[1], $dimensions)
            || !preg_match('/\bhnsw_similarity\s*=\s*\x27(l2|cosine|ip)\x27/i', $line[1], $metric)) {
            throw new UnsupportedFeatureException('knn', 'The selected column has no supported manual HNSW definition.');
        }
        return ['dimensions' => (int)$dimensions[1], 'metric' => strtolower($metric[1])];
    }

    public function validateKnnVector(string $table, string $column, array $vector): array
    {
        if (!array_is_list($vector) || !$vector) {
            throw new InvalidArgumentException('A KNN vector must be a non-empty list.');
        }
        foreach ($vector as $value) {
            if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value)) {
                throw new InvalidArgumentException('KNN vector entries must be finite numbers.');
            }
        }
        $definition = $this->getVectorDefinition($table, $column);
        if (count($vector) !== $definition['dimensions'] || ($definition['metric'] === 'cosine' && !array_filter($vector))) {
            throw new InvalidArgumentException('The vector must match HNSW dimensions and be nonzero for cosine.');
        }
        return $definition;
    }

    protected function createBlueprint($table, ?Closure $callback = null)
    {
        if (isset($this->resolver)) {
            throw new LogicException('Custom Blueprint resolvers are not supported by the Manticore driver.');
        }

        return new ManticoreBlueprint($this->connection, $table, $callback);
    }

    public function getTables($schema = null)
    {
        if ($schema !== null) {
            throw new LogicException('Manticore does not use SQL database schemas.');
        }

        return array_map(static function ($row) {
            $row = array_change_key_case((array)$row, CASE_LOWER);
            $name = $row['table'] ?? $row['index'];

            return ['name' => $name, 'schema' => null, 'schema_qualified_name' => $name,
                'size' => null, 'comment' => null, 'collation' => null, 'engine' => $row['type']];
        }, $this->connection->selectFromWriteConnection('SHOW TABLES'));
    }

    public function hasTable($table)
    {
        $name = $this->connection->getTablePrefix().$table;
        foreach ($this->getTables() as $candidate) {
            if (strcasecmp($candidate['name'], $name) === 0) {
                return true;
            }
        }

        return false;
    }

    public function getColumns($table)
    {
        $columns = [];
        foreach ($this->connection->getTableSchema($table) as $name => $info) {
            $columns[] = ['name' => $name, 'type' => $info['type'], 'type_name' => $info['type'],
                'collation' => null, 'nullable' => $info['type'] === 'json', 'default' => null,
                'auto_increment' => $name === 'id', 'comment' => null, 'generation' => null];
        }

        return $columns;
    }

    /** @return array<string, string> Reported settings only; defaults are not inferred. */
    public function getSettings(string $table): array
    {
        $rows = $this->connection->selectFromWriteConnection('SHOW TABLE '.$this->grammar->wrapTable($table).' SETTINGS');
        $settings = [];
        foreach ($rows as $row) {
            $row = array_change_key_case((array)$row, CASE_LOWER);
            if (($row['variable_name'] ?? null) !== 'settings') {
                continue;
            }
            foreach (preg_split('/\r?\n/', $row['value']) as $line) {
                if (trim($line) === '') {
                    continue;
                }
                if (!preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*=\s*(.*)$/D', $line, $matches)
                    || array_key_exists($matches[1], $settings)) {
                    throw new UnexpectedValueException('Unrecognized Manticore table settings output.');
                }
                $settings[$matches[1]] = $matches[2];
            }
        }
        return $settings;
    }

    public function createDatabase($name)
    {
        throw new LogicException('Manticore does not use SQL databases.');
    }

    public function dropDatabaseIfExists($name)
    {
        throw new LogicException('Manticore does not use SQL databases.');
    }
}
