<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Database\AttributeCodec;
use avadim\Manticore\Laravel\Database\ManticoreConnection;
use avadim\Manticore\Laravel\Database\ManticoreQueryBuilder;
use DateTimeImmutable;
use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Query\Expression;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

class DatabaseDriverEncodingPlanTest extends TestCase
{
    private function connection(array $schema, array $config = []): ManticoreConnection
    {
        $db = new ManticoreConnection(static fn () => throw new LogicException('Unexpected PDO access'), '', '', $config);
        (new ReflectionProperty(ManticoreConnection::class, 'schemaCache'))->setValue($db, $schema);
        return $db;
    }

    private function encode(ManticoreQueryBuilder $query, array $rows): array
    {
        return (new ReflectionMethod($query, 'encodeWriteRows'))->invoke($query, $rows);
    }

    public function testBatchKeepsColumnNamesRowOrderAndValidatedStructuredValues(): void
    {
        $types = ['id'=>'bigint', 'title'=>'text', 'details'=>'json', 'tags'=>'multi64',
            'embedding'=>'float_vector', 'created'=>'timestamp', 'price'=>'float'];
        $db = $this->connection(['products'=>array_map(static fn ($type) => ['type'=>$type], $types)]);
        $raw = new Expression("'? :key'");
        $rows = [
            ['id'=>1, 'details'=>['n'=>0.125], 'tags'=>['9007199254740993', '-1'],
                'embedding'=>[1.0, -0.0], 'created'=>new DateTimeImmutable('@1700000000'), 'title'=>$raw],
            ['title'=>'Phone', 'created'=>1700000001, 'embedding'=>[], 'tags'=>[],
                'details'=>'{"ok":true}', 'id'=>2, 'price'=>55.3, 'unknown'=>null],
        ];
        $encoded = $this->encode($db->table('products'), $rows);
        $this->assertSame(array_keys($rows[0]), array_keys($encoded[0]));
        $this->assertSame(array_keys($rows[1]), array_keys($encoded[1]));
        $this->assertSame('{"n":0.125}', $encoded[0]['details']);
        $this->assertSame('{"ok":true}', $encoded[1]['details']);
        $this->assertSame('(9007199254740993, -1)', $encoded[0]['tags']->getValue($db->getQueryGrammar()));
        $this->assertSame('(1.0, -0.0)', $encoded[0]['embedding']->getValue($db->getQueryGrammar()));
        $this->assertSame('()', $encoded[1]['tags']->getValue($db->getQueryGrammar()));
        $this->assertSame('()', $encoded[1]['embedding']->getValue($db->getQueryGrammar()));
        $this->assertSame(1700000000, $encoded[0]['created']);
        $this->assertSame(55.3, $encoded[1]['price']);
        $this->assertNull($encoded[1]['unknown']);
        $this->assertSame($raw, $encoded[0]['title']);
        $this->assertSame(['n'=>0.125], $rows[0]['details']);
        $this->assertInstanceOf(DateTimeImmutable::class, $rows[0]['created']);
    }

    public function testPlanUsesSchemaAfterCallbacksAndDoesNotSurviveTheNextCall(): void
    {
        $db = $this->connection([
            'products'=>['value'=>['type'=>'json']],
            'other_products'=>['value'=>['type'=>'multi64']],
        ]);
        $query = $db->table('products');
        $calls = 0;
        $query->beforeQuery(static function ($query) use (&$calls) { $calls++; $query->from('other_products'); });
        $encoded = $this->encode($query, [['value'=>[1]], ['value'=>[2]]]);
        $this->assertSame(1, $calls);
        $this->assertSame('(1)', $encoded[0]['value']->getValue($db->getQueryGrammar()));
        $query->from('products');
        $this->assertSame([['value'=>'[1]'], ['value'=>'[2]']], $this->encode($query, [['value'=>[1]], ['value'=>[2]]]));
        (new ReflectionProperty(ManticoreConnection::class, 'schemaCache'))->setValue($db, [
            'products'=>['value'=>['type'=>'float']],
        ]);
        $this->assertSame([['value'=>1.25], ['value'=>2.5]], $this->encode($query, [['value'=>1.25], ['value'=>2.5]]));
    }

    public function testDisabledCastsAndPretendDoNotUseCachedSchema(): void
    {
        foreach ([false, true] as $pretend) {
            $db = $this->connection(['products'=>['details'=>['type'=>'json']]], $pretend ? [] : ['cast_attributes'=>false]);
            $query = $db->table('products');
            $raw = new Expression("'{}'");
            $rows = [['details'=>$raw], ['details'=>'plain string']];
            $encoded = null;
            $run = function () use ($query, $rows, &$encoded) { $encoded = $this->encode($query, $rows); };
            if ($pretend) {
                $this->assertSame([], $db->pretend($run));
            } else {
                $run();
            }
            $this->assertSame($rows, $encoded);
            $invalid = fn () => $this->encode($query, [['details'=>[]], ['details'=>[]]]);
            try {
                $pretend ? $db->pretend($invalid) : $invalid();
                $this->fail('Unknown structured values were accepted.');
            } catch (InvalidArgumentException $error) {
                $this->assertSame('A structured value requires a known JSON, MVA or vector column.', $error->getMessage());
            }
        }
    }

