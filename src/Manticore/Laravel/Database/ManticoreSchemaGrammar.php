<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\Schema\Grammars\Grammar;
use LogicException;
use InvalidArgumentException;

class ManticoreSchemaGrammar extends Grammar
{
    public function compileBlueprint(ManticoreBlueprint $blueprint): array
    {
        if ($blueprint->temporary || $blueprint->engine || $blueprint->charset || $blueprint->collation) {
            throw new LogicException('Temporary tables, SQL engines, charsets and collations are not supported.');
        }
        $commands = [];
        foreach ($blueprint->getCommands() as $command) {
            if ($command instanceof ColumnDefinition) {
                continue;
            }
            if (!in_array($command->name, ['create', 'drop', 'dropIfExists', 'dropColumn', 'rename'], true)) {
                throw new LogicException('Unsupported Manticore schema command: '.$command->name);
            }
            $commands[] = $command;
        }
        // Validate all columns before returning even the first SQL statement.
        $definitions = [];
        $columnDefinitions = [];
        foreach ($blueprint->getColumns() as $column) {
            if ($column->change && ($blueprint->creating() || $column->type !== 'bigInteger')) {
                throw new LogicException('Manticore change() only widens an existing int attribute to bigint.');
            }
            if (!$blueprint->creating() && strtolower($column->name) === 'id') {
                throw new LogicException('The implicit document id cannot be added or changed.');
            }
            $definition = $this->columnDefinition($column);
            $columnDefinitions[spl_object_id($column)] = $definition;
            if ($definition !== null) {
                $definitions[] = $definition;
            }
        }
        $table = $this->wrapTable($blueprint);
        if ($blueprint->creating()) {
            if (count($commands) !== 1) {
                throw new LogicException('A Manticore CREATE blueprint cannot mix schema commands.');
            }
            $sql = 'create table '.$table.' ('.implode(', ', $definitions).')';
            $sql .= $this->compileSettings($blueprint->manticoreSettings, false);

            return [$sql];
        }
        $sql = [];
        $settings = [];
        foreach ($blueprint->getDriverOperations() as $command) {
            if (!($command instanceof ColumnDefinition) && $command->name === 'setting') {
                $settings[$command->setting] = $command->value;
                continue;
            }
            if ($settings) {
                $sql[] = 'alter table '.$table.$this->compileSettings($settings, true);
                $settings = [];
            }
            if ($command instanceof ColumnDefinition) {
                $definition = $columnDefinitions[spl_object_id($command)];
                if ($definition !== null) {
                    $sql[] = 'alter table '.$table.($command->change ? ' modify column ' : ' add column ').$definition;
                }
                continue;
            }
            if ($command->name === 'rename') {
                if ($definitions || $blueprint->manticoreSettings || count($commands) !== 1) {
                    throw new LogicException('A Manticore RENAME blueprint cannot mix schema commands.');
                }
                if (!is_string($command->to) || $command->to === '' || preg_match('/[.\s*]/', $command->to)
                    || strcasecmp($blueprint->getTable(), $command->to) === 0) {
                    throw new InvalidArgumentException('Manticore RENAME requires a different, unqualified table name.');
                }
                $sql[] = 'alter table '.$table.' rename '.$this->wrapTable($command->to);
            } elseif ($command->name === 'drop' || $command->name === 'dropIfExists') {
                if ($definitions || $blueprint->manticoreSettings || count($commands) !== 1) {
                    throw new LogicException('A Manticore DROP blueprint cannot mix schema commands.');
                }
                $sql[] = 'drop table '.($command->name === 'dropIfExists' ? 'if exists ' : '').$table;
            } elseif ($command->name === 'dropColumn') {
                foreach ($command->columns as $column) {
                    if (strtolower($column) === 'id') {
                        throw new LogicException('The document id cannot be dropped.');
                    }
                    $sql[] = 'alter table '.$table.' drop column '.$this->wrap($column);
                }
            }
        }
        if ($settings) {
            $sql[] = 'alter table '.$table.$this->compileSettings($settings, true);
        }
        return $sql;
    }

