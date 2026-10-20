<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Database;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor;
use InvalidArgumentException;

class ManticoreProcessor extends Processor
{
    public function processSelect(Builder $query, $results)
    {
        return $query instanceof ManticoreQueryBuilder ? $query->processReadRows($results) : $results;
    }

    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
    {
        if ($sequence !== null && $sequence !== 'id') {
            throw new InvalidArgumentException('Manticore only supports the document id.');
        }

        $connection = $query->getConnection();
        return $connection->insertAndGetId($sql, $values);
    }
}
