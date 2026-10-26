<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use avadim\Manticore\Laravel\Database\ManticoreGrammar;

class DatabaseDriverInsertFloatIntegrationTest extends DatabaseTestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('database.connections.manticore', [
            'driver'=>'manticore','host'=>$this->serverHost(),'port'=>$this->serverPort(),'timeout'=>3,
        ]);
    }

    public function testFloatWritesPreserveDataAndExposeCompiledNumbersWithoutFloatBindings(): void
    {
        $this->requiresServer();
        $db = DB::connection('manticore');
        $name = $this->tableName('floatinsert');
        $schema = $db->getSchemaBuilder();
        $events = [];
        DB::listen(static function (QueryExecuted $event) use (&$events) {
            if ($event->connectionName === 'manticore') $events[] = [$event->sql,$event->bindings];
        });
        try {
            $schema->create($name, static function ($table) {
                $table->text('title'); $table->float('price'); $table->json('details');
                $table->multi('tags'); $table->floatVector('embedding',3,'l2');
            });
            $row = ['id'=>42,'title'=>"Phone ? :price 'quoted'",'price'=>55.3,
                'details'=>['fraction'=>0.125],'tags'=>[1,2],'embedding'=>[1.25,2.5,3.75]];
            foreach (['insert','insertGetId','replace','replaceGetId'] as $method) {
                $db->table($name)->where('id',42)->delete();
                $events = [];
                $db->table($name)->{$method}($row);
                $write = array_values(array_filter($events, static fn ($event)=>preg_match('/^(?:insert|replace) /i',$event[0])))[0];
                $this->assertStringContainsString('55.3', $write[0]);
                $this->assertSame([], array_values(array_filter($write[1], 'is_float')));
                $this->assertContains("Phone ? :price 'quoted'", $write[1]);
                $stored = $db->table($name)->find(42);
                $this->assertEqualsWithDelta(55.3, $stored->price, 0.00001);
                $this->assertSame($row['details'], $stored->details);
                $this->assertSame($row['tags'], $stored->tags);
                $this->assertSame($row['embedding'], $stored->embedding);
            }
            $db->table($name)->where('id',42)->update(['price'=>12.75]);
            $this->assertSame(12.75, $db->table($name)->find(42)->price);
            $this->assertTrue($db->table($name)->where('price','>',12.5)->exists());
            $db->statement('UPDATE `'.$name.'` SET price=? WHERE id=?',[55.3,42]);
            $this->assertEqualsWithDelta(55.3, $db->table($name)->find(42)->price, 0.00001);
        } finally {
            $schema->dropIfExists($name);
        }
    }

    public function testBatchWritesMatchGenericSqlEventsAndStoredStructuredValues(): void
    {
        $this->requiresServer();
        $db = DB::connection('manticore');
        $name = $this->tableName('batchfloat');
        $schema = $db->getSchemaBuilder();
        $originalGrammar = $db->getQueryGrammar();
        $events = [];
        DB::listen(static function (QueryExecuted $event) use (&$events) {
            if ($event->connectionName === 'manticore' && preg_match('/^(insert|replace) /i', $event->sql)) {
                $events[] = [$event->sql, $event->bindings];
            }
        });
        try {
            $schema->create($name, static function ($table) {
                $table->text('title'); $table->float('price'); $table->json('details');
                $table->multi('tags'); $table->floatVector('embedding', 3, 'l2');
            });
            $rows = [
                ['id'=>9007199254740993,'title'=>"Телефон ? :price 'quoted'",'price'=>55.3,
                    'details'=>['fraction'=>0.125],'tags'=>[1,2],'embedding'=>[1.25,2.5,3.75]],
                ['embedding'=>[0.125,1.0,2.0],'tags'=>[],'details'=>['scalar'=>7.25],'price'=>-0.0,'title'=>'Emoji 📦','id'=>42],
            ];
            foreach (['insert','replace'] as $method) {
                $results = [];
                foreach ([false, true] as $fallback) {
                    $db->setQueryGrammar($fallback ? new class($db) extends ManticoreGrammar {} : $originalGrammar);
                    $db->statement('TRUNCATE TABLE '.$db->getQueryGrammar()->wrapTable($name));
                    if ($method === 'replace') {
                        $db->table($name)->insert([['id'=>42,'title'=>'old'],['id'=>9007199254740993,'title'=>'old']]);
                    }
                    $db->forgetSchema();
                    $events = [];
                    $this->assertTrue($db->table($name)->{$method}($rows));
                    $this->assertCount(1, $events);
                    $this->assertSame([], array_values(array_filter($events[0][1], 'is_float')));
                    $results[] = [$events[0], $db->table($name)->orderBy('id')->get()->all()];
                }
                $this->assertEquals($results[1], $results[0]);
                $stored = $results[0][1];
                $this->assertSame(42, $stored[0]->id);
                $this->assertSame(['scalar'=>7.25], $stored[0]->details);
                $this->assertSame([], $stored[0]->tags);
                $this->assertSame(9007199254740993, $stored[1]->id);
                $this->assertEqualsWithDelta(55.3, $stored[1]->price, 0.00001);
                $this->assertSame([1.25,2.5,3.75], $stored[1]->embedding);
            }
        } finally {
            $db->setQueryGrammar($originalGrammar);
            $schema->dropIfExists($name);
        }
    }
}
