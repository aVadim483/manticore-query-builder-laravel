<?php
declare(strict_types=1);

[$script,$app,$source,$port]=$argv;
require $app.'/vendor/autoload.php';
spl_autoload_register(static function(string $class)use($source):void {
    $prefix='avadim\\Manticore\\Laravel\\';
    if(str_starts_with($class,$prefix)) {
        $file=$source.'/src/Manticore/Laravel/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if(is_file($file))require $file;
    }
},true,true);
require __DIR__.'/trace.php';

use avadim\Manticore\Laravel\Database\ManticoreConnection;
use avadim\Manticore\Laravel\Database\ManticoreQueryBuilder;
use Illuminate\Container\Container;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Expression;
use Illuminate\Events\Dispatcher;

$pdo=new PipelinePDO('mysql:host=127.0.0.1;port='.(int)$port,null,null,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>true,
    PDO::ATTR_STRINGIFY_FETCHES=>false,PDO::MYSQL_ATTR_USE_BUFFERED_QUERY=>true,
]);
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PipelineStatement::class,[]]);
$table='pipe_'.bin2hex(random_bytes(6)).'_literalcheck';
$pdo->exec("CREATE TABLE $table (title text,brand string attribute,price float,category integer,stamp timestamp,details json,tags multi64,embedding float_vector KNN_TYPE='hnsw' KNN_DIMS='3' HNSW_SIMILARITY='l2')");
$report=['server'=>$pdo->query('SELECT VERSION()')->fetchColumn(),'port'=>(int)$port,
    'source_sha256'=>hash_file('sha256',(new ReflectionClass(ManticoreQueryBuilder::class))->getFileName()),'cases'=>[]];

function fingerprint(mixed $value):string {return hash('sha256',serialize($value));}
function bindingValues(array $bindings):array {
    return array_map(static fn($v)=>is_resource($v)?['resource'=>get_resource_type($v)]:$v,$bindings);
}
function rowData(int|string $id):array {
    return ['id'=>$id,'title'=>"Телефон ? :key 'quoted' ".chr(92),'brand'=>'Acme','price'=>55.3,
        'category'=>true,'stamp'=>new DateTimeImmutable('2026-10-26T10:00:00Z'),
        'details'=>['fraction'=>0.125,'flag'=>true],'tags'=>[1,'9007199254740993'],
        'embedding'=>[1.25,2.5,3.75]];
}
$names=['ordinary','float_edges','large_ids','numeric_strings','unicode_controls','json_scalar',
    'empty_mva','null_scalar','unknown_column','raw_quoted_marker','raw_unbound_marker','custom_expression',
    'nested_expression','resource','before_executing','late_before_executing','late_before_query',
    'builder_subclass','connection_subclass','rollback','commit','duplicate'];
