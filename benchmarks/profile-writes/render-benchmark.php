<?php
declare(strict_types=1);
require $argv[1];
require __DIR__.'/prototype.php';
$input=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
$sql=$input['sql'];$bindings=$input['bindings'];$samples=['released'=>[],'prototype'=>[]];
$functions=['released'=>[avadim\Manticore\Laravel\Database\NumericBindings::class,'prepare'],
    'prototype'=>[WriteNumericPrototype::class,'prepare']];
for($i=0;$i<103;$i++){
    foreach($i%2===0?['released','prototype']:['prototype','released']as$name){
        $start=hrtime(true);$out=$functions[$name]($sql,$bindings);$ms=(hrtime(true)-$start)/1e6;
        if($name==='released')$expected=$out;
        elseif($out!==$expected)throw new RuntimeException('Renderer output differs');
        if($i>=3)$samples[$name][]=$ms;
    }
}
$summary=[];
foreach($samples as$name=>$times){sort($times);$summary[$name]=['median_ms'=>($times[49]+$times[50])/2,'p95_ms'=>$times[94]];}
$report=['ok'=>true,'php'=>PHP_VERSION,'sql_bytes'=>strlen($sql),'bindings'=>count($bindings),
    'floats'=>count(array_filter($bindings,'is_float')),'remaining_bindings'=>count($out[1]),
    'summary'=>$summary,'samples'=>$samples];
echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
