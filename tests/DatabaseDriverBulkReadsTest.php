<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use avadim\Manticore\Laravel\Database\ManticoreConnection;

class DatabaseDriverBulkReadsTest extends DatabaseTestCase
{
    public function testDocumentIdNullPredicatesAreConstantsWithoutSchemaDiscovery(): void
    {
        $db = new ManticoreConnection(fn () => throw new \LogicException('Unexpected connection'));
        $this->assertSame('select * from `products` where 1 = 1', $db->table('products')->whereNotNull('id')->toSql());
        $this->assertSame('select * from `products` where 0 = 1', $db->table('products')->whereNull('id')->toSql());
        $this->assertStringContainsString('1 = 1', $db->table('products as p')->whereNotNull('p.id')->toSql());
        $this->assertStringContainsString('`payload`.`id` is null', $db->table('products')->whereNull('payload.id')->toSql());
    }
}
