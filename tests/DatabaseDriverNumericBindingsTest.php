<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Database\NumericBindings;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DatabaseDriverNumericBindingsTest extends TestCase
{
    public function testOnlyFloatParametersAreRenderedAndRemainingBindingsAreReindexed(): void
    {
        $this->assertSame(
            ['SELECT ? FROM products WHERE price > 12.75 AND id = ? AND ratio < 0.0', ['title', 42]],
            NumericBindings::prepare('SELECT ? FROM products WHERE price > ? AND id = ? AND ratio < ?', ['title', 12.75, 42, 0.0]),
        );
    }

    public function testQuotedValuesIdentifiersAndCommentsCannotConsumeParameters(): void
    {
        $sql = <<<'SQL'
SELECT '?', "?", `?`, 'it\'s ?', 'doubled '' ?', "doubled "" ?", 'backslash\\' FROM t
/* ? :radius */ WHERE price > ? -- ?
AND id = ? # ?
SQL;
        [$compiled, $bindings] = NumericBindings::prepare($sql, [1.25, 42]);
        $this->assertSame(str_replace('price > ?', 'price > 1.25', $sql), $compiled);
        $this->assertSame([42], $bindings);
    }

    public function testNamedFloatParametersCanRepeatWithoutChangingOtherNamedBindings(): void
    {
        $sql = "SELECT ':radius', `:radius` FROM t WHERE price > :radius AND ratio < :radius AND title = :title";
        $this->assertSame([
            "SELECT ':radius', `:radius` FROM t WHERE price > 1.5 AND ratio < 1.5 AND title = :title",
            [':title' => "O'Reilly"],
        ], NumericBindings::prepare($sql, [':radius' => 1.5, ':title' => "O'Reilly"]));
    }

    public function testNumericStringsRemainStringsAndNoFloatQueriesAreUntouched(): void
    {
        $this->assertSame(['SELECT ? FROM t WHERE id = ?', ['12.75', 1]], NumericBindings::prepare('SELECT ? FROM t WHERE id = ?', ['12.75', 1]));
        $this->assertSame(['SELECT ? FROM t WHERE price > 2.5', ['12.75']], NumericBindings::prepare('SELECT ? FROM t WHERE price > ?', ['12.75', 2.5]));
    }

    public function testNonFiniteValuesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        NumericBindings::prepare('SELECT * FROM t WHERE price > ?', [INF]);
    }
}
