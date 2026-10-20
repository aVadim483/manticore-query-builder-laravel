<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar;
use ReflectionMethod;

class ManticoreBlueprint extends Blueprint
{
    private ManticoreConnection $driverConnection;
    private array $driverOperations = [];
    public array $manticoreSettings = [];

    public function __construct(ManticoreConnection $connection, $table, ?Closure $callback = null)
    {
        $this->driverConnection = $connection;
        if ($connection->getSchemaGrammar() === null) {
            $connection->useDefaultSchemaGrammar();
        }
        if ((new ReflectionMethod(Blueprint::class, '__construct'))->getParameters()[0]->getName() === 'connection') {
            parent::__construct($connection, $table, $callback);
        } else {
            parent::__construct($table, $callback, $connection->getTablePrefix());
        }
    }

    public function multi(string $column)
    {
        return $this->addColumn('multi', $column);
    }

    public function multi64(string $column)
    {
        return $this->addColumn('multi64', $column);
    }

    public function floatVector(string $column, ?int $dimensions = null, string $similarity = 'l2')
    {
        $definition = $this->addColumn('floatVector', $column);
        if ($dimensions !== null) {
            $definition->knnDimensions = $dimensions;
            $definition->knnSimilarity = $similarity;
        }
        return $definition;
    }

    public function setting(string $name, int|float|string $value): static
    {
        $this->manticoreSettings[$name] = $value;

        return $this;
    }

    protected function addColumnDefinition($definition)
    {
        $this->driverOperations[] = $definition;

        return parent::addColumnDefinition($definition);
    }

    protected function addCommand($name, array $parameters = [])
    {
        $command = parent::addCommand($name, $parameters);
        $this->driverOperations[] = $command;

        return $command;
    }

    public function getDriverOperations(): array
    {
        return $this->driverOperations;
    }

    public function toSql(?Connection $connection = null, ?Grammar $grammar = null)
    {
        return $this->driverConnection->getSchemaBuilder()->getGrammar()->compileBlueprint($this);
    }

    public function build(?Connection $connection = null, ?Grammar $grammar = null)
    {
        // Compile and validate the entire blueprint before executing any irreversible DDL.
        foreach ($this->toSql() as $statement) {
            $this->driverConnection->statement($statement);
        }
    }
}
