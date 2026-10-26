<?php
declare(strict_types=1);

function comparisonDocument(int $id,int $dimensions=128):array
{
    $state=$id; $vector=[];
    for($i=0;$i<$dimensions;$i++) {
        $state=($state*1664525+1013904223)%4294967296;
        $vector[]=round($state/4294967296,7);
    }
    return ['id'=>$id,'title'=>($id%2?'Galaxy phone ':'Orbit laptop ').$id,
        'brand'=>['Acme','Nova','Orbit'][$id%3],'price'=>round(($id%1000)/10,1),'category'=>$id%5,
        'details'=>['color'=>$id%2?'red':'blue','payload'=>str_repeat('x',128)],
        'tags'=>[1+($id%4),10+($id%3)],'embedding'=>$vector];
}

function comparisonWriteDocuments():array
{
    $rows=[];
    for($id=1;$id<=500;$id++) {
        $row=comparisonDocument($id,3);unset($row['embedding']);$rows[]=$row;
    }
    return $rows;
}
