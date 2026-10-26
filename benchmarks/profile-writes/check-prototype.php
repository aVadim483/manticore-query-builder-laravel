<?php
declare(strict_types=1);
require $argv[1] ?? dirname(__DIR__,2).'/vendor/autoload.php';
require __DIR__.'/prototype.php';
use avadim\Manticore\Laravel\Database\NumericBindings;

$cases=[
    ['SELECT ?, ?, ?', [1.25,42,'text']],
    ["SELECT :x,:x,:text",[':x'=>1.5,':text'=>"O'Reilly"]],
    ["SELECT '?', \"?\", ".chr(96).'?'.chr(96)." /* ? */ , ? -- ?\n, ?",[1.5,2]],
    ["SELECT 'unterminated ?\\",[1.5]],
    ['SELECT /* unterminated ? :x',[1.5]],
    ['SELECT ?::float, foo:bar, :x, :y',['x'=>1.5,':y'=>2.0]],
    ['SELECT :x, :x, ?',['x'=>1.5,':x'=>2.5,0=>3.5]],
    ["SELECT '".str_repeat('x',200000)." ?' , ?",[1.5]],
];
mt_srand(20261026);
$tokens=[
    '?',' :n0 ',' :n1 ',' :n2 ',' :unknown ',' ::n0 ',' word:n0 ',
    "'? :n0'","'it\\'s ?'","'doubled '' ?'","\"doubled \"\" ?\"",
    chr(96).'? :n0'.chr(96),'/* ? :n0 */',"-- ? :n1\n","# ? :n2\n",
    "\n",',',' + ','123','price','\\',"'\0?'",
];
for($i=0;$i<10000;$i++){
    $sql='SELECT ';
    for($j=0;$j<20;$j++)$sql.=$tokens[mt_rand(0,count($tokens)-1)];
    if($i%7===0)$sql.="'unterminated ?";
    $bindings=$i%2===0?[1.5,2,'3.5',0.0,42,-2.25]:['n0'=>1.5,':n1'=>0.0,'n2'=>42,0=>2.5,1=>'text'];
    $cases[]=[$sql,$bindings];
}
foreach([INF,-INF,NAN]as$value)$cases[]=['SELECT ?',[1,$value]];
foreach($cases as$i=>[$sql,$bindings]){
    $observed=[];
    foreach([NumericBindings::class,WriteNumericPrototype::class]as$class){
        try{$observed[]=['result'=>$class::prepare($sql,$bindings)];}
        catch(Throwable$error){$observed[]=['exception'=>get_class($error),'message'=>$error->getMessage()];}
    }
    if($observed[0]!==$observed[1])throw new RuntimeException('Mismatch in case '.$i.': '.json_encode([$sql,$bindings,$observed],JSON_PARTIAL_OUTPUT_ON_ERROR));
}
echo 'OK: '.count($cases).' differential cases'.PHP_EOL;
