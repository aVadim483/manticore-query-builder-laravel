<?php

declare(strict_types=1);

// Optional, destructive only to its randomly named scratch table; never part of PHPUnit discovery.
require $argv[1] ?? dirname(__DIR__, 2).'/vendor/autoload.php';

use avadim\Manticore\Laravel\Database\ManticoreBlueprint;
use avadim\Manticore\Laravel\ServiceProvider;
use Composer\InstalledVersions;
use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Events\Dispatcher;

$app = new Container();
$app->instance('config', new ArrayObject([
    'database.default'=>'benchmark',
    'database.connections'=>['benchmark'=>[
        'driver'=>'manticore', 'host'=>getenv('MANTICORE_TEST_HOST') ?: '127.0.0.1',
        'port'=>(int)(getenv('MANTICORE_TEST_PORT') ?: 9306), 'timeout'=>10,
    ]],
]));
$app->singleton('db', static fn ($app)=>new DatabaseManager($app, new ConnectionFactory($app)));
(new ServiceProvider($app))->register();
$manager=$app->make('db');
$db=$manager->connection();
$dimensions=(int)(getenv('MANTICORE_BENCHMARK_DIMS') ?: 128);
if (!in_array($dimensions,[3,128,768],true)) throw new InvalidArgumentException('Benchmark dimensions must be 3, 128 or 768.');
$queries=0;
$dispatcher=new Dispatcher();
$dispatcher->listen(Illuminate\Database\Events\QueryExecuted::class, static function () use (&$queries) {$queries++;});
$db->setEventDispatcher($dispatcher);
$name='benchmark_'.bin2hex(random_bytes(8));
$schema=$db->getSchemaBuilder();
$assert=static function ($condition, string $message):void {if (!$condition) throw new RuntimeException($message);};
$measure=static function (callable $work) use (&$queries):array {
    gc_collect_cycles();
    if (function_exists('memory_reset_peak_usage')) memory_reset_peak_usage();
    $memory=memory_get_usage(); $queryStart=$queries; $start=hrtime(true);
    $result=$work();
    $elapsed=(hrtime(true)-$start)/1e6;
    $peak=max(0,memory_get_peak_usage()-$memory);
    unset($result); gc_collect_cycles();
    return ['ms'=>round($elapsed,3),'queries'=>$queries-$queryStart,'peak_growth_bytes'=>$peak,'retained_growth_bytes'=>memory_get_usage()-$memory];
};
$document=static fn (int $id):array=>[
    'id'=>$id,'title'=>'Galaxy phone '.$id,'price'=>$id,'category'=>$id%5,
    'details'=>['tags'=>['sale','new'],'payload'=>str_repeat('x',128)],
    'embedding'=>array_map(static fn ($dimension)=>(float)(($id+$dimension*7)%97)/97,range(0,$dimensions-1)),
];
$report=['generated_at'=>date(DATE_ATOM),'php'=>PHP_VERSION,
    'package'=>InstalledVersions::getPrettyVersion('avadim/manticore-query-builder-laravel'),
    'server'=>$db->selectOne('SELECT VERSION() AS version')->version,'cases'=>[]];