    private function compileSettings(array $settings, bool $alter): string
    {
        $sql = '';
        foreach ($settings as $name => $value) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', (string)$name)
                || (is_float($value) && !is_finite($value)) || strpbrk((string)$value, "\r\n\0") !== false) {
                throw new InvalidArgumentException('Invalid Manticore table setting.');
            }
            if ($alter) {
                $this->validateAlterSetting((string)$name, $value);
            }
            $sql .= ' '.$name.'='.$this->escape((string)$value);
        }
        return $sql;
    }

    private function validateAlterSetting(string $name, int|float|string $value): void
    {
        $valid = match ($name) {
            'min_prefix_len', 'min_infix_len', 'min_word_len' => preg_match('/^[0-9]+$/D', (string)$value) === 1,
            'expand_keywords', 'index_exact_words', 'html_strip' => in_array((string)$value, ['0', '1'], true),
            'dict' => in_array($value, ['keywords', 'crc'], true),
            'rt_mem_limit' => preg_match('/^[1-9][0-9]*[KMG]?$/Di', (string)$value) === 1,
            'morphology', 'charset_table', 'stopwords', 'prefix_fields' => is_string($value),
            default => throw new LogicException('Unsupported Manticore ALTER setting: '.$name),
        };
        if (!$valid) {
            throw new InvalidArgumentException('Invalid value for Manticore ALTER setting: '.$name);
        }
    }

    protected function columnDefinition(ColumnDefinition $column): ?string
    {
        $attributes = $column->getAttributes();
        $allowed = ['type', 'name', 'length', 'autoIncrement', 'unsigned', 'precision', 'stored', 'indexed', 'knnDimensions', 'knnSimilarity', 'change'];
        foreach ($attributes as $key => $value) {
            if (!in_array($key, $allowed, true) && !($key === 'nullable' && $value === false)) {
                throw new LogicException('Unsupported Manticore column modifier: '.$key);
            }
        }
        if (strtolower($column->name) === 'id') {
            if (!in_array($column->type, ['integer', 'bigInteger'], true)) {
                throw new LogicException('The implicit document id is always a 64-bit integer.');
            }

            return null;
        }
        if ($column->autoIncrement || ($column->unsigned && $column->type === 'bigInteger')) {
            throw new LogicException('Auto-increment and unsigned bigint are only supported for the document id.');
        }
        $type = match ($column->type) {
            'string' => 'string', 'text' => 'text', 'integer' => 'int', 'bigInteger' => 'bigint',
            'float' => 'float', 'boolean' => 'bool', 'timestamp' => 'timestamp', 'json' => 'json',
            'multi' => 'multi', 'multi64' => 'multi64', 'floatVector' => 'float_vector',
            default => throw new LogicException('Unsupported Manticore column type: '.$column->type),
        };
        if ($column->type === 'timestamp' && $column->precision) {
            throw new LogicException('Manticore timestamps do not support fractional precision.');
        }
        if (($column->stored !== null || $column->indexed !== null) && $type !== 'text') {
            throw new LogicException('Stored/indexed modifiers are supported only for text fields.');
        }
        $sql = $this->wrap($column->name).' '.$type;
        if ($column->knnDimensions !== null || $column->knnSimilarity !== null) {
            if ($type !== 'float_vector' || !is_int($column->knnDimensions) || $column->knnDimensions <= 0
                || !in_array($column->knnSimilarity, ['l2', 'cosine', 'ip'], true)) {
                throw new InvalidArgumentException('KNN indexing requires a float vector, positive dimensions and l2/cosine/ip similarity.');
            }
            $sql .= " knn_type='hnsw' knn_dims=".$this->escape((string)$column->knnDimensions)
                .' hnsw_similarity='.$this->escape($column->knnSimilarity);
        }
        if ($type === 'text' && ($column->stored !== null || $column->indexed !== null)) {
            $stored = $column->stored ?? true;
            $indexed = $column->indexed ?? true;
            if (!$stored && !$indexed) {
                throw new LogicException('A text field must be stored or indexed.');
            }
            $sql .= ($stored ? ' stored' : '').($indexed ? ' indexed' : '');
        }

        return $sql;
    }

    protected function wrapValue($value)
    {
        return $value === '*' ? $value : '`'.str_replace('`', '``', $value).'`';
    }
}
