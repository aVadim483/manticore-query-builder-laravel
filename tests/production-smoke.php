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
        });
        $id = $db->table($table)->insertGetId([
            'id' => 42, 'title' => 'galaxy phone', 'details' => ['brand' => 'acme'],
            'tags' => [1, 2], 'price' => 12.5,
        ]);
        $result = $db->table($table)->match('galaxy')->search();
        $row = $result->rows()->sole();
        if ((string)$id !== '42' || $row->details !== ['brand' => 'acme']
            || $row->tags !== [1, 2] || $result->meta()->totalFound() !== 1) {
            throw new RuntimeException('Production CRUD, casts or search metadata failed.');
        }
        $db->transaction(fn () => $db->table($table)->where('id', 42)->update(['price' => 25.5]));
        if ((float)$db->table($table)->find(42)->price !== 25.5) {
            throw new RuntimeException('Production transaction failed.');
        }
    } finally {
        $schema->dropIfExists($table);
    }
}

echo 'OK: production-only native driver, Illuminate '.InstalledVersions::getPrettyVersion('illuminate/database').PHP_EOL;
