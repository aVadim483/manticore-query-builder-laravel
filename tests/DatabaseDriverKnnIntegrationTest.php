<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseDriverKnnIntegrationTest extends DatabaseTestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('database.connections.manticore', [
            'driver' => 'manticore', 'host' => $this->serverHost(), 'port' => $this->serverPort(), 'timeout' => 3,
        ]);
    }

    public function testKnnReturnsNearestFilteredDocumentsAndDistances(): void
    {
        $this->requiresServer();
        $table = $this->tableName('knn');
        $schema = Schema::connection('manticore');
        try {
            $schema->create($table, function ($blueprint) {
                $blueprint->text('title');
                $blueprint->integer('stock');
                $blueprint->floatVector('embedding', 3, 'l2');
            });
            $db = DB::connection('manticore');
            $db->table($table)->insert([
                ['id' => 1, 'title' => 'galaxy phone', 'stock' => 1, 'embedding' => [1, 0, 0]],
                ['id' => 2, 'title' => 'galaxy tablet', 'stock' => 1, 'embedding' => [2, 0, 0]],
                ['id' => 3, 'title' => 'apple phone', 'stock' => 0, 'embedding' => [9, 0, 0]],
            ]);
            foreach ([false, true] as $disk) {
                if ($disk) {
                    $db->statement('FLUSH RAMCHUNK `'.$table.'`');
                }
                $query = $db->table($table)->knn('embedding', [1, 0, 0], 2, ['ef' => 100])
                    ->withKnnDistance()->select('id')->match('galaxy')->where('stock', 1)->orderByKnnDistance();
                $result = $query->search();
                $this->assertSame([1, 2], $result->rows()->pluck('id')->all());
                $this->assertEqualsWithDelta(0, $result->rows()[0]->_knn_distance, 0.001);
                $this->assertGreaterThan(0, $result->rows()[1]->_knn_distance);
                $this->assertSame(2, $result->meta()->totalFound());
                $this->assertSame([1, 2], $query->cursor()->pluck('id')->all());
            }
        } finally {
            $schema->dropIfExists($table);
        }
    }
}
