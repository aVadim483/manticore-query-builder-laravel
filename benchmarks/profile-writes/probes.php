<?php
declare(strict_types=1);

final class WriteProfileClock
{
    public static bool $enabled=false;
    public static array $stats=[];
    private static array $stack=[];
    public static function measure(string $stage,callable $callback):mixed
    {
        if(!self::$enabled)return $callback();
        $index=count(self::$stack);self::$stack[]=['start'=>hrtime(true),'children'=>0];
        try{return $callback();}finally{
            $frame=array_pop(self::$stack);$elapsed=hrtime(true)-$frame['start'];
            if($index>0)self::$stack[$index-1]['children']+=$elapsed;
            self::$stats[$stage]??=['calls'=>0,'inclusive_ns'=>0,'exclusive_ns'=>0];
            self::$stats[$stage]['calls']++;
            self::$stats[$stage]['inclusive_ns']+=$elapsed;
            self::$stats[$stage]['exclusive_ns']+=$elapsed-$frame['children'];
        }
    }
}

class WriteProfileStatement extends PDOStatement
{
    protected function __construct(){}
    public function execute(?array $params=null):bool
    {
        return WriteProfileClock::measure('pdo.execute',fn()=>parent::execute($params));
    }
}

class WriteProfilePDO extends PDO
{
    public function prepare(string $query,array $options=[]):PDOStatement|false
    {
        return WriteProfileClock::measure('pdo.prepare',fn()=>parent::prepare($query,$options));
    }
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false
    {
        return WriteProfileClock::measure('pdo.query',fn()=> $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$args));
    }
    public function exec(string $statement):int|false
    {
        return WriteProfileClock::measure('pdo.exec',fn()=>parent::exec($statement));
    }
}
