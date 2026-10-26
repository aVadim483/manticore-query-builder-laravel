<?php
declare(strict_types=1);

[$script,$app,$source,$mode]=$argv;
require $app.'/vendor/autoload.php';
spl_autoload_register(static function(string $class)use($source):void {
    $prefix='avadim\\Manticore\\Laravel\\';
    if(str_starts_with($class,$prefix)) {
        $file=$source.'/src/Manticore/Laravel/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
        if(is_file($file))require $file;
    }
},true,true);
use avadim\Manticore\Laravel\Database\AttributeCodec;
use avadim\Manticore\Laravel\Database\ManticoreConnection;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Query\Expression;

enum EncodingPlanInt:int {case Value=7;}
enum EncodingPlanUnit {case Ready;}
$db=new ManticoreConnection(static fn()=>throw new LogicException('Unexpected PDO access'));
$grammar=$db->getQueryGrammar();
$resource=fopen('php://memory','r+');
$values=[null,false,true,0,1,-1,PHP_INT_MAX,55.3,-0.0,1e-7,INF,NAN,'','7.25','{"x":1}','bad json',
    '9007199254740993',[],[1,2],['9007199254740993','-1'],[1.25,-0.0],[NAN],[INF],['1.25'],
    ['x'=>1],(object)['n'=>7.25],new DateTimeImmutable('2026-10-26T10:00:00Z'),
    EncodingPlanInt::Value,EncodingPlanUnit::Ready,new Expression("'? :key'"),$resource,
    new class implements JsonSerializable {public function jsonSerialize():mixed {return ['n'=>55.3];}},
    chr(255),['text'=>chr(255)]];
$types=['json','timestamp','float_vector','multi','multi64','mva','mva64','integer','bigint','float','bool','string','other'];
$encoders=[];
foreach($types as $type) {
    $encoders[$type]=match($mode) {
        'closures'=>AttributeCodec::encoder($type),
        'reference'=>static fn($value)=>AttributeCodec::encode($type,$value),
        'kinds'=>AttributeCodec::encodingKind($type),
        default=>throw new InvalidArgumentException('Unknown mode: '.$mode),
    };
}
function outcome(Closure $call):array {
    global $grammar;
    try {
        $value=$call();
        if($value instanceof ExpressionContract)$value=['expression'=>get_class($value),'value'=>$value->getValue($grammar)];
        return ['ok'=>true,'serialized'=>serialize($value)];
    } catch(Throwable $error) {return ['ok'=>false,'class'=>get_class($error),'message'=>$error->getMessage(),'code'=>$error->getCode()];}
}
$count=0;$errors=0;$digest=hash_init('sha256');
mt_srand(261026);
foreach(range(0,5999) as $index) {
    $type=$index<count($types)*count($values)?$types[intdiv($index,count($values))]:$types[mt_rand(0,count($types)-1)];
    $value=$values[$index<count($types)*count($values)?$index%count($values):mt_rand(0,count($values)-1)];
    $expected=outcome(static fn()=>AttributeCodec::encode($type,$value));
    $encoder=$encoders[$type];
    $actual=outcome(static fn()=>$mode==='kinds'?AttributeCodec::encodeKind($encoder,$value):$encoder($value));
    if($expected!==$actual)throw new RuntimeException('Codec mismatch for '.$type.' / '.get_debug_type($value));
    hash_update($digest,serialize([$type,$actual]));
    $count++;if(!$expected['ok'])$errors++;
}
fclose($resource);
echo json_encode(['ok'=>true,'mode'=>$mode,'cases'=>$count,'matching_errors'=>$errors,
    'outcomes_sha256'=>hash_final($digest),
    'codec_source_sha256'=>hash_file('sha256',(new ReflectionClass(AttributeCodec::class))->getFileName())],JSON_THROW_ON_ERROR).PHP_EOL;
