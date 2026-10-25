<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Database\AttributeCodec;
use avadim\Manticore\Laravel\Database\ManticoreConnection;
use DateTimeImmutable;
use Illuminate\Database\Query\Grammars\Grammar;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

class DatabaseDriverAttributeCodecTest extends TestCase
{
    public function testLargeIntegersAndEmptyCollectionsRemainExact(): void
    {
        $this->assertSame([9007199254740993], AttributeCodec::decode('mva64', '9007199254740993'));
        $this->assertSame('18446744073709551615', AttributeCodec::decode('bigint', '18446744073709551615'));
        $this->assertSame([], AttributeCodec::decode('mva', ''));
        $this->assertSame([], AttributeCodec::decode('float_vector', ''));
        $this->assertNull(AttributeCodec::decode('json', null));
        $this->assertSame(['x' => ['enabled' => true]], AttributeCodec::decode('json', '{"x":{"enabled":true}}'));
        $this->assertFalse(AttributeCodec::decode('bool', '0'));
    }

    public function testStructuredWritesUseValidatedSqlExpressionsAndJson(): void
    {
        $grammar = (new ManticoreConnection(fn () => throw new LogicException('Unexpected connection')))->getQueryGrammar();
        $this->assertSame('(9007199254740993, 2)', AttributeCodec::encode('multi64', ['9007199254740993', 2])->getValue($grammar));
        $this->assertSame('(1.5, -2.25, 0)', AttributeCodec::encode('float_vector', [1.5, -2.25, 0])->getValue($grammar));
        $this->assertSame('()', AttributeCodec::encode('multi', [])->getValue($grammar));
        $this->assertSame('{"enabled":true}', AttributeCodec::encode('json', ['enabled' => true]));
        $this->assertSame(1700000000, AttributeCodec::encode('timestamp', new DateTimeImmutable('@1700000000')));
    }

    public function testInvalidStructuredValuesCannotBecomeSql(): void
    {
        foreach ([['multi', [1, '2); DROP TABLE t']], ['multi', ['x' => 1]], ['multi', [1.5]],
            ['float_vector', [INF]], ['float_vector', ['1,2']], ['float_vector', null], ['string', ['x' => 1]]] as [$type, $value]) {
            try {
                AttributeCodec::encode($type, $value);
                $this->fail('An invalid structured value was accepted.');
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testEmptyUpsertAndInvalidLookupKeysDoNotConnect(): void
    {
        $db = new ManticoreConnection(fn () => throw new LogicException('Unexpected connection'));
        $this->assertSame(0, $db->table('products')->upsert([], ['id']));
        $this->expectException(InvalidArgumentException::class);
        $db->table('products')->upsert([['id' => 1, 'title' => 'test']], []);
    }
}
