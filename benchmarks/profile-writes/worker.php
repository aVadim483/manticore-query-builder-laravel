<?php
declare(strict_types=1);

[$script,$root,$client]=$argv;
require $root.'/vendor/autoload.php';
// Optional isolated working-tree snapshot; installed baseline packages remain untouched.
if (isset($argv[3])) {
    $source = rtrim($argv[3], '/').'/src/Manticore/Laravel/';
    spl_autoload_register(static function (string $class) use ($source): void {
        $prefix = 'avadim\\Manticore\\Laravel\\';
        if (str_starts_with($class, $prefix)) {
            $file = $source.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($file)) require $file;
        }
    }, true, true);
}
require __DIR__.'/probes.php';
require __DIR__.'/../compare-versions/fixtures.php';
if($client==='v4')require __DIR__.'/probes-v4.php';
if(is_file(__DIR__.'/prototype.php'))require __DIR__.'/prototype.php';
$config=['driver'=>'manticore','host'=>'127.0.0.1','port'=>19306,'timeout'=>5,'prefix'=>'','cast_attributes'=>true];
$options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>true,
    PDO::ATTR_STRINGIFY_FETCHES=>false,PDO::MYSQL_ATTR_USE_BUFFERED_QUERY=>true,PDO::ATTR_TIMEOUT=>5];
$pdo=new WriteProfilePDO('mysql:host=127.0.0.1;port=19306',null,null,$options);
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[WriteProfileStatement::class,[]]);
if($client==='v4'){
    $connection=new WriteProfileConnection($pdo,'','',$config);
    $connection->disableQueryLog();
}else{
    $connection=new avadim\Manticore\Laravel\Connection($config);
    $backend=(new ReflectionProperty(avadim\Manticore\QueryBuilder\Connection::class,'client'))->getValue($connection);
    (new ReflectionProperty($backend,'dbh'))->setValue($backend,$pdo);
}
$control=new PDO('mysql:host=127.0.0.1;port=19306',null,null,$options);
$table='write_profile_'.bin2hex(random_bytes(6));
$control->exec("CREATE TABLE $table (title text,brand string attribute,price float,category integer,details json,tags multi)");
$loadedSource=(new ReflectionClass($client==='v4'?avadim\Manticore\Laravel\Database\ManticoreQueryBuilder::class:avadim\Manticore\Laravel\Connection::class))->getFileName();
echo json_encode(['ready'=>true,'client'=>$client,'package'=>Composer\InstalledVersions::getPrettyVersion('avadim/manticore-query-builder-laravel'),'php'=>PHP_VERSION,'table'=>$table,
    'loaded_source'=>$loadedSource,'loaded_source_sha256'=>hash_file('sha256',$loadedSource)],JSON_THROW_ON_ERROR).PHP_EOL;
try{
    while($line=fgets(STDIN)){
        try{
            $command=json_decode($line,true,512,JSON_THROW_ON_ERROR);
            if($command['op']==='quit')break;
            $size=$command['size'];$variant=$command['variant'];$verb=$command['verb'];
            $rows=[];
            for($i=1;$i<=$size;$i++){
                $row=comparisonDocument($i,3);unset($row['embedding']);
                if($variant==='integer_values')$row['price']=$i;
                elseif($variant==='float_integer_values')$row['price']=(float)$i;
                elseif($variant==='scalar')unset($row['details'],$row['tags']);
                $rows[]=$row;
            }
            if($verb==='insert')$control->exec('TRUNCATE TABLE '.$table);
            WriteProfileClock::$enabled=false;
            if($client==='v4')$connection->getTableSchema($table);
            if($command['op']==='capture_numeric'){
                $connection->table($table)->replace($rows);
                [$sql,$bindings]=WriteProfileConnection::$lastInput;
                echo json_encode(['ok'=>true,'sql'=>$sql,'bindings'=>$bindings],JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION).PHP_EOL;continue;
            }
            if($client==='v4')WriteProfileConnection::$normalizer=($command['prototype']??false)?[WriteNumericPrototype::class,'prepare']:null;
            WriteProfileClock::$stats=[];WriteProfileClock::$enabled=$command['profile']??true;
            $start=hrtime(true);
            $value=$client==='v3'?WriteProfileClock::measure('builder.'.$verb,fn()=>$connection->table($table)->{$verb}($rows))
                :$connection->table($table)->{$verb}($rows);
            $elapsed=(hrtime(true)-$start)/1e6;WriteProfileClock::$enabled=false;
            if(!$value)throw new RuntimeException('Write failed');
            $snapshot=$control->query("SELECT id,title,brand,price,category,details,tags FROM $table ORDER BY id ASC LIMIT $size")->fetchAll(PDO::FETCH_ASSOC);
            if(count($snapshot)!==$size)throw new RuntimeException('Wrong document count');
            foreach($snapshot as &$row){$row['price']=round((float)$row['price'],6);$row['id']=(int)$row['id'];$row['category']=(int)$row['category'];}unset($row);
            $stats=WriteProfileClock::$stats;
            foreach($stats as &$stage){$stage['inclusive_ms']=$stage['inclusive_ns']/1e6;$stage['exclusive_ms']=$stage['exclusive_ns']/1e6;unset($stage['inclusive_ns'],$stage['exclusive_ns']);}unset($stage);
            echo json_encode(['ok'=>true,'total_ms'=>$elapsed,'stages'=>$stats,'digest'=>hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR))],JSON_THROW_ON_ERROR).PHP_EOL;
        }catch(Throwable $error){WriteProfileClock::$enabled=false;echo json_encode(['ok'=>false,'error'=>$error->getMessage(),'exception'=>get_class($error)],JSON_THROW_ON_ERROR).PHP_EOL;}
    }
}finally{$control->exec('DROP TABLE IF EXISTS '.$table);}
