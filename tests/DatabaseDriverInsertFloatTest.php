<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Database\ManticoreConnection;
use avadim\Manticore\Laravel\Database\NumericBindings;
use Illuminate\Database\Query\Expression;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

class DatabaseDriverInsertFloatTest extends TestCase
{
    private function connection(array $config = []): ManticoreConnection
    {
        return new class(fn () => throw new LogicException('Unexpected PDO access'), '', '', $config) extends ManticoreConnection {
            public array $writes = [];
            public function getTableSchema(string $table): array
            {
                return ['id'=>['type'=>'bigint'],'title'=>['type'=>'text'],'price'=>['type'=>'float'],
                    'details'=>['type'=>'json'],'tags'=>['type'=>'multi'],'embedding'=>['type'=>'float_vector']];
            }
            public function insert($query, $bindings = [])
            {
                $this->writes[] = [$query, $bindings];
                return true;
            }
            public function insertAndGetId(string $sql, array $bindings = []): int|string
            {
                $this->writes[] = [$sql, $bindings];
                return 42;
            }
            public function update($query, $bindings = [])
            {
                $this->writes[] = [$query, $bindings];
                return 1;
            }
        };
    }

    public function testAllInsertAndReplaceTerminalsRenderFloatsBeforeExecution(): void
    {
        foreach (['insert','insertGetId','replace','replaceGetId'] as $method) {
            $db = $this->connection();
            $price = 55.3;
            $values = ['id'=>42,'title'=>'Phone','price'=>&$price];
            $db->table('products')->{$method}($values);
            [$sql,$bindings] = $db->writes[0];
            $this->assertStringContainsString('55.3', $sql);
            $this->assertSame([42,'Phone'], $bindings);
            $this->assertSame([$sql,$bindings], NumericBindings::prepare($sql,$bindings));
            $this->assertSame(['id'=>42,'title'=>'Phone','price'=>55.3], $values);
            $this->assertSame(55.3, $price);
        }
    }

    public function testSchemaEncodingKeepsJsonNumbersBoundAndStructuredExpressionsValid(): void
    {
        $db = $this->connection();
        $db->table('products')->insert(['id'=>42,'price'=>0.125,'details'=>7.25,
            'tags'=>[1,2],'embedding'=>[1.25,2.5,3.0],'title'=>"Phone ? :price 'quoted'"]);
        [$sql,$bindings] = $db->writes[0];
        $this->assertStringContainsString('0.125', $sql);
        $this->assertStringContainsString('(1.25, 2.5, 3.0)', $sql);
        $this->assertStringContainsString('(1, 2)', $sql);
        $this->assertSame([42,'7.25',"Phone ? :price 'quoted'"], $bindings);
    }

    public function testBatchOrderQuotedMarkersAndDisabledCastsRemainCorrect(): void
    {
        $db = $this->connection(['cast_attributes'=>false]);
        $db->table('products')->replace([
            ['id'=>1,'price'=>-0.0,'title'=>new Expression("'? :raw'")],
            ['title'=>'Phone?','price'=>1e-7,'id'=>2],
        ]);
        [$sql,$bindings] = $db->writes[0];
        $this->assertStringContainsString('-0.0', $sql);
        $this->assertStringContainsString(json_encode(1e-7, JSON_PRESERVE_ZERO_FRACTION), $sql);
        $this->assertStringContainsString("'? :raw'", $sql);
        $this->assertSame([1,2,'Phone?'], $bindings);
    }

    public function testNonFiniteFloatsCannotReachTheConnection(): void
    {
        foreach (['insert','insertGetId','replace','replaceGetId'] as $method) {
            foreach ([INF,-INF,NAN] as $number) {
                $db = $this->connection();
                try {
                    $db->table('products')->{$method}(['id'=>42,'price'=>$number]);
                    $this->fail('A non-finite write was accepted.');
                } catch (InvalidArgumentException $error) {
                    $this->assertStringContainsString('finite', $error->getMessage());
                    $this->assertSame([], $db->writes);
                }
            }
        }
    }

    public function testUpdateAndRawSqlStillUseTheGeneralFloatRenderer(): void
    {
        $db = $this->connection();
        $db->table('products')->where('id',42)->update(['price'=>55.3]);
        [$sql,$bindings] = $db->writes[0];
        $this->assertSame([55.3,42], $bindings);
        $this->assertSame(['update `products` set `price` = 55.3 where `id` = ?', [42]], NumericBindings::prepare($sql,$bindings));
        $this->assertSame(["SELECT '?' AS marker FROM t WHERE price > 55.3",[]],
            NumericBindings::prepare("SELECT '?' AS marker FROM t WHERE price > ?",[55.3]));
    }
}
