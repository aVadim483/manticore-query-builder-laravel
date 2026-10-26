<?php
declare(strict_types=1);

[$script,$root,$client,$configPath]=$argv;
require $root.'/vendor/autoload.php';
if (isset($argv[4]) && $argv[4] !== '-') {
    $source=rtrim($argv[4],'/').'/src/Manticore/Laravel/';
    spl_autoload_register(static function(string $class)use($source):void {
        $prefix='avadim\\Manticore\\Laravel\\';
        if(str_starts_with($class,$prefix)) {
            $file=$source.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
            if(is_file($file))require $file;
        }
    },true,true);
}
$app=require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/../compare-versions/adapter.php';
require __DIR__.'/../compare-versions/fixtures.php';
require __DIR__.'/trace.php';
if($client==='v4')require __DIR__.'/probes-v4.php';
$config=json_decode(file_get_contents($configPath),true,512,JSON_THROW_ON_ERROR);
$config['count_sql']=false;
$driver=new DriverComparison($client,$config);
$pdo=new PipelinePDO('mysql:host=127.0.0.1;port=19306',null,null,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>true,
    PDO::ATTR_STRINGIFY_FETCHES=>false,PDO::MYSQL_ATTR_USE_BUFFERED_QUERY=>true,
    PDO::ATTR_TIMEOUT=>5,
]);
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PipelineStatement::class,[]]);
$driver->pdo=$pdo;
$connectionProperty=new ReflectionProperty(DriverComparison::class,'connection');
if($client==='v4') {
    $original=$connectionProperty->getValue($driver);
    $connection=($config['native_builder']??false)
        ? new avadim\Manticore\Laravel\Database\ManticoreConnection($pdo,'','',$original->getConfig())
        : new PipelineConnection($pdo,'','',$original->getConfig());
    $connection->disableQueryLog(); $connection->unsetEventDispatcher();
    $connectionProperty->setValue($driver,$connection);
} elseif($client==='v3') {
    $connection=$connectionProperty->getValue($driver);
    $backend=(new ReflectionProperty(avadim\Manticore\QueryBuilder\Connection::class,'client'))->getValue($connection);
    (new ReflectionProperty($backend,'dbh'))->setValue($backend,$pdo);
}
$write=$config['write_tables'][$client];
if(!preg_match('/^pipe_[a-f0-9]+_[a-z0-9_]+$/D',$write))throw new RuntimeException('Invalid scratch name');
$pdo->exec("CREATE TABLE $write (title text,brand string attribute,price float,category integer,details json,tags multi)");
$rows=comparisonWriteDocuments();
if(isset($config['write_size']) && $config['write_size']!==500) {
    $rows=[];
    foreach(range(1,$config['write_size']) as $id) {
        $row=comparisonDocument($id,3);unset($row['embedding']);$rows[]=$row;
    }
}
$GLOBALS['comparison_write_rows']=$rows;
$captures=[]; $ownsRead=false;
$loaded=$client==='v4'?(new ReflectionClass(avadim\Manticore\Laravel\Database\ManticoreQueryBuilder::class))->getFileName():null;
echo json_encode(['ready'=>true,'php'=>PHP_VERSION,'laravel'=>$app->version(),'client'=>$client,
    'package'=>Composer\InstalledVersions::getPrettyVersion('avadim/manticore-query-builder-laravel'),
    'native_builder'=>$config['native_builder']??false,
    'grammar_class'=>$client==='v4'?get_class($connection->getQueryGrammar()):null,
    'source'=>$loaded,'source_sha256'=>$loaded?hash_file('sha256',$loaded):null,
    'xdebug'=>extension_loaded('xdebug'),'emulated_prepares'=>$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),
    'buffered'=>$pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY)],JSON_THROW_ON_ERROR).PHP_EOL;

