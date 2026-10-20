<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Database\ManticoreConnection;
use InvalidArgumentException;

class DatabaseDriverRankingTest extends DatabaseTestCase
{
    public function testWeightsRankersAndStringsCompileWithoutConnecting(): void
    {
        $db = new ManticoreConnection(fn () => throw new \LogicException('Unexpected connection'));
        $query = $db->table('products')->match('galaxy')->fieldWeights(['title' => 10, 'body' => 2])
            ->ranker('expr', 'sum(lcs*user_weight)*1000+bm25')->option('comment', "a'\\b");
        $sql = $query->toSql();
        $this->assertStringContainsString('field_weights=(title=10, body=2)', $sql);
        $this->assertStringContainsString("ranker=expr('sum(lcs*user_weight)*1000+bm25')", $sql);
        $this->assertStringContainsString("comment='a\\'\\\\b'", $sql);
        $this->assertSame(['galaxy'], $query->getBindings());
        $this->assertStringContainsString('ranker=bm25', $query->option('ranker', 'bm25')->toSql());
        $this->assertStringNotContainsString('ranker=', $query->option('ranker')->toSql());
    }

    public function testInvalidRankingOptionsAreRejected(): void
    {
        $db = new ManticoreConnection(fn () => throw new \LogicException('Unexpected connection'));
        foreach ([['field_weights', []], ['field_weights', ['title' => -1]], ['field_weights', ['x);' => 2]],
            ['ranker', 'expr'], ['ranker', 'bm25; select 1'], ['other', ['x' => 2]], ['max_matches', INF]] as [$name, $value]) {
            try {
                $db->table('products')->option($name, $value);
                $this->fail('Invalid ranking option was accepted.');
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }
}
