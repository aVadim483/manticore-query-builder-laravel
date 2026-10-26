<?php
declare(strict_types=1);

require $argv[1] ?? dirname(__DIR__,2).'/vendor/autoload.php';
use avadim\Manticore\Laravel\Database\NumericBindings;

$port=(int)($argv[2]??9306);$path=$argv[3]??null;
$pdo=new PDO('mysql:host=127.0.0.1;port='.$port,null,null,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>true,
    PDO::ATTR_STRINGIFY_FETCHES=>false,PDO::ATTR_TIMEOUT=>5,
]);
$table='float_binding_'.bin2hex(random_bytes(6));
$report=['php'=>PHP_VERSION,'server'=>$pdo->query('SELECT VERSION() AS v')->fetchColumn(),
    'precision'=>ini_get('precision'),'serialize_precision'=>ini_get('serialize_precision'),'cases'=>[]];
$execute=static function(string $sql,array $bindings,string $mode)use($pdo):array{
    if($mode==='literal')[$sql,$bindings]=NumericBindings::prepare($sql,$bindings);
    else foreach($bindings as &$value)if(is_float($value)){
        $value=$mode==='php_string'?(string)$value:json_encode($value,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);
    }unset($value);
    $statement=$pdo->prepare($sql);
    foreach($bindings as $key=>$value)$statement->bindValue(is_int($key)?$key+1:$key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
    $statement->execute();$rows=[];$columns=[];$affected=$statement->rowCount();
    if($statement->columnCount()){
        $rows=$statement->fetchAll(PDO::FETCH_ASSOC);
        for($i=0;$i<$statement->columnCount();$i++){
            $meta=$statement->getColumnMeta($i);
            $columns[]=['name'=>$meta['name'],'native_type'=>$meta['native_type']];
        }
    }
    while($statement->nextRowset()){}$statement->closeCursor();
    return ['rows'=>$rows,'columns'=>$columns,'affected_rows'=>$affected];
};
$cases=[];
foreach([55.3,-55.3,0.0,-0.0,1e-7,1e-20,1.25e6,1e20]as$number){
    foreach(['INSERT','REPLACE','UPDATE']as$verb){
        $cases[]=['name'=>strtolower($verb).'_'.json_encode($number,JSON_PRESERVE_ZERO_FRACTION),
            'group'=>strtolower($verb),'number'=>$number,
            'sql'=>$verb==='UPDATE'?"UPDATE $table SET price=? WHERE id=100":"$verb INTO $table (id,price,title) VALUES (100,?,'Phone')",
            'cast_sql'=>$verb==='UPDATE'?"UPDATE $table SET price=DOUBLE(?) WHERE id=100":"$verb INTO $table (id,price,title) VALUES (100,DOUBLE(?),'Phone')",
            'bindings'=>[$number]];
    }
}
$reads=[
    ['where_eq','where',"SELECT id FROM $table WHERE price=? ORDER BY id ASC",[55.3],"SELECT id FROM $table WHERE price=DOUBLE(?) ORDER BY id ASC"],
    ['where_gt','where',"SELECT id FROM $table WHERE price>? ORDER BY id ASC",[55.3],"SELECT id FROM $table WHERE price>DOUBLE(?) ORDER BY id ASC"],
    ['where_negative','where',"SELECT id FROM $table WHERE price>? ORDER BY id ASC",[-1.25],null],
    ['where_exp','where',"SELECT id FROM $table WHERE price>? ORDER BY id ASC",[1e-7],null],
    ['where_between','where',"SELECT id FROM $table WHERE price BETWEEN ? AND ? ORDER BY id ASC",[1.25,55.4],"SELECT id FROM $table WHERE price BETWEEN DOUBLE(?) AND DOUBLE(?) ORDER BY id ASC"],
    ['where_in','where',"SELECT id FROM $table WHERE price IN (?,?) ORDER BY id ASC",[1.25,55.3],null],
    ['json_eq','json',"SELECT id FROM $table WHERE details.rating=? ORDER BY id ASC",[1.25],"SELECT id FROM $table WHERE details.rating=DOUBLE(?) ORDER BY id ASC"],
    ['json_gt','json',"SELECT id FROM $table WHERE details.rating>? ORDER BY id ASC",[1.25],"SELECT id FROM $table WHERE details.rating>DOUBLE(?) ORDER BY id ASC"],
    ['json_double','json',"SELECT id,DOUBLE(details.rating) AS n FROM $table WHERE DOUBLE(details.rating)>? ORDER BY id ASC",[1.25],null],
    ['select_parameter','select',"SELECT ? AS n FROM $table WHERE id=1",[55.3],"SELECT DOUBLE(?) AS n FROM $table WHERE id=1"],
    ['select_double','select',"SELECT DOUBLE(?) AS n FROM $table WHERE id=1",[0.12345678901234566],null],
    ['add','arithmetic',"SELECT id,price+? AS n FROM $table ORDER BY id ASC",[0.125],"SELECT id,price+DOUBLE(?) AS n FROM $table ORDER BY id ASC"],
    ['multiply','arithmetic',"SELECT id,price*? AS n FROM $table ORDER BY id ASC",[1.25],"SELECT id,price*DOUBLE(?) AS n FROM $table ORDER BY id ASC"],
    ['divide','arithmetic',"SELECT id,price/? AS n FROM $table ORDER BY id ASC",[1.25],null],
    ['abs','function',"SELECT ABS(?) AS n FROM $table WHERE id=1",[-55.3],"SELECT ABS(DOUBLE(?)) AS n FROM $table WHERE id=1"],
    ['pow','function',"SELECT id,POW(price,?) AS n FROM $table ORDER BY id ASC",[2.5],"SELECT id,POW(price,DOUBLE(?)) AS n FROM $table ORDER BY id ASC"],
    ['if_compare','function',"SELECT id,IF(price>?,1,0) AS n FROM $table ORDER BY id ASC",[55.3],null],
    ['geodist','geo',"SELECT id,GEODIST(?,?,lat,lon,{in=deg,out=km}) AS d FROM $table ORDER BY id ASC",[55.76,37.62],
        "SELECT id,GEODIST(DOUBLE(?),DOUBLE(?),lat,lon,{in=deg,out=km}) AS d FROM $table ORDER BY id ASC"],
    ['sum_expression','aggregate',"SELECT SUM(price+?) AS n FROM $table",[0.125],null],
    ['having','aggregate',"SELECT category,SUM(price) AS n FROM $table GROUP BY category HAVING n>? ORDER BY category ASC",[55.3],null],
    ['json_update','json_update',"UPDATE $table SET details.rating=? WHERE id=100",[1.25],null],
    ['insert_vector_tuple','vector_insert',"INSERT INTO $table (id,title,price,embedding) VALUES (100,'Vector',55.3,(?,?,?))",[1.1,0.2,0.3],null],
    ['replace_vector_tuple','vector_replace',"REPLACE INTO $table (id,title,price,embedding) VALUES (100,'Vector',55.3,(?,?,?))",[1.1,0.2,0.3],null],
    ['knn_tuple','knn',"SELECT id,KNN_DIST() AS d FROM $table WHERE KNN(embedding,3,(?,?,?)) ORDER BY d ASC,id ASC LIMIT 3",[1.1,0.2,0.3],null],
];
foreach($reads as[$name,$group,$sql,$bindings,$cast])$cases[]=['name'=>$name,'group'=>$group,'sql'=>$sql,'bindings'=>$bindings,'cast_sql'=>$cast];
try{
    $pdo->exec("CREATE TABLE $table (title text,price float,category integer,details json,lat float,lon float,embedding float_vector KNN_TYPE='hnsw' KNN_DIMS='3' HNSW_SIMILARITY='l2')");
    foreach([1.25,2.5,55.3,55.4,100.25,-5.0,1e-7,0.0]as$i=>$value){
        $id=$i+1;$rating=$id%2?1.25:2.5;
        $pdo->exec("INSERT INTO $table (id,title,price,category,details,lat,lon,embedding) VALUES ($id,'Phone',".json_encode($value,JSON_PRESERVE_ZERO_FRACTION).",".($id%2).",'".json_encode(['rating'=>$rating])."',55.75,37.61,($id,0.2,0.3))");
    }
    $pdo->exec('FLUSH RAMCHUNK '.$table);
    foreach(['emulated'=>true,'native'=>false]as$protocol=>$emulated){
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,$emulated);
        foreach($cases as$case){
            if(!$emulated && (!in_array($case['group'],['insert','replace','update'],true)||!in_array($case['number']??null,[55.3,-55.3,1e-7],true)))continue;
            $record=['name'=>$case['name'],'group'=>$case['group'],'protocol'=>$protocol,'sql'=>$case['sql'],'bindings'=>$case['bindings'],'modes'=>[]];
            foreach(['literal','json_string','php_string','double_cast']as$mode){
                if($mode==='double_cast'&&!$case['cast_sql'])continue;
                $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,true);
                $pdo->exec("DELETE FROM $table WHERE id=100");
                if(in_array($case['group'],['replace','update','json_update'],true))$pdo->exec("INSERT INTO $table (id,title,price,details) VALUES (100,'Old',1.0,'{\"rating\":0.0}')");
                $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,$emulated);
                try{
                    $out=$execute($mode==='double_cast'?$case['cast_sql']:$case['sql'],$case['bindings'],$mode==='double_cast'?'json_string':$mode);
                    if(in_array($case['group'],['insert','replace','update','json_update','vector_insert','vector_replace'],true)){
                        $out['warnings']=$pdo->query('SHOW WARNINGS')->fetchAll(PDO::FETCH_ASSOC);
                        $fields=str_starts_with($case['group'],'vector_')?'id,title,price,details,embedding':'id,title,price,details';
                        $out['rows']=$pdo->query("SELECT $fields FROM $table WHERE id=100")->fetchAll(PDO::FETCH_ASSOC);
                    }
                    $record['modes'][$mode]=['ok'=>true,'output'=>$out];
                }catch(Throwable$error){
                    $record['modes'][$mode]=['ok'=>false,'exception'=>get_class($error),'error'=>$error->getMessage()];
                }
            }
            $baseline=$record['modes']['literal'];
            foreach($record['modes']as$mode=>&$result){
                $result['same_as_literal']=$baseline['ok']&&$result['ok']&&$baseline['output']===$result['output'];
                if($baseline['ok']&&$result['ok']){
                    $result['same_rows']=$baseline['output']['rows']===$result['output']['rows'];
                    $result['same_column_types']=$baseline['output']['columns']===$result['output']['columns'];
                }
            }unset($result);
            $report['cases'][]=$record;
        }
    }
    $report['ok']=true;
}finally{
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,true);
    $pdo->exec('DROP TABLE IF EXISTS '.$table);
}
$json=json_encode($report,JSON_PRETTY_PRINT|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR).PHP_EOL;
if($path)file_put_contents($path,$json);
echo json_encode(['server'=>$report['server'],'cases'=>count($report['cases']),'ok'=>$report['ok']],JSON_THROW_ON_ERROR).PHP_EOL;