    public function testUnknownAndInvalidColumnsRetainErrorsAndEvaluationOrder(): void
    {
        foreach ([
            ['known', ['x'=>1], 'Structured values require a JSON, MVA or vector column.'],
            ['unknown', ['x'=>1], 'A structured value requires a known JSON, MVA or vector column.'],
        ] as [$column, $value, $message]) {
            $db = $this->connection(['products'=>['details'=>['type'=>'json'], 'known'=>['type'=>'string']]]);
            $calls = [];
            $json = new class($calls) implements JsonSerializable {
                public function __construct(private array &$calls) {}
                public function jsonSerialize(): mixed { $this->calls[] = 'json'; return ['ok'=>true]; }
            };
            try {
                $this->encode($db->table('products'), [
                    ['details'=>$json, $column=>'scalar'], [$column=>$value, 'details'=>$json],
                ]);
                $this->fail('Invalid structured value was accepted.');
            } catch (InvalidArgumentException $error) {
                $this->assertSame($message, $error->getMessage());
                $this->assertSame(['json'], $calls);
            }
        }
    }

    public function testReferencedValuesAndInheritedEncodingHookKeepTheirBehavior(): void
    {
        $db = $this->connection(['products'=>['details'=>['type'=>'json']]]);
        $query = new class($db, $db->getQueryGrammar(), $db->getPostProcessor()) extends ManticoreQueryBuilder {
            public int $encodings = 0;
            protected function encodeWriteRows(array $values): array
            {
                $this->encodings++;
                return parent::encodeWriteRows($values);
            }
        };
        $query->from('products');
        $value = ['n'=>1.25];
        $this->assertSame([['details'=>'{"n":1.25}'], ['details'=>'false']],
            $this->encode($query, [['details'=>&$value], ['details'=>false]]));
        $this->assertSame('{"n":1.25}', $value);
        $this->assertSame(1, $query->encodings);
        $this->assertSame([], $this->encode($query, []));
        $this->assertSame(['details'=>'null'], $this->encode($query, ['details'=>null]));
        $this->assertSame([['details'=>'0.0']], $this->encode($query, [['details'=>0.0]]));
    }

    public function testCustomConnectionRetainsPerValueMetadataBehavior(): void
    {
        $type = 'json';
        $db = new class(static fn () => throw new LogicException('Unexpected PDO access')) extends ManticoreConnection {
            public array $schema = [];
            public function getTableSchema(string $table): array { return $this->schema; }
        };
        $db->schema = ['details'=>['type'=>&$type]];
        $json = new class($type) implements JsonSerializable {
            public function __construct(private string &$type) {}
            public function jsonSerialize(): mixed { $this->type = 'float'; return ['ok'=>true]; }
        };
        $this->assertSame([['details'=>'{"ok":true}'], ['details'=>7.25]],
            $this->encode($db->table('products'), [['details'=>$json], ['details'=>7.25]]));
    }

    public function testPreparedHandlersPreserveExpressionsForEveryType(): void
    {
        $raw = new Expression('(1)');
        $custom = new class implements ExpressionContract {
            public function getValue(\Illuminate\Database\Grammar $grammar) { return '(2)'; }
        };
        foreach (['json', 'timestamp', 'multi', 'multi64', 'mva', 'mva64', 'float_vector', 'string', 'other'] as $type) {
            $encode = AttributeCodec::encoder($type);
            foreach ([$raw, $custom] as $value) {
                $this->assertSame($value, $encode($value));
                $this->assertSame($value, AttributeCodec::encode($type, $value));
            }
        }
    }

    public function testPreparedHandlersRejectInvalidStructuredValuesWithOriginalMessages(): void
    {
        foreach ([
            ['json', '{', \JsonException::class, 'Syntax error'],
            ['multi', [1.5], InvalidArgumentException::class, 'MVA values must be integers.'],
            ['multi64', ['1); DROP TABLE t'], InvalidArgumentException::class, 'MVA values must be integers.'],
            ['float_vector', ['1.25'], InvalidArgumentException::class, 'Vectors require finite numeric values.'],
            ['float_vector', [NAN], InvalidArgumentException::class, 'Vectors require finite numeric values.'],
            ['float_vector', [INF], InvalidArgumentException::class, 'Vectors require finite numeric values.'],
            ['mva', ['x'=>1], InvalidArgumentException::class, 'MVA and vector values must be lists.'],
            ['float_vector', null, InvalidArgumentException::class, 'MVA and vector values must be lists.'],
            ['timestamp', (object)[], InvalidArgumentException::class, 'Structured values require a JSON, MVA or vector column.'],
            ['string', [], InvalidArgumentException::class, 'Structured values require a JSON, MVA or vector column.'],
        ] as [$type, $value, $class, $message]) {
            foreach ([AttributeCodec::encoder($type), static fn ($v) => AttributeCodec::encode($type, $v)] as $encode) {
                try {
                    $encode($value);
                    $this->fail('Invalid value was accepted for '.$type);
                } catch (\Throwable $error) {
                    $this->assertSame($class, get_class($error));
                    $this->assertSame($message, $error->getMessage());
                }
            }
        }
    }
}
