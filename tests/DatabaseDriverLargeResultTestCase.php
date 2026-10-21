<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class DatabaseDriverLargeResultTestCase extends DatabaseTestCase
{
    protected ?string $walkTable = null;
    protected const DOCUMENT_COUNT = 2105;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('database.connections.manticore', [
            'driver' => 'manticore', 'host' => $this->serverHost(), 'port' => $this->serverPort(), 'timeout' => 5,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->requiresServer();
        $this->walkTable = $this->tableName('walk');
        Schema::connection('manticore')->create($this->walkTable, function ($table) {
            $table->text('title');
            $table->integer('category');
            $table->float('price');
            $table->json('payload');
        });
        $rows = [];
        for ($id = 1; $id <= self::DOCUMENT_COUNT; ++$id) {
            $rows[] = ['id' => $id, 'title' => 'galaxy phone', 'category' => $id % 7,
                'price' => $id / 4, 'payload' => ['position' => $id]];
        }
        DB::connection('manticore')->table($this->walkTable)->insert($rows);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->walkTable !== null) {
                Schema::connection('manticore')->dropIfExists($this->walkTable);
            }
        } finally {
            parent::tearDown();
        }
    }

    protected function walkQuery()
    {
        return DB::connection('manticore')->table($this->walkTable)->match('galaxy');
    }

    protected function selectLog(): array
    {
        return array_values(array_filter(DB::connection('manticore')->getQueryLog(),
            fn ($entry) => str_starts_with(strtolower($entry['query']), 'select ')));
    }
}
