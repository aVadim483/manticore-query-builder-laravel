<?php

declare(strict_types=1);

// Run in a separate process against a consumer's --no-dev Composer installation.
require $argv[1] ?? dirname(__DIR__).'/vendor/autoload.php';

use avadim\Manticore\Laravel\Database\ManticoreBlueprint;
use avadim\Manticore\Laravel\Database\ManticoreConnection;
use avadim\Manticore\Laravel\ServiceProvider;
use Composer\InstalledVersions;
use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;

if (InstalledVersions::isInstalled('avadim/manticore-query-builder-php')) {
    throw new RuntimeException('The consumer still installs the standalone PHP builder.');
}
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'avadim\\Manticore\\QueryBuilder\\')) {
        throw new LogicException('A legacy class was requested: '.$class);
    }
}, true, true);

$app = new Container();
$app->instance('config', new ArrayObject([
    'database.default' => 'search',
    'database.connections' => ['search' => [
        'driver' => 'manticore',
        'host' => getenv('MANTICORE_TEST_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('MANTICORE_TEST_PORT') ?: 9306),
        'timeout' => 3,
    ]],
]));
$app->singleton('db', static fn ($app) => new DatabaseManager($app, new ConnectionFactory($app)));
(new ServiceProvider($app))->register();
$db = $app->make('db')->connection('search');
if (!$db instanceof ManticoreConnection || !$db->getRawPdo() instanceof Closure) {
    throw new RuntimeException('The provider did not register a lazy native connection.');
}
$log = $db->pretend(fn () => $db->table('products')->select('id')->match('galaxy')->get());
if (count($log) !== 1 || $log[0]['bindings'] !== ['galaxy']) {
    throw new RuntimeException('Native query compilation failed.');
}
try {
    $db->table('products')->whereLike('title', '%phone%')->toSql();
    throw new RuntimeException('An unsupported where-method fell through to a column filter.');
} catch (LogicException $error) {
    // Also protects older Laravel patches that do not declare whereLike().
}

if (in_array('--live', $argv, true)) {
    $table = 'phpunit_'.bin2hex(random_bytes(8)).'_production';
    $schema = $db->getSchemaBuilder();
    try {
        $schema->create($table, static function (ManticoreBlueprint $blueprint) {
            $blueprint->id();
            $blueprint->text('title');
            $blueprint->json('details');
            $blueprint->multi('tags');
            $blueprint->float('price');
            $blueprint->integer('qty');
            $blueprint->floatVector('embedding', 3, 'l2');
        });
        $id = $db->table($table)->insertGetId([
            'id' => 42, 'title' => 'galaxy phone', 'details' => ['brand' => 'acme'],
            'tags' => [1, 2], 'price' => 12.5, 'embedding' => [1, 0, 0],
        ]);
        $result = $db->table($table)->match('galaxy')->search();
        $row = $result->rows()->sole();
        if ((string)$id !== '42' || $row->details !== ['brand' => 'acme']
            || $row->tags !== [1, 2] || $result->meta()->totalFound() !== 1) {
            throw new RuntimeException('Production CRUD, casts or search metadata failed.');
        }
        if ($db->table($table)->whereJsonDoesntOverlap('details->missing', ['x'])->whereFullText('title', 'galaxy')->count() !== 1
            || $db->table($table)->where('id', 0)->orWhereJsonDoesntOverlap('details->missing', ['x'])->count() !== 1
            || $db->table($table)->where('id', 42)->orWhereJsonOverlaps('details->missing', ['x'])->count() !== 1) {
            throw new RuntimeException('Production JSON/full-text aliases failed.');
        }
        $afterCalls = 0;
        $withCallback = $db->table($table)->where('id', 42)->afterQuery(function ($rows) use (&$afterCalls) {
            $afterCalls++;
        });
        if ($withCallback->get()->count() !== 1 || $afterCalls !== 1
            || $withCallback->search()->rows()->count() !== 1 || $afterCalls !== 2) {
            throw new RuntimeException('Production after-query callbacks did not execute exactly once.');
        }
        $db->transaction(fn () => $db->table($table)->replace([
            'id' => 42, 'title' => 'galaxy phone', 'details' => ['brand' => 'acme'],
            'tags' => [1, 2], 'price' => 25.5, 'embedding' => [1, 0, 0],
        ]));
        if ((float)$db->table($table)->find(42)->price !== 25.5) {
            throw new RuntimeException('Production transaction failed.');
        }
        $db->table($table)->updateOrInsert(['id' => 42], ['price' => 26.5]);
        $processed = $db->table($table)->upsert(['id' => 42, 'price' => 25.5], 'id', ['price']);
        $db->table($table)->replaceOrInsert(['id' => 43], ['title' => 'temporary', 'embedding' => [0, 1, 0]]);
        $db->table($table)->replaceOrInsert(['id' => 43], ['title' => 'temporary replacement', 'embedding' => [0, 1, 0]]);
        if ($processed !== 1 || $db->table($table)->find(42)->title !== 'galaxy phone'
            || (float)$db->table($table)->find(42)->price !== 25.5
            || $db->table($table)->find(43)->title !== 'temporary replacement') {
            throw new RuntimeException('Production conditional writes or transactional upsert failed.');
        }
        $db->table($table)->where('id', 43)->delete();
        $schema->table($table, static function (ManticoreBlueprint $blueprint) {
            $blueprint->bigInteger('qty')->change();
            $blueprint->setting('min_word_len', 2);
        });
        $schema->rename($table, $table.'_renamed');
        if ($schema->getColumnType($table.'_renamed', 'qty') !== 'bigint'
            || $schema->getSettings($table.'_renamed')['min_word_len'] !== '2'
            || (float)$db->table($table.'_renamed')->find(42)->price !== 25.5) {
            throw new RuntimeException('Production ALTER or RENAME failed.');
        }
        $schema->rename($table.'_renamed', $table);
        $hybrid = $db->table($table)->hybridSearch('galaxy', 'embedding', [1, 0, 0]);
        if ($hybrid->windowTotal() !== 1 || $hybrid->totalFound() !== null
            || $hybrid->hits()[0]->textRank !== 1 || $hybrid->hits()[0]->vectorRank !== 1
            || !$db->probeCapability('knn', $table, ['vector' => [1, 0, 0]])->supported) {
            throw new RuntimeException('Production hybrid or capability probe failed.');
        }
        $hybridRows = $db->table($table)->hybridGet('galaxy', 'embedding', [1, 0, 0]);
        $hybridFirst = $db->table($table)->hybridFirst('galaxy', 'embedding', [1, 0, 0]);
        if (!$hybridRows instanceof \Illuminate\Support\Collection || $hybridRows->count() !== 1
            || !$hybridRows->first() instanceof \stdClass || !$hybridFirst instanceof \stdClass
            || (array)$hybridRows->first() !== (array)$hybridFirst
            || $db->table($table)->where('id', 0)->hybridFirst('galaxy', 'embedding', [1, 0, 0]) !== null) {
            throw new RuntimeException('Production hybrid document return types failed.');
        }
        $schema->createLike($table.'_staged', $table);
        $db->table($table.'_staged')->insert(['id' => 42, 'title' => 'staged', 'embedding' => [1, 0, 0]]);
        $replacement = $schema->replacement($table, $table.'_staged', $table.'_backup');
        $replacement->switch(true); // These random, isolated tables have no application traffic.
        if ($db->table($table)->find(42)->title !== 'staged'
            || $db->table($table.'_backup')->find(42)->title !== 'galaxy phone') {
            throw new RuntimeException('Production index replacement failed.');
        }
        $replacement->rollback(true);
    } finally {
        $schema->dropIfExists($table);
        $schema->dropIfExists($table.'_renamed');
        $schema->dropIfExists($table.'_staged');
        $schema->dropIfExists($table.'_backup');
    }
}

echo 'OK: production-only native driver, Illuminate '.InstalledVersions::getPrettyVersion('illuminate/database').PHP_EOL;
