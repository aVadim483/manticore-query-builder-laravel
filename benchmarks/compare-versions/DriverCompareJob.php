<?php
namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DriverCompareJob implements ShouldQueue
{
    use Queueable;
    public function __construct(public string $token,public int $number) {}
    public function handle():void
    {
        require_once base_path('verification/ab/adapter.php');
        static $driver;
        $config=json_decode(file_get_contents(base_path('verification/ab/config.json')),true,512,JSON_THROW_ON_ERROR);
        $driver??=new \DriverComparison($config['client'],array_replace($config,['count_sql'=>true]));
        gc_collect_cycles();memory_reset_peak_usage();\ComparisonStatement::$executions=0;
        $start=hrtime(true);$hash=hash_init('sha256');
        for($i=0;$i<5;$i++)foreach(['match_meta20','json_mva20','knn20'] as $scenario) {
            $value=$driver->normalize($scenario,$driver->run($scenario));
            hash_update($hash,json_encode($value,JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
            unset($value);
        }
        $ms=(hrtime(true)-$start)/1e6;$peak=memory_get_peak_usage();gc_collect_cycles();
        $result=['number'=>$this->number,'ms'=>$ms,'queries'=>\ComparisonStatement::$executions,
            'peak_bytes'=>$peak,'retained_bytes'=>memory_get_usage(),'digest'=>hash_final($hash),'pid'=>getmypid()];
        file_put_contents(storage_path('app/ab-queue-'.$this->token.'.jsonl'),json_encode($result,JSON_THROW_ON_ERROR).PHP_EOL,FILE_APPEND|LOCK_EX);
    }
}