function runScenario(string $scenario):mixed {
    global $driver,$connection,$write;
    if($scenario==='write_update_float')return $connection->table($write)->where('id',1)->update(['price'=>55.3]);
    return $driver->run($scenario);
}
function normalizeScenario(string $scenario,mixed $result):mixed {
    global $driver,$config,$write,$rows;
    if(isset($config['write_size']) && str_starts_with($scenario,'write_')) {
        $stored=$driver->raw("SELECT id,title,brand,price,category,details,tags FROM $write ORDER BY id ASC LIMIT ".count($rows));
        $normalizer=new ReflectionMethod(DriverComparison::class,'rows');
        return array_map(static fn($a,$b)=>array_merge($a,$b),
            $normalizer->invoke($driver,$stored),$normalizer->invoke($driver,$stored,true));
    }
    return $driver->normalize($scenario==='write_update_float'?'write_update':$scenario,$result);
}
function resetWrite(string $scenario):void {
    global $driver,$write,$rows;
    if(!str_starts_with($scenario,'write_'))return;
    $driver->raw('TRUNCATE TABLE '.$write);
    if($scenario!=='write_insert500') {
        $seed=new DriverComparison('pdo',$driver->config);
        $GLOBALS['comparison_write_rows']=$rows;
        $seed->run('write_insert500');
    }
}
function digest(mixed $value):string {return hash('sha256',json_encode($value,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION));}
function replay(string $scenario,array $records,bool $literal):mixed {
    global $pdo;
    $firstRows=[];$total=0;
    foreach($records as $index=>$record) {
        if($literal) {
            $statement=$pdo->query($record['literal_sql']);
        } elseif($record['method']==='query') {
            $statement=$pdo->query($record['sql']);
        } else {
            $statement=$pdo->prepare($record['sql']);
            foreach($record['bindings'] as [$key,$value,$type])$statement->bindValue($key,$value,$type);
            $statement->execute($record['execute_params']);
        }
        $result=$statement->columnCount()?$statement->fetchAll(PDO::FETCH_OBJ):[];
        if($index===0)$firstRows=$result;
        if(preg_match('/^SHOW META/i',$record['sql']))foreach($result as $item) {
            if($item->Variable_name==='total_found')$total=(int)$item->Value;
        }
        while($statement->nextRowset())$statement->fetchAll(PDO::FETCH_OBJ);
        $statement->closeCursor();
    }
    return str_starts_with($scenario,'match_meta')?['rows'=>$firstRows,'total'=>$total]:$firstRows;
}
try {
    while($line=fgets(STDIN)) {
        try {
            $command=json_decode($line,true,512,JSON_THROW_ON_ERROR);
            if($command['op']==='quit')break;
            if($command['op']==='setup') {
                $read=$config['read_table'];
                if(!preg_match('/^pipe_[a-f0-9]+_read$/D',$read))throw new RuntimeException('Invalid read table');
                $pdo->exec("CREATE TABLE $read (title text,brand string attribute,price float,category integer,details json,tags multi,embedding float_vector KNN_TYPE='hnsw' KNN_DIMS='128' HNSW_SIMILARITY='l2') min_infix_len='2' index_exact_words='1'");
                $ownsRead=true;
                $seedConfig=$config;$seedConfig['write_tables']['pdo']=$read;
                $seed=new DriverComparison('pdo',$seedConfig);
                for($start=1;$start<=$config['documents'];$start+=250) {
                    $GLOBALS['comparison_write_rows']=array_map('comparisonDocument',range($start,min($config['documents'],$start+249)));
                    $seed->run('write_insert500');
                }
                $GLOBALS['comparison_write_rows']=$rows;
                $pdo->exec('FLUSH RAMCHUNK '.$read);
                echo json_encode(['ok'=>true,'version'=>$pdo->query('SELECT VERSION()')->fetchColumn(),
                    'count'=>(int)$pdo->query("SELECT COUNT(*) FROM $read")->fetchColumn()],JSON_THROW_ON_ERROR).PHP_EOL;
                continue;
            }
            $scenario=$command['scenario'];
            if($command['op']==='capture') {
                resetWrite($scenario);runScenario($scenario); // Warm schema, classes and caches.
                resetWrite($scenario);PipelineTrace::begin(true);
                $result=runScenario($scenario);$elapsed=(hrtime(true)-PipelineTrace::$start)/1e6;
                $metrics=PipelineTrace::finish($elapsed);
                $captures[$scenario]=PipelineTrace::$records;
                $hash=digest(normalizeScenario($scenario,$result));
                $trace=array_map(static function(array $r):array {
                    unset($r['entered_ns']);
                    $r['binding_count']=count($r['bindings'])+count($r['execute_params']??[]);
                    $r['sql_bytes']=strlen($r['sql']);$r['literal_bytes']=strlen($r['literal_sql']);
                    return $r;
                },$captures[$scenario]);
                echo json_encode(['ok'=>true,'digest'=>$hash,'trace'=>$trace,'metrics'=>$metrics],JSON_THROW_ON_ERROR).PHP_EOL;
                continue;
            }
            if($command['op']==='build') {
                $times=[];$compileTimes=[];$cpuTimes=[];$compilations=[];
                foreach(range(1,$command['iterations']) as $_) {
                    PipelineTrace::begin(false,true);
                    try {runScenario($scenario);throw new RuntimeException('Build did not stop at PDO');}
                    catch(Throwable $error) {
                        // 3.x wraps the sentinel without preserving the previous exception.
                        if(!PipelineTrace::$stoppedAtBoundary)throw $error;
                    } finally {PipelineTrace::$enabled=false;PipelineTrace::$stop=false;}
                    $record=PipelineTrace::$records[0];
                    if($record['sql']!==$captures[$scenario][0]['sql'])throw new RuntimeException('Dry build SQL changed');
                    $times[]=($record['entered_ns']-PipelineTrace::$start)/1e6;
                    $compileTimes[]=PipelineTrace::$compileMs;
                    $cpuTimes[]=$record['cpu_before_pdo_ms'];
                    $compilations=PipelineTrace::$compilations;
                }
                echo json_encode(['ok'=>true,'times_ms'=>$times,'compile_times_ms'=>$compileTimes,'cpu_times_ms'=>$cpuTimes,'compilations'=>$compilations],JSON_THROW_ON_ERROR).PHP_EOL;
                continue;
            }
            resetWrite($scenario);
            if($command['op']==='plain') {
                PipelineTrace::$enabled=false;
                $start=hrtime(true);$result=runScenario($scenario);$elapsed=(hrtime(true)-$start)/1e6;
                echo json_encode(['ok'=>true,'metrics'=>['total_ms'=>$elapsed],
                    'digest'=>digest(normalizeScenario($scenario,$result))],JSON_THROW_ON_ERROR).PHP_EOL;
                continue;
            }
            PipelineTrace::begin();
            if($command['op']==='live')$result=runScenario($scenario);
            else $result=replay($scenario,$captures[$scenario],$command['op']==='literal');
            $elapsed=(hrtime(true)-PipelineTrace::$start)/1e6;
            $metrics=PipelineTrace::finish($elapsed);
            $hash=digest(normalizeScenario($scenario,$result));
            echo json_encode(['ok'=>true,'metrics'=>$metrics,'digest'=>$hash],JSON_THROW_ON_ERROR).PHP_EOL;
        } catch(Throwable $error) {
            PipelineTrace::$enabled=PipelineTrace::$stop=false;
            echo json_encode(['ok'=>false,'error'=>$error->getMessage(),'exception'=>get_class($error)],JSON_THROW_ON_ERROR).PHP_EOL;
        }
    }
} finally {
    PipelineTrace::$enabled=false;
    $pdo->exec('DROP TABLE IF EXISTS '.$write);
    if($ownsRead)$pdo->exec('DROP TABLE IF EXISTS '.$config['read_table']);
}
