<?php
declare(strict_types=1);

/** Benchmark-only boundary tracing; never loaded by the package. */
final class PipelineStop extends RuntimeException {}

final class PipelineTrace
{
    public static bool $enabled = false;
    public static bool $capture = false;
    public static bool $stop = false;
    public static bool $stoppedAtBoundary = false;
    public static int $start = 0;
    public static int $cpuStart = 0;
    public static array $records = [];
    public static array $compilations = [];
    public static float $compileMs = 0.0;

    public static function begin(bool $capture = false, bool $stop = false): void
    {
        self::$records = self::$compilations = [];
        self::$compileMs = 0.0;
        self::$capture = $capture;
        self::$stop = $stop;
        self::$stoppedAtBoundary = false;
        self::$enabled = true;
        self::$cpuStart = self::cpuMicros();
        self::$start = hrtime(true);
    }

    public static function cpuMicros(): int
    {
        $r=getrusage();
        return ($r['ru_utime.tv_sec']+$r['ru_stime.tv_sec'])*1000000+$r['ru_utime.tv_usec']+$r['ru_stime.tv_usec'];
    }

    public static function enter(string $sql, string $method): int
    {
        $now = hrtime(true);
        self::$records[] = ['sql'=>$sql,'method'=>$method,'entered_ns'=>$now,
            'cpu_before_pdo_ms'=>(self::cpuMicros()-self::$cpuStart)/1000,
            'prepare_ms'=>0.0,'binding_ms'=>0.0,'execute_ms'=>0.0,'fetch_ms'=>0.0,
            'bindings'=>[],'execute_params'=>null];
        if (self::$stop) {
            self::$stoppedAtBoundary = true;
            throw new PipelineStop('Captured before PDO');
        }
        return array_key_last(self::$records);
    }

    public static function finish(float $total): array
    {
        self::$enabled = false;
        $cpu=(self::cpuMicros()-self::$cpuStart)/1000;
        $sum = ['prepare_ms'=>0.0,'binding_ms'=>0.0,'execute_ms'=>0.0,'fetch_ms'=>0.0];
        foreach (self::$records as $record) {
            foreach ($sum as $key=>$_) $sum[$key] += $record[$key];
        }
        return ['total_ms'=>$total,'before_pdo_ms'=>(self::$records[0]['entered_ns']-self::$start)/1e6,
            'cpu_ms'=>$cpu,'cpu_before_pdo_ms'=>self::$records[0]['cpu_before_pdo_ms'],
            'other_php_ms'=>$total-array_sum($sum), 'sql_count'=>count(self::$records),
            'compile_ms'=>self::$compileMs,
            'compilations'=>self::$compilations] + $sum;
    }
}

class PipelineStatement extends PDOStatement
{
    public ?int $traceId = null;
    private ?int $preparedAt = null;
    protected function __construct() {}

    public function mark(int $id): void { $this->traceId=$id; $this->preparedAt=hrtime(true); }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        if (PipelineTrace::$enabled && PipelineTrace::$capture && $this->traceId !== null) {
            PipelineTrace::$records[$this->traceId]['bindings'][] = [$param,$value,$type];
        }
        return parent::bindValue($param,$value,$type);
    }

    public function execute(?array $params = null): bool
    {
        if (!PipelineTrace::$enabled || $this->traceId === null) return parent::execute($params);
        $start=hrtime(true); $id=$this->traceId;
        PipelineTrace::$records[$id]['binding_ms']=($start-$this->preparedAt)/1e6;
        $result=parent::execute($params);
        PipelineTrace::$records[$id]['execute_ms']=(hrtime(true)-$start)/1e6;
        if (PipelineTrace::$capture) {
            PipelineTrace::$records[$id]['execute_params']=$params;
            ob_start(); $this->debugDumpParams(); $dump=ob_get_clean();
            if (preg_match('/Sent SQL: \[(\d+)\] /', $dump, $match, PREG_OFFSET_CAPTURE)) {
                $offset=$match[0][1]+strlen($match[0][0]);
                PipelineTrace::$records[$id]['literal_sql']=substr($dump,$offset,(int)$match[1][0]);
            } else {
                PipelineTrace::$records[$id]['literal_sql']=$this->queryString;
            }
        }
        return $result;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $start=hrtime(true); $result=parent::fetchAll($mode,...$args);
        if (PipelineTrace::$enabled && $this->traceId !== null) {
            PipelineTrace::$records[$this->traceId]['fetch_ms']+=(hrtime(true)-$start)/1e6;
        }
        return $result;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $start=hrtime(true); $result=parent::fetch($mode,$cursorOrientation,$cursorOffset);
        if (PipelineTrace::$enabled && $this->traceId !== null) {
            PipelineTrace::$records[$this->traceId]['fetch_ms']+=(hrtime(true)-$start)/1e6;
        }
        return $result;
    }
}

class PipelinePDO extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (!PipelineTrace::$enabled) return parent::prepare($query,$options);
        $id=PipelineTrace::enter($query,'prepare'); $start=hrtime(true);
        $statement=parent::prepare($query,$options);
        PipelineTrace::$records[$id]['prepare_ms']=(hrtime(true)-$start)/1e6;
        $statement->mark($id);
        return $statement;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        if (!PipelineTrace::$enabled) return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$args);
        $id=PipelineTrace::enter($query,'query'); $start=hrtime(true);
        $statement=$fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$args);
        PipelineTrace::$records[$id]['execute_ms']=(hrtime(true)-$start)/1e6;
        PipelineTrace::$records[$id]['literal_sql']=$query;
        $statement->mark($id);
        return $statement;
    }
}
