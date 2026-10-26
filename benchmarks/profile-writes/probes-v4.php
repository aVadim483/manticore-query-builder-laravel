<?php
declare(strict_types=1);

use avadim\Manticore\Laravel\Database\ManticoreConnection;
use avadim\Manticore\Laravel\Database\ManticoreGrammar;
use avadim\Manticore\Laravel\Database\ManticoreQueryBuilder;
use avadim\Manticore\Laravel\Database\NumericBindings;
use Illuminate\Database\Query\Builder;

class WriteProfileQuery extends ManticoreQueryBuilder
{
    public function insert(array $values)
    {
        return WriteProfileClock::measure('builder.insert',fn()=>parent::insert($values));
    }
    public function replace(array $values):bool
    {
        return WriteProfileClock::measure('builder.replace',fn()=>parent::replace($values));
    }
    protected function encodeWriteRows(array $values):array
    {
        return WriteProfileClock::measure('builder.encodeWriteRows',fn()=>parent::encodeWriteRows($values));
    }
    protected function compileReplacement(array $values):array
    {
        return WriteProfileClock::measure('builder.compileReplacement',fn()=>parent::compileReplacement($values));
    }
    public function cleanBindings(array $bindings)
    {
        return WriteProfileClock::measure('builder.cleanBindings',fn()=>parent::cleanBindings($bindings));
    }
}

class WriteProfileGrammar extends ManticoreGrammar
{
    public function compileInsert(Builder $query,array $values)
    {
        return WriteProfileClock::measure('grammar.compileInsert',fn()=>parent::compileInsert($query,$values));
    }
}

class WriteProfileConnection extends ManticoreConnection
{
    public static array $lastInput=[];
    public static mixed $normalizer=null;
    public function query()
    {
        return new WriteProfileQuery($this,$this->getQueryGrammar(),$this->getPostProcessor());
    }
    protected function getDefaultQueryGrammar()
    {
        return new WriteProfileGrammar($this);
    }
    public function prepareBindings(array $bindings)
    {
        return WriteProfileClock::measure('connection.prepareBindings',fn()=>parent::prepareBindings($bindings));
    }
    public function bindValues($statement,$bindings)
    {
        return WriteProfileClock::measure('connection.bindValues',fn()=>parent::bindValues($statement,$bindings));
    }
    protected function runQueryCallback($query,$bindings,Closure $callback)
    {
        self::$lastInput=[$query,$bindings];
        // Same wrapper as the released driver, with one timer around the unchanged numeric renderer.
        return Illuminate\Database\Connection::runQueryCallback($query,$bindings,static function($sql,$parameters)use($callback){
            [$sql,$parameters]=WriteProfileClock::measure('numeric.prepare',static fn()=>
                self::$normalizer===null?NumericBindings::prepare($sql,$parameters):(self::$normalizer)($sql,$parameters));
            return $callback($sql,$parameters);
        });
    }
}
