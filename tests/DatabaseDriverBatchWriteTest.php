<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Database\ManticoreConnection;
use avadim\Manticore\Laravel\Database\ManticoreGrammar;
use avadim\Manticore\Laravel\Database\ManticoreQueryBuilder;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

enum BatchWriteState: int { case Ready = 7; }
enum BatchWriteUnit { case Ready; }

class BatchWriteConnection extends ManticoreConnection
{
    public array $writes = [];
    public ?Closure $onSchema = null;

    public function getTableSchema(string $table): array
    {
        ($this->onSchema ?? static fn () => null)();
        return ['id'=>['type'=>'bigint'],'title'=>['type'=>'text'],'price'=>['type'=>'float'],
            'details'=>['type'=>'json'],'tags'=>['type'=>'multi'],'embedding'=>['type'=>'float_vector'],
            'created'=>['type'=>'timestamp'],'enabled'=>['type'=>'bool']];
    }

    public function insert($query, $bindings = [])
    {
        $this->writes[] = [$query, $bindings];
        return true;
    }
}

class HookedBatchWriteQuery extends ManticoreQueryBuilder
{
    public int $encodings = 0;
    public int $cleanings = 0;
    public int $replacements = 0;

    protected function encodeWriteRows(array $values): array
    {
        $this->encodings++;
        return parent::encodeWriteRows($values);
    }

    public function cleanBindings(array $bindings)
    {
        $this->cleanings++;
        return parent::cleanBindings($bindings);
    }

    protected function compileReplacement(array $values): array
    {
        $this->replacements++;
        return parent::compileReplacement($values);
    }
}

class DatabaseDriverBatchWriteTest extends TestCase
{
    private function connection(bool $fallback = false, array $config = []): BatchWriteConnection
    {
        $db = new BatchWriteConnection(fn () => throw new LogicException('Unexpected PDO access'), '', 'pre_', $config);
        if ($fallback) {
            // Inheriting the stock Grammar retains the generic path, providing a reference.
            $grammar = new class($db) extends ManticoreGrammar {};
            if (method_exists($grammar, 'setTablePrefix')) {
                $grammar->setTablePrefix($db->getTablePrefix()); // Laravel 11 stores it on Grammar.
            }
            $db->setQueryGrammar($grammar);
        }
        return $db;
    }

    public function testBatchSqlAndBindingsMatchGenericCompilationForReorderedStructuredRows(): void
    {
        foreach (['insert', 'replace'] as $method) {
            foreach (range(1, 80) as $seed) {
                $rows = [];
                foreach (range(1, 3) as $id) {
                    $row = ['id'=>$id,'title'=>"Телефон ? :key 'quoted' $seed",'price'=>($seed % 2 ? -1 : 1) * $id / 10,
                        'details'=>$seed % 3 ? ['n'=>0.125,'text'=>'? :key'] : 7.25,
                        'tags'=>[1,$id],'embedding'=>[0.125,1.0,2.5],
                        'created'=>new DateTimeImmutable('2026-10-26T10:00:00Z'),'enabled'=>$id % 2 === 0];
                    if ($seed % 5 === 0) $row['title'] = new Expression("'? :raw'");
                    if ($seed % 7 === 0) $row['price'] = -0.0;
                    if ($seed % 11 === 0) $row['price'] = 1e-7;
                    $rows[] = $id % 2 ? array_reverse($row, true) : $row;
                }
                $original = $rows;
                $fast = $this->connection();
                $generic = $this->connection(true);
                $this->assertTrue($fast->table('products')->{$method}($rows));
                $generic->table('products')->{$method}($rows);
                $this->assertSame($generic->writes, $fast->writes);
                $this->assertSame($original, $rows);
            }
        }
    }

    public function testDisabledCastsUnknownScalarColumnsAndReferencedFloatsMatchGenericPath(): void
    {
        foreach (['insert', 'replace'] as $method) {
            $number = 55.3;
            $rows = [['id'=>1,'unknown'=>null,'price'=>&$number],['price'=>1e20,'unknown'=>false,'id'=>2]];
            $fast = $this->connection(false, ['cast_attributes'=>false]);
            $generic = $this->connection(true, ['cast_attributes'=>false]);
            $fast->table('products')->{$method}($rows);
            $generic->table('products')->{$method}($rows);
            $this->assertSame($generic->writes, $fast->writes);
            $this->assertSame(55.3, $number);
        }
    }

    public function testCleanBindingsMatchesLaravelAndRetainsCastBindingOverrides(): void
    {
        $query = $this->connection()->table('products');
        $original = new ReflectionMethod(Builder::class, 'cleanBindings');
        $values = [null,true,false,0,PHP_INT_MAX,55.3,'55.3',new Expression('42'),
            BatchWriteState::Ready,BatchWriteUnit::Ready,new DateTimeImmutable('2026-10-26')];
        foreach (range(1, 100) as $offset) {
            $bindings = [];
            foreach (range(0, 25) as $index) $bindings[$index * 3] = $values[($index + $offset) % count($values)];
            $this->assertSame($original->invoke($query, $bindings), $query->cleanBindings($bindings));
        }
        $db = $this->connection();
        $custom = new class($db, $db->getQueryGrammar(), $db->getPostProcessor()) extends ManticoreQueryBuilder {
            public function castBinding($value) { return is_int($value) ? $value + 1 : parent::castBinding($value); }
        };
        $this->assertSame([2, 'x'], $custom->cleanBindings([1,new Expression('99'),'x']));
    }

