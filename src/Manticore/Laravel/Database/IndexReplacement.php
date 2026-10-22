<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use InvalidArgumentException;
use LogicException;

/** Maintenance-only RT replacement. No atomic swap, lock, model import or automatic deletion. */
final class IndexReplacement
{
    public function __construct(private ManticoreSchemaBuilder $schema, private string $active,
        private string $staged, private string $backup)
    {
        foreach ([$active, $staged, $backup] as $name) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) {
                throw new InvalidArgumentException('Replacement requires unqualified logical table names.');
            }
        }
        if (count(array_unique(array_map('strtolower', [$active, $staged, $backup]))) !== 3) {
            throw new InvalidArgumentException('Active, staged and backup names must be distinct.');
        }
    }

    public function inspect(): IndexReplacementState
    {
        $connection = $this->schema->getConnection();
        if ($connection->getConfig('read') || $connection->getConfig('write') || $connection->forgetCapabilities()->capabilities()->hasClusters) {
            throw new UnsupportedFeatureException('index_replacement', 'Replacement supports one non-clustered local connection without read/write splitting.');
        }
        $tables = [];
        foreach ($this->schema->getTables() as $table) {
            $tables[strtolower($table['name'])] = $table['engine'];
        }
        $present = [];
        foreach (['active', 'staged', 'backup'] as $role) {
            $physical = strtolower($connection->getTablePrefix().$this->$role);
            $present[$role] = isset($tables[$physical]);
            if ($present[$role] && $tables[$physical] !== 'rt') {
                throw new UnsupportedFeatureException('index_replacement', 'Replacement accepts local RT tables only: '.$this->$role);
            }
        }
        $layout = implode('', array_map(static fn ($value) => $value ? '1' : '0', array_values($present)));
        $status = match ($layout) {
            '110' => 'prepared', '011' => 'gap', '101' => 'switched', default => 'conflict',
        };
        return new IndexReplacementState($status, $this->active, $this->staged, $this->backup, $present);
    }

    public function switch(bool $maintenanceConfirmed): IndexReplacementState
    {
        $state = $this->beforeMutation($maintenanceConfirmed);
        if ($state->status === 'prepared') {
            $this->schema->rename($this->active, $this->backup);
            $state = $this->inspect();
        }
        if ($state->status === 'gap') {
            $this->schema->rename($this->staged, $this->active);
        }
        return $this->expectState('switched');
    }

    public function rollback(bool $maintenanceConfirmed): IndexReplacementState
    {
        $state = $this->beforeMutation($maintenanceConfirmed);
        if ($state->status === 'switched') {
            $this->schema->rename($this->active, $this->staged);
            $state = $this->inspect();
        }
        if ($state->status === 'gap') {
            $this->schema->rename($this->backup, $this->active);
        }
        return $this->expectState('prepared');
    }

    private function beforeMutation(bool $maintenanceConfirmed): IndexReplacementState
    {
        if (!$maintenanceConfirmed) {
            throw new LogicException('Stop/drain reads and writes and acquire an exclusive application lock before replacement.');
        }
        $state = $this->inspect();
        if ($state->status === 'conflict') {
            throw new LogicException('Conflicting replacement layout; inspect all three names before recovery.');
        }
        if (!$this->schema->getConnection()->capabilities()->advertises('buddy')) {
            throw new UnsupportedFeatureException('index_replacement', 'RT replacement requires Buddy; advertised presence does not guarantee rename will succeed.');
        }
        return $state;
    }

    private function expectState(string $expected): IndexReplacementState
    {
        $state = $this->inspect();
        if ($state->status !== $expected) {
            throw new LogicException('Replacement did not reach '.$expected.'; observed '.$state->status.'.');
        }
        return $state;
    }
}
