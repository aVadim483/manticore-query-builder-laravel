<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use Illuminate\Support\Facades\DB;

/** Baseline protocol contract, independent of the Schema implementation. */
class DatabaseDriverAlterProtocolIntegrationTest extends DatabaseTestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('database.connections.manticore', [
            'driver' => 'manticore', 'host' => $this->serverHost(), 'port' => $this->serverPort(),
        ]);
    }

    public function testAlterPreservesDataAndReportsNormalizedSettings(): void
    {
        $this->requiresServer();
        $db = DB::connection('manticore');
        $name = $this->tableName('alter_protocol');
        $table = $db->getQueryGrammar()->wrapTable($name);
        try {
            $db->statement("CREATE TABLE $table (title text, qty int) min_infix_len='2'");
            $db->insert("INSERT INTO $table (id, title, qty) VALUES (?, ?, ?)", [1, 'running', 4294967295]);
            $db->statement("ALTER TABLE $table MODIFY COLUMN qty bigint");
            $db->statement("ALTER TABLE $table min_infix_len='3' morphology='stem_en'");
            $columns = $db->select("DESCRIBE $table");
            $this->assertSame('bigint', collect($columns)->firstWhere('Field', 'qty')->Type);
            $this->assertSame(4294967295, (int)$db->selectOne("SELECT qty FROM $table WHERE id=1")->qty);
            $settings = $db->select("SHOW TABLE $table SETTINGS");
            $this->assertStringContainsString('min_infix_len = 3', $settings[0]->Value);
            $this->assertStringContainsString('morphology = stem_en', $settings[0]->Value);
        } finally {
            $db->statement("DROP TABLE IF EXISTS $table");
        }
    }
}
