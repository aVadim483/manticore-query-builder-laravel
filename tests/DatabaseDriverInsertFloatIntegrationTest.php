<?php

declare(strict_types=1);

namespace avadim\Manticore\Laravel\Tests;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

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
}