    public function testBuilderSubclassKeepsEncodingBindingAndReplacementHooks(): void
    {
        foreach (['insert', 'replace'] as $method) {
            $db = $this->connection();
            $query = (new HookedBatchWriteQuery($db,$db->getQueryGrammar(),$db->getPostProcessor()))->from('products');
            $query->{$method}([['id'=>1,'price'=>55.3],['price'=>12.75,'id'=>2]]);
            $this->assertSame(1, $query->encodings);
            $this->assertSame(1, $query->cleanings);
            $this->assertSame($method === 'replace' ? 1 : 0, $query->replacements);
            $this->assertCount(1, $db->writes);
        }
    }

    public function testCustomGrammarAndLateGrammarReplacementAreRespected(): void
    {
        foreach (['insert', 'replace'] as $method) {
            foreach ([false, true] as $late) {
                $db = $this->connection();
                $grammar = new class($db) extends ManticoreGrammar {
                    public int $calls = 0;
                    public function compileInsert(Builder $query, array $values)
                    {
                        $this->calls++;
                        return parent::compileInsert($query, $values).' /* custom */';
                    }
                };
                if (!$late) $db->setQueryGrammar($grammar);
                $query = $db->table('products');
                if ($late) $db->onSchema = static function () use ($query, $grammar) { $query->grammar = $grammar; };
                $query->{$method}([['id'=>1,'price'=>55.3],['price'=>12.75,'id'=>2]]);
                $this->assertSame(1, $grammar->calls);
                $this->assertStringEndsWith('/* custom */', $db->writes[0][0]);
            }
        }
    }

    public function testCustomAndNestedExpressionsKeepReferencedValuesAndEvaluationOrder(): void
    {
        foreach (['insert','replace'] as $method) foreach ([false,true] as $nested) {
            $writes = [];
            foreach ([false,true] as $fallback) {
                $db = $this->connection($fallback);
                $price = 55.3;
                $calls = 0;
                $callback = static function () use (&$price, &$calls) { $calls++; $price = 99.9; return "'raw'"; };
                $expression = new class($callback) implements \Illuminate\Contracts\Database\Query\Expression {
                    public function __construct(private Closure $callback) {}
                    public function getValue(\Illuminate\Database\Grammar $grammar) { return ($this->callback)(); }
                };
                $rows = [['id'=>1,'brand'=>$nested ? new Expression($expression) : $expression,'price'=>&$price],
                    ['price'=>12.75,'id'=>2,'brand'=>'Nova']];
                $db->table('products')->{$method}($rows);
                $this->assertSame(1, $calls);
                $this->assertSame(99.9, $price);
                $writes[] = $db->writes;
            }
            $this->assertSame($writes[1], $writes[0]);
            $this->assertStringContainsString('55.3', $writes[0][0][0]);
        }
    }

    public function testCallbacksRegisteredDuringSchemaLoadingRunForInsert(): void
    {
        foreach ([false,true] as $fallback) {
            $db = $this->connection($fallback);
            $query = $db->table('products');
            $calls = 0;
            $price = 55.3;
            $db->onSchema = static function () use ($query, &$calls, &$price) {
                $query->beforeQuery(static function ($query) use (&$calls, &$price) {
                    $calls++; $price = 99.9; $query->from('other_products');
                });
            };
            $query->insert([['id'=>1,'price'=>&$price],['id'=>2,'price'=>12.75]]);
            $this->assertSame(1, $calls);
            $this->assertStringStartsWith('insert into `pre_other_products`', $db->writes[0][0]);
            $this->assertStringContainsString('55.3', $db->writes[0][0]);
            $this->assertStringNotContainsString('99.9', $db->writes[0][0]);
        }
    }

    public function testReplaceRetainsDeferredCallbacksRegisteredDuringSchemaLoading(): void
    {
        foreach ([false, true] as $fallback) {
            $db = $this->connection($fallback);
            $query = $db->table('products');
            $calls = 0;
            $db->onSchema = static function () use ($query, &$calls) {
                $query->beforeQuery(static function ($query) use (&$calls) { $calls++; $query->from('other_products'); });
            };
            $query->replace([['id'=>1,'price'=>55.3],['id'=>2,'price'=>12.75]]);
            $this->assertSame(0, $calls);
            $this->assertStringStartsWith('replace into `pre_products`', $db->writes[0][0]);
        }
    }

    public function testInvalidBatchPayloadsAndNonFiniteNumbersNeverReachInsert(): void
    {
        $payloads = [
            [['id'=>1],['id'=>2,'extra'=>1]], [['id'=>1],[]], [['id'=>1],['id'=>2,'json->path'=>3]],
            [['id'=>1],['id'=>2,0=>'bad']], [['id'=>1,'price'=>INF],['id'=>2,'price'=>1]],
            [['id'=>1,'price'=>1],['id'=>2,'price'=>NAN]],
        ];
        foreach (['insert','replace'] as $method) foreach ($payloads as $rows) {
            $db = $this->connection();
            try {
                $db->table('products')->{$method}($rows);
                $this->fail('Invalid batch was accepted');
            } catch (InvalidArgumentException|LogicException $error) {
                $this->assertSame([], $db->writes);
            }
        }
    }

    public function testSchemaListenersCannotAddUnsupportedWriteConditions(): void
    {
        foreach (['insert','replace'] as $method) {
            $db = $this->connection();
            $query = $db->table('products');
            $db->onSchema = static function () use ($query) { $query->where('id', 1); };
            try {
                $query->{$method}([['id'=>1,'price'=>55.3],['id'=>2,'price'=>12.75]]);
                $this->fail('Late WHERE was accepted');
            } catch (LogicException $error) {
                $this->assertSame([], $db->writes);
            }
        }
    }
}
