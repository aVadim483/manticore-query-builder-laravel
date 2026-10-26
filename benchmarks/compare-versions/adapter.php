<?php

declare(strict_types=1);

/** Benchmark-only PDO instrumentation, identical for both drivers and the PDO control. */
class ComparisonStatement extends PDOStatement
{
    public static int $executions = 0;
    public static bool $capture = false;
    public static array $sql = [];
    protected function __construct() {}
    public static function record(string $sql):void
    {
        self::$executions++;
        if(self::$capture)self::$sql[]=$sql;
    }
    public function execute(?array $params = null): bool
    {
        self::record($this->queryString);
        return parent::execute($params);
    }
}

class ComparisonPDO extends PDO
{
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false
    {
        ComparisonStatement::record($query);
        return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$fetchModeArgs);
    }
    public function exec(string $statement):int|false
    {
        ComparisonStatement::record($statement);
        return parent::exec($statement);
    }
}

final class DriverComparison
{
    private mixed $connection;
    public PDO $pdo;
    private array $columns = ['id','title','brand','price','category'];

    public function __construct(public string $client, public array $config)
    {
        if ($client === 'v3') {
            $this->connection = app('manticore')->connection()->setLogger(false);
            // The old public API has no PDO accessor. Read the existing handle without replacing
            // the client, changing vendor code or changing its execution/fetch behavior.
            $property = new ReflectionProperty(avadim\Manticore\QueryBuilder\Connection::class, 'client');
            $backend = $property->getValue($this->connection);
            $handle = new ReflectionProperty($backend, 'dbh');
            $this->pdo = $handle->getValue($backend);
            $replace = static fn(PDO $pdo)=>$handle->setValue($backend,$pdo);
        } elseif ($client === 'v4') {
            $this->connection = Illuminate\Support\Facades\DB::connection('manticore');
            $this->connection->disableQueryLog();
            $this->connection->unsetEventDispatcher();
            $this->pdo = $this->connection->getPdo();
            $replace = fn(PDO $pdo)=>$this->connection->setPdo($pdo);
        } elseif ($client === 'pdo') {
            $this->pdo = new PDO('mysql:host=127.0.0.1;port=19306', null, null, [
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>true,
                PDO::ATTR_STRINGIFY_FETCHES=>false,
            ]);
        } else throw new InvalidArgumentException('Unknown benchmark client');
        if($config['count_sql']??true) {
            $options=[PDO::ATTR_TIMEOUT=>5];
            foreach([PDO::ATTR_ERRMODE,PDO::ATTR_EMULATE_PREPARES,PDO::ATTR_STRINGIFY_FETCHES,
                PDO::ATTR_DEFAULT_FETCH_MODE,PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] as $attribute) {
                $options[$attribute]=$this->pdo->getAttribute($attribute);
            }
            $this->pdo=new ComparisonPDO('mysql:host=127.0.0.1;port=19306',null,null,$options);
            $this->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[ComparisonStatement::class,[]]);
            if(isset($replace))$replace($this->pdo);
        }
    }

    public function query(?string $table = null): mixed
    {
        $query = $this->connection->table($table ?? $this->config['read_table']);
        return $query;
    }

    private function selected(array $columns): mixed
    {
        return $this->query()->select($this->client === 'v3' ? implode(', ', $columns) : $columns);
    }

    public function raw(string $sql, array $values = []): array
    {
        $statement = $this->pdo->prepare($sql);
        foreach ($values as $index=>$value) $statement->bindValue($index+1,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
        $statement->execute();
        $rows = $statement->columnCount() ? $statement->fetchAll(PDO::FETCH_OBJ) : [];
        while ($statement->nextRowset()) {}
        $statement->closeCursor();
        return $rows;
    }

    public function run(string $scenario): mixed
    {
        if (str_starts_with($scenario, 'write_')) return $this->write($scenario);
        if (in_array($scenario, ['chunk','lazy','paged_cursor','native_cursor'], true)) return $this->walk($scenario);
        if ($this->client === 'pdo') return $this->pdoRead($scenario);
        $columns = $scenario === 'structured1000' ? ['id','details','tags','embedding'] : $this->columns;
        $query = $this->selected($columns);
        switch ($scenario) {
            case 'get20':
                return $query->orderBy('id')->limit(20)->get();
            case 'match20':
            case 'rows_only20':
                $query->match('galaxy')->orderBy('id')->limit(20);
                return $scenario === 'rows_only20' && $this->client === 'v3' ? $query->getRows() : $query->get();
            case 'match_meta20':
            case 'match_meta1000':
                $query->match('galaxy')->orderBy('id')->limit($scenario === 'match_meta1000' ? 1000 : 20);
                if ($this->client === 'v4') {
                    $result = $query->search();
                    return ['rows'=>$result->rows(), 'total'=>(int)$result->meta()->totalFound()];
                }
                $rows = $query->get();
                return ['rows'=>$rows, 'total'=>(int)$query->lastResultSet()->metaObject()->totalFound()];
            case 'structured1000':
                return $query->orderBy('id')->limit(1000)->get();
            case 'json_mva20':
                if ($this->client === 'v3') $query->where('details.color', 'red');
                else $query->where('details->color', 'red');
                return $query->whereMvaAny('tags', 2)->orderBy('id')->limit(20)->get();
            case 'ranking20':
                $query->match('galaxy')->withScore()->orderBy('id')->limit(20);
                return ($this->client === 'v3' ? $query->ranker("expr('42')") : $query->ranker('expr','42'))->get();
            case 'facets20':
                $query->match('galaxy')->orderBy('id')->limit(20);
                if ($this->client === 'v3') {
                    $query->facet('brand', fn ($facet)=>$facet->alias('brands')->orderBy('brands')->limit(10));
                    $rows = $query->get(); $result = $query->lastResultSet();
                    return ['rows'=>$rows, 'facets'=>$result->facets(0), 'total'=>(int)$result->metaObject()->totalFound()];
                }
                $result = $query->facet('brand','brands',10,'value','asc')->search();
                return ['rows'=>$result->rows(), 'facets'=>$result->facet('brands'), 'total'=>(int)$result->meta()->totalFound()];
            case 'highlight20':
                $query->match('galaxy')->orderBy('id')->limit(20);
                if ($this->client === 'v3') return $query->highlight(['before_match'=>'[[','after_match'=>']]'],['title'])->get();
                return $query->withHighlight(['title'],['before_match'=>'[[','after_match'=>']]'])->search()->rows();
            case 'knn20':
                $query = $this->selected(['id']);
                if ($this->client === 'v3') return $query->whereKnn('embedding',20,$this->config['vector'])
                    ->selectRaw('knn_dist() AS _knn_distance')->orderBy('_knn_distance')->orderBy('id')->get();
                return $query->knn('embedding',$this->config['vector'],20)->withKnnDistance()->orderByKnnDistance()->orderBy('id')->search()->rows();
            case 'paginate61':
                $page = $query->orderBy('id')->paginate(20,$this->client === 'v3' ? implode(', ',$columns) : $columns,'page',61);
                return ['rows'=>$page->items(),'total'=>$page->total()];
        }
        throw new InvalidArgumentException('Unknown scenario: '.$scenario);
    }

    private function pdoRead(string $scenario): mixed
    {
        $table = $this->config['read_table'];
        $columns = implode(', ', $this->columns);
        $base = "SELECT $columns FROM $table";
        if ($scenario === 'get20') return $this->raw($base.' ORDER BY id ASC LIMIT 20');
        if (in_array($scenario,['match20','rows_only20'],true)) return $this->raw($base." WHERE MATCH(?) ORDER BY id ASC LIMIT 20",['galaxy']);
        if (in_array($scenario,['match_meta20','match_meta1000','facets20'],true)) {
            $limit = $scenario === 'match_meta1000' ? 1000 : 20;
            $sql = $base." WHERE MATCH(?) ORDER BY id ASC LIMIT $limit";
            if ($scenario !== 'facets20') {
                $rows = $this->raw($sql,['galaxy']); $meta = $this->raw('SHOW META');
                $total = 0; foreach ($meta as $row) if ($row->Variable_name === 'total_found') $total = (int)$row->Value;
                return ['rows'=>$rows,'total'=>$total];
            }
            $statement=$this->pdo->prepare($sql.' FACET brand AS brands ORDER BY brands ASC LIMIT 10');
            $statement->execute(['galaxy']); $rows=$statement->fetchAll(PDO::FETCH_OBJ);
            $statement->nextRowset(); $facets=$statement->fetchAll(PDO::FETCH_OBJ);
            while ($statement->nextRowset()) {} $statement->closeCursor();
            $meta=$this->raw('SHOW META'); $total=0;
            foreach ($meta as $row) if ($row->Variable_name==='total_found') $total=(int)$row->Value;
            return ['rows'=>$rows,'facets'=>$facets,'total'=>$total];
        }
        if ($scenario === 'structured1000') return $this->raw("SELECT id, details, tags, embedding FROM $table ORDER BY id ASC LIMIT 1000");
        if ($scenario === 'json_mva20') return $this->raw($base.' WHERE details.color = ? AND ANY(tags) = ? ORDER BY id ASC LIMIT 20',['red',2]);
        if ($scenario === 'ranking20') return $this->raw("SELECT $columns, WEIGHT() AS _score FROM $table WHERE MATCH(?) ORDER BY id ASC LIMIT 20 OPTION ranker=expr('42')",['galaxy']);
        if ($scenario === 'highlight20') return $this->raw("SELECT $columns, HIGHLIGHT({before_match='[[', after_match=']]'}, 'title') AS _highlight FROM $table WHERE MATCH(?) ORDER BY id ASC LIMIT 20",['galaxy']);
        if ($scenario === 'knn20') {
            $vector=implode(',',array_map(static fn ($value)=>(string)$value,$this->config['vector']));
            return $this->raw("SELECT id, KNN_DIST() AS _knn_distance FROM $table WHERE KNN(embedding,20,($vector)) ORDER BY _knn_distance ASC, id ASC LIMIT 20");
        }
        if ($scenario === 'paginate61') {
            $total=(int)$this->raw("SELECT COUNT(*) AS n FROM $table")[0]->n;
            return ['rows'=>$this->raw($base.' ORDER BY id ASC LIMIT 20 OFFSET 1200 OPTION max_matches=2000'),'total'=>$total];
        }
        throw new InvalidArgumentException('Unknown PDO scenario');
    }

    private function walk(string $scenario): array
    {
        $count=0; $sum=0; $hash=hash_init('sha256');
        $consume=static function ($row) use (&$count,&$sum,$hash) {
            $id=(int)(is_array($row)?$row['id']:$row->id); $count++; $sum+=$id;
            hash_update($hash,$id.',');
        };
        if ($this->client==='pdo') {
            $table=$this->config['read_table'];
            if ($scenario==='native_cursor') {
                $statement=$this->pdo->prepare("SELECT id FROM $table ORDER BY id ASC LIMIT ".$this->config['documents'].' OPTION max_matches='.$this->config['documents']);
                $statement->execute(); while ($row=$statement->fetch(PDO::FETCH_OBJ)) $consume($row); $statement->closeCursor();
            } else {
                for ($offset=0;;$offset+=500) {
                    $rows=$this->raw("SELECT id FROM $table ORDER BY id ASC LIMIT 500 OFFSET $offset OPTION max_matches=".($this->config['documents']+500));
                    foreach ($rows as $row) $consume($row);
                    if (count($rows)<500) break;
                }
            }
        } else {
            $query=$this->selected(['id'])->orderBy('id')->option('max_matches',$this->config['documents']+500);
            if ($scenario==='chunk') $query->chunk(500,static function ($rows) use ($consume) {foreach($rows as $row) $consume($row);});
            else {
                $rows=$scenario==='native_cursor' ? $query->limit($this->config['documents'])->cursor()
                    : ($scenario==='paged_cursor' && $this->client==='v3' ? $query->cursor(500) : $query->lazy(500));
                foreach ($rows as $row) $consume($row);
            }
        }
        if ($count!==$this->config['documents'] || $sum!==$count*($count+1)/2) throw new RuntimeException('Walk lost documents');
        return ['count'=>$count,'sum'=>$sum,'digest'=>hash_final($hash)];
    }

    private function write(string $scenario): mixed
    {
        $table=$this->config['write_tables'][$this->client];
        $rows=$GLOBALS['comparison_write_rows'];
        if ($this->client==='pdo') {
            if ($scenario==='write_update') { $this->raw("UPDATE $table SET price=? WHERE id=?",[987,1]); return true; }
            if ($scenario==='write_delete') { $this->raw("DELETE FROM $table WHERE id=?",[1]); return true; }
            $verb=$scenario==='write_insert500'?'INSERT':'REPLACE';
            $columns=array_keys($rows[0]); $values=[]; $placeholders=[];
            foreach($rows as $row) {
                $slots=[];
                foreach($row as $key=>$value) {
                    if(in_array($key,['tags','embedding'],true)) {
                        $slots[]='('.implode(',',array_map(static fn($v)=>(string)$v,$value)).')';
                    } else { $slots[]='?'; $values[]=is_array($value)?json_encode($value,JSON_THROW_ON_ERROR):$value; }
                }
                $placeholders[]='('.implode(',',$slots).')';
            }
            $this->raw("$verb INTO $table (".implode(',',$columns).') VALUES '.implode(',',$placeholders),$values);
            return true;
        }
        $query=$this->query($table);
        return match($scenario) {
            'write_insert500'=>$query->insert($rows),
            'write_replace500'=>$query->replace($rows),
            'write_update'=>$query->where('id',1)->update(['price'=>987]),
            'write_delete'=>$query->where('id',1)->delete(),
            default=>throw new InvalidArgumentException('Unknown write'),
        };
    }

    public function normalize(string $scenario, mixed $result): mixed
    {
        if (str_starts_with($scenario,'write_')) {
            $table=$this->config['write_tables'][$this->client];
            $rows=$this->raw("SELECT id,title,brand,price,category,details,tags FROM $table ORDER BY id ASC LIMIT 500");
            $scalar=$this->rows($rows); $structured=$this->rows($rows,true);
            return array_map(static fn($a,$b)=>array_merge($a,$b),$scalar,$structured);
        }
        if (in_array($scenario,['chunk','lazy','paged_cursor','native_cursor'],true)) return $result;
        if (in_array($scenario,['match_meta20','match_meta1000','paginate61','facets20'],true)) {
            $out=['rows'=>$this->rows($result['rows']),'total'=>(int)$result['total']];
            if (isset($result['facets'])) {
                $out['facets']=[];
                foreach($result['facets'] as $row) {
                    $row=$row instanceof Illuminate\Contracts\Support\Arrayable?$row->toArray():(array)$row;
                    $out['facets'][]=['brand'=>(string)($row['brands']??$row['brand']),'n'=>(int)($row['count(*)']??$row['_count'])];
                }
                usort($out['facets'],static fn($a,$b)=>strcmp($a['brand'],$b['brand']));
            }
            return $out;
        }
        return $this->rows($result,$scenario==='structured1000',$scenario==='ranking20',$scenario==='knn20',$scenario==='highlight20');
    }

    private function rows(iterable $rows,bool $structured=false,bool $score=false,bool $distance=false,bool $highlight=false):array
    {
        $out=[];
        foreach($rows as $row) {
            $row=$row instanceof Illuminate\Contracts\Support\Arrayable?$row->toArray():(array)$row;
            $keep=$structured?['id','details','tags','embedding']:$this->columns;
            if ($score) $keep[]='_score'; if ($distance) $keep=['id','_knn_distance']; if($highlight)$keep[]='_highlight';
            $item=[];
            foreach($keep as $key) {
                if(!array_key_exists($key,$row))continue;
                $value=$row[$key];
                if(in_array($key,['id','category','_score'],true))$value=(int)$value;
                if(in_array($key,['price','_knn_distance'],true))$value=round((float)$value,6);
                if($key==='details'&&is_string($value))$value=json_decode($value,true,512,JSON_THROW_ON_ERROR);
                if(in_array($key,['tags','embedding'],true)&&is_string($value))$value=explode(',',trim($value,'()[]'));
                if($key==='tags')$value=array_map('intval',$value);
                if($key==='embedding')$value=array_map(static fn($v)=>round((float)$v,6),$value);
                if($key==='details'&&is_array($value))ksort($value);
                $item[$key]=$value;
            }
            $out[]=$item;
        }
        return $out;
    }
}
