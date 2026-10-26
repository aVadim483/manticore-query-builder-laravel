<?php
declare(strict_types=1);

/** Correctness probes beyond the warmed, listener-free timing fixture. */
[$script,$root,$snapshot]=$argv;
require $root.'/vendor/autoload.php';
spl_autoload_register(static function(string $class)use($snapshot):void {
    $prefix='avadim\\Manticore\\Laravel\\';
    if(str_starts_with($class,$prefix)) {
        $file=$snapshot.'/src/Manticore/Laravel/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if(is_file($file))require $file;
    }
},true,true);

use avadim\Manticore\Laravel\Database\ManticoreConnection;
use avadim\Manticore\Laravel\Database\ManticoreQueryBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container;

enum PipelineInt:int {case Value=7;}
enum PipelineUnit {case Ready;}
class EncodingHookQuery extends ManticoreQueryBuilder
{
    public static int $calls=0;
    protected function encodeWriteRows(array $values):array
    {
        self::$calls++;
        return parent::encodeWriteRows($values);
    }
}
class EdgeConnection extends ManticoreConnection
{
    public function query() {return new EncodingHookQuery($this,$this->getQueryGrammar(),$this->getPostProcessor());}
}
$pdo=new PDO('mysql:host=127.0.0.1;port=19306',null,null,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>true,
    PDO::ATTR_STRINGIFY_FETCHES=>false,
]);
$db=($argv[3]??null)==='--native'
    ? new ManticoreConnection($pdo,'','',['driver'=>'manticore'])
    : new EdgeConnection($pdo,'','',['driver'=>'manticore']);
$table='pipe_'.bin2hex(random_bytes(6)).'_edges';
$pdo->exec("CREATE TABLE $table (title text,brand string attribute,price float,category integer,details json,tags multi)");
$report=['source_sha256'=>hash_file('sha256',(new ReflectionClass(ManticoreQueryBuilder::class))->getFileName()),
    'clean_bindings_cases'=>0,'vector_cases'=>0,'writes'=>[],'cold_schema_listener'=>[]];
try {
    $query=$db->table($table);
    $originalClean=new ReflectionMethod(Builder::class,'cleanBindings');
    $pool=[null,false,true,0,1,-1,PHP_INT_MAX,55.3,'? :key \'quoted\'','55.3',
        new Expression('NOW()'),PipelineInt::Value,PipelineUnit::Ready,new DateTimeImmutable('2026-01-01')];
    mt_srand(261026);
    foreach(range(1,1000) as $index) {
        $bindings=[];
        foreach(range(1,20) as $key)$bindings[$key*2]=$pool[mt_rand(0,count($pool)-1)];
        if($originalClean->invoke($query,$bindings)!==$query->cleanBindings($bindings))throw new RuntimeException('cleanBindings parity failed');
        $report['clean_bindings_cases']++;
    }
    foreach(range(1,1000) as $index) {
        $vector=[$index,-0.0,1e-7,1e20,-3.5,mt_rand()/mt_getrandmax()];
        $options=$index%2?['oversampling'=>2.0,'rescore'=>true,'ef'=>64]:[];
        $sql=$db->table($table)->select('id')->knn('embedding',$vector,20,$options)->toSql();
        $expected='('.implode(', ',array_map(static fn($v)=>json_encode($v,JSON_THROW_ON_ERROR),$vector)).')';
        if(!str_contains($sql,$expected))throw new RuntimeException('Vector SQL parity failed');
        if($options && !str_contains($sql,'oversampling=2.0'))throw new RuntimeException('KNN option type changed');
        $report['vector_cases']++;
    }
    $rows=[
        ['id'=>1,'title'=>"Телефон ? :price 'quoted' ".chr(92),'brand'=>'Acme','price'=>55.3,'category'=>false,'details'=>['fraction'=>0.125,'null'=>null],'tags'=>[1,2]],
        ['id'=>42,'title'=>'Emoji 📦','brand'=>'','price'=>-0.0,'category'=>true,'details'=>['a'=>'? :key'],'tags'=>[]],
        ['id'=>9007199254740993,'title'=>'Large ID','brand'=>'','price'=>1e-7,'category'=>4294967295,'details'=>7.25,'tags'=>[0,4294967295]],
        ['id'=>PHP_INT_MAX,'title'=>'Max ID','brand'=>'Nova','price'=>1e20,'category'=>0,'details'=>null,'tags'=>[1]],
    ];
    foreach(['insert','replace','insertGetId','replaceGetId'] as $method) {
        $pdo->exec('TRUNCATE TABLE '.$table);
        EncodingHookQuery::$calls=0;
        try {
            if(str_ends_with($method,'GetId'))foreach($rows as $row)$db->table($table)->{$method}($row);
            else $db->table($table)->{$method}($rows);
            $stored=$pdo->query("SELECT * FROM $table ORDER BY id ASC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
            $report['writes'][$method]=['ok'=>true,'digest'=>hash('sha256',json_encode($stored,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION)),
                'encoder_hook_calls'=>EncodingHookQuery::$calls];
        } catch(Throwable $error) {
            $report['writes'][$method]=['ok'=>false,'exception'=>get_class($error),'error'=>$error->getMessage(),
                'encoder_hook_calls'=>EncodingHookQuery::$calls];
        }
    }
    $pdo->exec('TRUNCATE TABLE '.$table);
    try {
        $db->table($table)->insert(['id'=>10,'title'=>'Null probe','brand'=>null]);
        $report['scalar_null']=['accepted'=>true];
    } catch(Throwable $error) {
        $report['scalar_null']=['accepted'=>false,'exception'=>get_class($error),
            'previous_code'=>$error->getPrevious()?->getCode()];
    }
    foreach(['get','search'] as $method) {
        $pdo->exec('TRUNCATE TABLE '.$table);
        $db->table($table)->insert([
            ['id'=>1,'title'=>'Phone','category'=>0],['id'=>2,'title'=>'Phone','category'=>1],
        ]);
        $db->forgetSchema();
        $dispatcher=new Dispatcher(new Container());
        $db->setEventDispatcher($dispatcher);
        $query=$db->table($table)->select('id');
        $dispatcher->listen(Illuminate\Database\Events\QueryExecuted::class,static function($event)use($query):void {
            if(str_starts_with(strtolower($event->sql),'describe '))$query->where('category',1);
        });
        try {
            $result=$query->{$method}();
            $ids=($method==='search'?$result->rows():$result)->pluck('id')->all();
            $report['cold_schema_listener'][$method]=['ok'=>true,'ids'=>$ids];
        } catch(Throwable $error) {
            $report['cold_schema_listener'][$method]=['ok'=>false,'exception'=>get_class($error),'error'=>$error->getPrevious()?->getMessage()??$error->getMessage()];
        } finally {$db->unsetEventDispatcher();}
    }
    $report['ok']=true;
} finally {$pdo->exec('DROP TABLE IF EXISTS '.$table);}
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