try {
    $schema->create($name,static function (ManticoreBlueprint $table) use ($dimensions) {
        $table->text('title'); $table->integer('price'); $table->integer('category');
        $table->json('details'); $table->floatVector('embedding',$dimensions,'l2');
    });
    $report['cases']['insert_5000']=$measure(function () use ($db,$name,$document) {
        foreach (array_chunk(range(1,5000),500) as $ids) $db->table($name)->insert(array_map($document,$ids));
    });
    foreach ([100,1000,5000] as $size) {
        $rows=array_map(static fn ($id)=>['id'=>$id,'price'=>10000+$id],range(1,$size));
        $report['cases']['upsert_existing_'.$size]=$measure(function () use ($db,$name,$rows,$size,$assert) {
            $assert($db->table($name)->upsert($rows,['id'],['price'])===$size,'Upsert row count mismatch');
        });
        unset($rows);
    }
    $report['cases']['upsert_mixed_1000']=$measure(function () use ($db,$name,$document,$assert) {
        $rows=array_map($document,range(4501,5500));
        $assert($db->table($name)->upsert($rows,['id'],['price'])===1000,'Mixed upsert mismatch');
    });
    $assert($db->table($name)->find(1)->title==='Galaxy phone 1','Upsert lost stored TEXT');
    $assert($db->table($name)->find(1)->details['tags']===['sale','new'],'Upsert lost JSON');
    $db->statement('FLUSH RAMCHUNK '.$name);
    $vector=array_fill(0,$dimensions,0.25);
    $count=$db->table($name)->count(); $assert($count===5500,'Unexpected dataset count');
    $expectedSum=(int)($count*($count+1)/2);
    foreach (['get','cursor','lazy','chunkById'] as $method) {
        $report['cases'][$method.'_5500']=$measure(function () use ($db,$name,$method,$count,$expectedSum,$assert) {
            $query=$db->table($name)->orderBy('id')->option('max_matches',6000);
            $seen=0; $sum=0;
            $consume=static function ($row) use (&$seen,&$sum) {$seen++; $sum+=(int)$row->id;};
            if ($method==='chunkById') {
                $query->chunkById(250,static function ($rows) use ($consume) {foreach ($rows as $row) $consume($row);});
            } else {
                $rows=$method==='lazy' ? $query->lazy(250) : $query->limit($count)->{$method}();
                foreach ($rows as $row) $consume($row);
            }
            $assert($seen===$count && $sum===$expectedSum,$method.' lost or duplicated rows');
        });
    }
    foreach ([50,200,1000] as $window) {
        $samples=[];
        for ($i=0;$i<10;$i++) {
            $samples[]=$measure(function () use ($db,$name,$window,$vector,$assert) {
                $rows=$db->table($name)->where('category',1)->hybridGet('Galaxy','embedding',$vector,[
                    'text_candidates'=>$window,'vector_candidates'=>$window,
                ]);
                $assert($rows->isNotEmpty() && $rows->every(static fn ($row)=>$row->category===1),'Hybrid filter lost');
            });
        }
        $times=array_column($samples,'ms'); sort($times);
        $report['cases']['hybrid_window_'.$window]=[
            'runs'=>count($samples),'median_ms'=>$times[4],'p95_ms'=>$times[9],
            'max_peak_growth_bytes'=>max(array_column($samples,'peak_growth_bytes')),
        ];
    }
    $work=static function (int $i) use ($db,$name,$vector,$assert) {
        if ($i%3===0) {
            $rows=$db->table($name)->whereJsonContains('details->tags','sale')->whereFullText('title','Galaxy')->limit(5)->get();
        } elseif ($i%3===1) {
            $rows=$db->table($name)->where('category',$i%5)->hybridGet('Galaxy','embedding',$vector,[
                'text_candidates'=>50,'vector_candidates'=>50,
            ]);
        } else {
            $rows=$db->table($name)->match('Galaxy')->facet('category','categories')->limit(5)->search()->rows();
        }
        $assert($rows->isNotEmpty(),'Soak returned no rows');
    };
    for ($i=0;$i<100;$i++) $work($i);
    $samples=[];
    gc_collect_cycles(); $startMemory=memory_get_usage(); $startAllocated=memory_get_usage(true);
    $report['cases']['soak_1000']=$measure(function () use ($work,&$samples) {
        for ($i=0;$i<1000;$i++) {
            $work($i);
            if (($i+1)%100===0) {gc_collect_cycles();$samples[]=['iteration'=>$i+1,'used_bytes'=>memory_get_usage(),'allocated_bytes'=>memory_get_usage(true)];}
        }
    });
    gc_collect_cycles();
    $usedGrowth=memory_get_usage()-$startMemory; $allocatedGrowth=memory_get_usage(true)-$startAllocated;
    $report['soak']=['warmup'=>100,'iterations'=>1000,'used_growth_bytes'=>$usedGrowth,'allocated_growth_bytes'=>$allocatedGrowth,'samples'=>$samples];
    $assert($usedGrowth<8*1024*1024 && $allocatedGrowth<16*1024*1024,'Soak exceeded retained-memory growth budget');
    $assert(!$db->logging() && count($db->getQueryLog())===0,'Query log was unexpectedly retained');
    $report['dataset']=['documents'=>$count,'json_payload_bytes'=>128,'dimensions'=>$dimensions,'index_flushed'=>true];
    $report['query_log_retained']=false;
    $report['ok']=true;
} catch (Throwable $error) {
    $report['ok']=false; $report['error']=$error->getMessage();
} finally {
    $schema->dropIfExists($name);
}
$json=json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
if ($path=getenv('MANTICORE_BENCHMARK_REPORT')) file_put_contents($path,$json);
echo $json;
exit($report['ok']?0:1);
