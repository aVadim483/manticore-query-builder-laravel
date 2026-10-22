<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use Closure;
use Illuminate\Database\Schema\Builder;
use LogicException;
use UnexpectedValueException;

class ManticoreSchemaBuilder extends Builder
{
    public function getGrammar(): ManticoreSchemaGrammar
    {
        return $this->grammar;
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
