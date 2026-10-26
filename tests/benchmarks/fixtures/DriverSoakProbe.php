<?php
namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class DriverSoakProbe implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $table, public string $token, public int $number) {}

    public function handle(): void
    {
        $db=DB::connection('manticore');
        for ($i=0;$i<15;$i++) {
            if ($i%3===0) {
                $rows=$db->table($this->table)->whereFullText('title','Galaxy')->whereJsonContains('details->tags','sale')->limit(5)->get();
            } elseif ($i%3===1) {
                $rows=$db->table($this->table)->where('category',1)->hybridGet('Galaxy','embedding',[1,1,1],[
                    'text_candidates'=>20,'vector_candidates'=>20,
                ]);
            } else {
                $db->table($this->table)->upsert([['id'=>1,'price'=>$this->number]],['id'],['price']);
                $rows=$db->table($this->table)->where('id',1)->get();
            }
            if ($rows->isEmpty()) throw new \RuntimeException('Worker query returned no rows');
            unset($rows);
        }
        if ($db->logging() || $db->getQueryLog()) throw new \RuntimeException('Worker retains query log');
        gc_collect_cycles();
        $report=['job'=>$this->number,'pid'=>getmypid(),'used_bytes'=>memory_get_usage(),'allocated_bytes'=>memory_get_usage(true)];
        file_put_contents(storage_path('app/worker-soak-'.$this->token.'.jsonl'),json_encode($report,JSON_THROW_ON_ERROR).PHP_EOL,FILE_APPEND|LOCK_EX);
    }
}
