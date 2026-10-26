<?php
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use App\Jobs\DriverSoakProbe;

$root=$argv[1] ?? getcwd();
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$token=bin2hex(random_bytes(6)); $name='worker_soak_'.$token; $queue='driver-soak-'.$token;
$log=storage_path('logs/worker-soak-'.$token.'.log');
$assert=static function ($condition,$message) {if (!$condition) throw new RuntimeException($message);};
$catalog=$app->make(App\Services\Catalog::class); $before=$catalog->fingerprint();
$db=DB::connection('manticore');
try {
    Schema::connection('manticore')->create($name,function ($table) {
        $table->text('title'); $table->integer('price'); $table->integer('category');
        $table->json('details'); $table->floatVector('embedding',3,'l2');
    });
    foreach (array_chunk(range(1,1000),250) as $ids) $db->table($name)->insert(array_map(static fn ($id)=>[
        'id'=>$id,'title'=>'Galaxy phone '.$id,'price'=>$id,'category'=>$id%5,
        'details'=>['tags'=>['sale','new']],'embedding'=>[(float)($id%7),1.0,1.0],
    ],$ids));
    $db->statement('FLUSH RAMCHUNK '.$db->getTablePrefix().$name);
    for ($i=1;$i<=120;$i++) Queue::connection('database')->push(new DriverSoakProbe($name,$token,$i),'',$queue);
    $command=[base_path('bin/php'),'artisan','queue:work','database','--queue='.$queue,'--stop-when-empty','--max-jobs=120','--sleep=0','--tries=1','--timeout=60','--memory=512'];
    $process=proc_open($command,[0=>['file','/dev/null','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,base_path());
    $assert(is_resource($process),'Cannot launch queue worker');
    $exit=proc_close($process);
    $assert($exit===0,'Queue worker exited with failure; see '.$log);
    $samples=array_map(static fn ($line)=>json_decode($line,true,512,JSON_THROW_ON_ERROR),file(storage_path('app/worker-soak-'.$token.'.jsonl'),FILE_IGNORE_NEW_LINES));
    $assert(count($samples)===120,'Not all jobs completed; see '.$log);
    $assert(count(array_unique(array_column($samples,'pid')))===1,'Worker unexpectedly restarted');
    $growth=end($samples)['used_bytes']-$samples[19]['used_bytes'];
    $allocated=end($samples)['allocated_bytes']-$samples[19]['allocated_bytes'];
    $assert($growth<8*1024*1024 && $allocated<16*1024*1024,'Worker retained-memory budget exceeded');
    $assert(DB::connection('sqlite')->table('jobs')->where('queue',$queue)->count()===0,'Queue was not drained');
    $assert($db->table($name)->find(1)->price===120,'Last worker write was not applied');
    $assert($catalog->fingerprint()===$before,'Application catalogue changed');
    $report=['ok'=>true,'php'=>PHP_VERSION,'jobs'=>120,'operations_per_job'=>15,'single_process'=>true,
        'warmup_jobs'=>20,'used_growth_bytes'=>$growth,'allocated_growth_bytes'=>$allocated,
        'first_after_warmup'=>$samples[19],'last'=>end($samples),'data_preserved'=>true,'samples'=>$samples];
    file_put_contents(storage_path('app/worker-soak-report.json'),json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    unset($report['samples']); echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    Schema::connection('manticore')->dropIfExists($name);
    DB::connection('sqlite')->table('jobs')->where('queue',$queue)->delete();
}
