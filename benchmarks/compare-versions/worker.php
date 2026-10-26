<?php
declare(strict_types=1);

[$script,$root,$client,$configPath]=$argv;
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/adapter.php';
require __DIR__.'/fixtures.php';
$config=json_decode(file_get_contents($configPath),true,512,JSON_THROW_ON_ERROR);
$driver=new DriverComparison($client,$config);
$GLOBALS['comparison_write_rows']=comparisonWriteDocuments();
echo json_encode(['ready'=>true,'client'=>$client,'package'=>Composer\InstalledVersions::getPrettyVersion('avadim/manticore-query-builder-laravel'),
    'php'=>PHP_VERSION,'laravel'=>$app->version(),'xdebug_loaded'=>extension_loaded('xdebug'),'opcache_cli'=>ini_get('opcache.enable_cli'),
    'pdo'=>['emulated_prepares'=>$driver->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES),'buffered'=>$driver->pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY)]],JSON_THROW_ON_ERROR).PHP_EOL;
while($line=fgets(STDIN)) {
    try {
        $command=json_decode($line,true,512,JSON_THROW_ON_ERROR);
        if($command['op']==='quit')break;
        if($command['op']==='enqueue') {
            for($i=0;$i<$command['jobs'];$i++) Illuminate\Support\Facades\Queue::connection('database')->push(
                new App\Jobs\DriverCompareJob($command['token'],$i),'',$command['queue']);
            echo "{\"ok\":true}".PHP_EOL;continue;
        }
        if($command['op']==='sql') {
            $result=$driver->raw($command['sql'],$command['values']??[]);
            echo json_encode(['ok'=>true,'rows'=>$result],JSON_THROW_ON_ERROR).PHP_EOL;continue;
        }
        if($command['op']==='setup') {
            $table=$config['read_table'];
            if(!preg_match('/^abcmp_[a-f0-9]+_read$/D',$table))throw new RuntimeException('Unexpected scratch table');
            $driver->raw("CREATE TABLE $table (title text, brand string attribute, price float, category integer, details json, tags multi, embedding float_vector KNN_TYPE='hnsw' KNN_DIMS='128' HNSW_SIMILARITY='l2') min_infix_len='2' index_exact_words='1'");
            foreach($config['write_tables'] as $target) {
                if(!preg_match('/^abcmp_[a-f0-9]+_write_(?:v3|v4|pdo)$/D',$target))throw new RuntimeException('Unexpected write table');
                $driver->raw("CREATE TABLE $target (title text, brand string attribute, price float, category integer, details json, tags multi)");
            }
            // Use the same tested PDO writer for seeding; no client under comparison gets seed time.
            $old=$driver->config['write_tables']['pdo'];
            $driver->config['write_tables']['pdo']=$table;
            for($start=1;$start<=$config['documents'];$start+=250) {
                $GLOBALS['comparison_write_rows']=array_map('comparisonDocument',range($start,min($config['documents'],$start+249)));
                $driver->run('write_insert500');
            }
            $driver->config['write_tables']['pdo']=$old;
            $GLOBALS['comparison_write_rows']=comparisonWriteDocuments();
            $driver->raw('FLUSH RAMCHUNK '.$table);
            echo json_encode(['ok'=>true,'documents'=>$driver->raw("SELECT COUNT(*) AS n FROM $table")[0]->n],JSON_THROW_ON_ERROR).PHP_EOL;continue;
        }
        if($command['op']==='reset_write') {
            $table=$config['write_tables'][$command['client']];
            $driver->raw('TRUNCATE TABLE '.$table);
            if($command['scenario']!=='write_insert500') {
                $old=$driver->config['write_tables']['pdo'];$driver->config['write_tables']['pdo']=$table;
                $driver->run('write_insert500');$driver->config['write_tables']['pdo']=$old;
            }
            echo "{\"ok\":true}".PHP_EOL;continue;
        }
        $scenario=$command['scenario'];
        gc_collect_cycles(); memory_reset_peak_usage();
        $baseline=memory_get_usage(); ComparisonStatement::$executions=0;
        ComparisonStatement::$capture=$command['capture']??false; ComparisonStatement::$sql=[];
        $start=hrtime(true); $result=$driver->run($scenario); $api=(hrtime(true)-$start)/1e6;
        $apiPeak=memory_get_peak_usage(); $sqlCount=ComparisonStatement::$executions; $sql=ComparisonStatement::$sql;
        ComparisonStatement::$capture=false;
        $start=hrtime(true);$canonical=$driver->normalize($scenario,$result);
        $digest=hash('sha256',json_encode($canonical,JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
        $dto=(hrtime(true)-$start)/1e6;$peak=memory_get_peak_usage();
        $sample=array_slice(is_array($canonical)?($canonical['rows']??$canonical):[],0,2);
        unset($result,$canonical);gc_collect_cycles();
        echo json_encode(['ok'=>true,'scenario'=>$scenario,'api_ms'=>$api,'dto_ms'=>$dto,'queries'=>$sqlCount,
            'api_peak_bytes'=>$apiPeak,'api_peak_growth_bytes'=>max(0,$apiPeak-$baseline),'peak_bytes'=>$peak,
            'retained_bytes'=>memory_get_usage(),'digest'=>$digest,'sql'=>$sql,'sample'=>$sample],JSON_THROW_ON_ERROR).PHP_EOL;
    } catch(Throwable $error) {
        echo json_encode(['ok'=>false,'error'=>$error->getMessage(),'exception'=>get_class($error)],JSON_THROW_ON_ERROR).PHP_EOL;
    }
}