try {
    foreach(['insert','replace'] as $verb)foreach($names as $name) {
        PipelineTrace::$enabled=false;
        $pdo->exec('TRUNCATE TABLE '.$table);
        $rows=[rowData(1),rowData(2)];
        if($name==='float_edges') {
            $rows=[];
            foreach([55.3,-55.3,0.0,-0.0,1e-7,1e-20,1.25e6,1e20] as $i=>$number) {
                $row=rowData($i+1);$row['price']=$number;$rows[]=$row;
            }
        }
        if($name==='large_ids')$rows=[rowData(9007199254740993),rowData(PHP_INT_MAX),rowData('18446744073709551615')];
        if($name==='numeric_strings')foreach($rows as &$row) {$row['price']='55.3';$row['category']='4294967295';} unset($row);
        if($name==='unicode_controls')foreach($rows as &$row) {
            $row['title']="Emoji 📦 ' \" ? ; DROP TABLE nope; ".chr(92).chr(0).chr(26)."\r\n\t";
            $row['brand']=str_repeat('длинная строка ',400);
            $row['details']=['note'=>"O'Reilly ? ".chr(92),'nested'=>['v'=>0.125,'null'=>null]];
        } unset($row);
        if($name==='json_scalar')foreach($rows as &$row)$row['details']=7.25; unset($row);
        if($name==='empty_mva')foreach($rows as &$row)$row['tags']=[]; unset($row);
        if($name==='null_scalar')$rows[1]['brand']=null;
        if($name==='unknown_column')foreach($rows as &$row)$row['unknown']='x'; unset($row);
        if($name==='raw_quoted_marker')$rows[0]['title']=new Expression("'? :key'");
        if($name==='raw_unbound_marker')$rows[0]['title']=new Expression('?');
        if($name==='custom_expression'||$name==='nested_expression') {
            $expr=new class implements \Illuminate\Contracts\Database\Query\Expression {
                public function getValue(\Illuminate\Database\Grammar $grammar) {return "'custom'";}
            };
            $rows[0]['title']=$name==='nested_expression'?new Expression($expr):$expr;
        }
        $resource=null;
        if($name==='resource') {
            $resource=fopen('php://memory','r+');fwrite($resource,'Stream ? '.chr(0).' value');rewind($resource);
            $rows[0]['title']=$resource;
        }
        if($name==='duplicate')$pdo->exec("INSERT INTO $table (id,title,price) VALUES (1,'old',1)");
        $config=['driver'=>'manticore'];
        $db=$name==='connection_subclass'
            ? new class($pdo,'','',$config) extends ManticoreConnection {
                public int $insertCalls=0;
                public function insert($query,$bindings=[]) {$this->insertCalls++;return parent::insert($query,$bindings);}
            }
            : new ManticoreConnection($pdo,'','',$config);
        $query=$name==='builder_subclass'
            ? (new class($db,$db->getQueryGrammar(),$db->getPostProcessor()) extends ManticoreQueryBuilder {
                public int $encodeCalls=0;
                protected function encodeWriteRows(array $values):array {$this->encodeCalls++;return parent::encodeWriteRows($values);}
            })->from($table)
            : $db->table($table);
        $events=[];$beforeCalls=0;$latePrice=55.3;
        $dispatcher=new Dispatcher(new Container());
        $db->setEventDispatcher($dispatcher);$db->enableQueryLog();
        $dispatcher->listen(QueryExecuted::class,static function($event)use(&$events,$table):void {
            if(preg_match('/^(insert|replace) /i',$event->sql))$events[]=[
                'sql'=>str_replace($table,'scratch',$event->sql),'bindings'=>bindingValues($event->bindings)];
        });
        $before=static function($sql)use(&$beforeCalls):void {
            if(preg_match('/^(insert|replace) /i',$sql))$beforeCalls++;
        };
        if($name==='before_executing')$db->beforeExecuting($before);
        if($name==='late_before_executing')$dispatcher->listen(QueryExecuted::class,static function($event)use($db,$before):void {
            if(str_starts_with(strtolower($event->sql),'describe '))$db->beforeExecuting($before);
        });
        if($name==='late_before_query') {
            $rows[0]['price']=&$latePrice;
            $dispatcher->listen(QueryExecuted::class,static function($event)use($query,&$latePrice):void {
                if(str_starts_with(strtolower($event->sql),'describe '))$query->beforeQuery(static function()use(&$latePrice):void {$latePrice=99.9;});
            });
        }
        $status=['ok'=>true];$result=null;
        PipelineTrace::begin(true);
        try {
            if(in_array($name,['rollback','commit'],true))$db->beginTransaction();
            $result=$query->{$verb}($rows);
            if($name==='rollback')$db->rollBack();
            elseif($name==='commit')$db->commit();
        } catch(Throwable $error) {
            $status=['ok'=>false,'exception'=>get_class($error),'previous'=>get_class($error->getPrevious()??$error),
                'code'=>(string)($error->getPrevious()?->getCode()??$error->getCode())];
            if($error instanceof \Illuminate\Database\QueryException)$status['context_hash']=fingerprint([
                str_replace($table,'scratch',$error->getSql()),bindingValues($error->getBindings())]);
            if($db->transactionLevel())$db->rollBack();
        } finally {PipelineTrace::$enabled=false;}
        $wire=[];$methods=[];
        foreach(PipelineTrace::$records as $record)if(preg_match('/^(insert|replace) /i',$record['sql'])) {
            $methods[]=$record['method'];
            if(isset($record['literal_sql']))$wire[]=str_replace($table,'scratch',$record['literal_sql']);
        }
        $log=[];
        foreach($db->getQueryLog() as $entry)if(preg_match('/^(insert|replace) /i',$entry['query']))$log[]=[
            'sql'=>str_replace($table,'scratch',$entry['query']),'bindings'=>bindingValues($entry['bindings'])];
        $stored=$pdo->query("SELECT * FROM $table ORDER BY id ASC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
        $report['cases'][$verb.'_'.$name]=[
            'status'=>$status,'return_type'=>get_debug_type($result),'return_value'=>$result,
            'rows'=>count($stored),'data_hash'=>fingerprint($stored),'event_hash'=>fingerprint($events),
            'query_log_hash'=>fingerprint($log),'wire_hash'=>$wire?fingerprint($wire):null,
            'methods'=>$methods,'before_calls'=>$beforeCalls,
            'builder_hook'=>$query->encodeCalls??null,'connection_hook'=>$db->insertCalls??null,
            'modified'=>$db->hasModifiedRecords(),'transaction_level'=>$db->transactionLevel(),
        ];
        if(is_resource($resource))fclose($resource);
    }
    foreach(['valid_unavailable','invalid_nonfinite','pretend'] as $name) {
        $calls=0;
        $db=new ManticoreConnection(static function()use(&$calls) {$calls++;throw new LogicException('Unavailable test PDO');},
            '','',['driver'=>'manticore','cast_attributes'=>false]);
        $rows=[['id'=>1,'title'=>'Phone','price'=>55.3],['id'=>2,'title'=>'Other','price'=>12.75]];
        if($name==='invalid_nonfinite')$rows[1]['price']=INF;
        try {
            $value=$name==='pretend'?$db->pretend(fn()=>$db->table('products')->insert($rows)):$db->table('products')->insert($rows);
            if($name==='pretend')$value=array_map(static fn($r)=>['query'=>$r['query'],'bindings'=>$r['bindings']],$value);
            $result=['ok'=>true,'value_hash'=>fingerprint($value)];
        } catch(Throwable $error) {
            $result=['ok'=>false,'exception'=>get_class($error),'previous'=>get_class($error->getPrevious()??$error)];
        }
        $report['cases'][$name]=$result+['pdo_resolutions'=>$calls];
    }
    $report['ok']=true;
} finally {PipelineTrace::$enabled=false;$pdo->exec('DROP TABLE IF EXISTS '.$table);}
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
