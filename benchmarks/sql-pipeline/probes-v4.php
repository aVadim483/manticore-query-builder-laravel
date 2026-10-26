<?php
declare(strict_types=1);

use avadim\Manticore\Laravel\Database\ManticoreConnection;
use avadim\Manticore\Laravel\Database\ManticoreGrammar;
use avadim\Manticore\Laravel\Database\ManticoreQueryBuilder;
use Illuminate\Database\Query\Builder;

class PipelineGrammar extends ManticoreGrammar
{
    public function compileSelect(Builder $query)
    {
        if (PipelineTrace::$enabled) PipelineTrace::$compilations['select']=(PipelineTrace::$compilations['select']??0)+1;
        if (!PipelineTrace::$enabled) return parent::compileSelect($query);
        $start=hrtime(true);
        try { return parent::compileSelect($query); }
        finally { PipelineTrace::$compileMs+=(hrtime(true)-$start)/1e6; }
    }
    public function compileInsert(Builder $query, array $values)
    {
        if (PipelineTrace::$enabled) PipelineTrace::$compilations['insert']=(PipelineTrace::$compilations['insert']??0)+1;
        if (!PipelineTrace::$enabled) return parent::compileInsert($query,$values);
        $start=hrtime(true);
        try { return parent::compileInsert($query,$values); }
        finally { PipelineTrace::$compileMs+=(hrtime(true)-$start)/1e6; }
    }
    public function compileUpdate(Builder $query, array $values)
    {
        if (PipelineTrace::$enabled) PipelineTrace::$compilations['update']=(PipelineTrace::$compilations['update']??0)+1;
        if (!PipelineTrace::$enabled) return parent::compileUpdate($query,$values);
        $start=hrtime(true);
        try { return parent::compileUpdate($query,$values); }
        finally { PipelineTrace::$compileMs+=(hrtime(true)-$start)/1e6; }
    }
}

class PipelineConnection extends ManticoreConnection
{
    protected function getDefaultQueryGrammar() { return new PipelineGrammar($this); }
}
