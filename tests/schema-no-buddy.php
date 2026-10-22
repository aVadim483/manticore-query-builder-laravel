<?php

declare(strict_types=1);

// Run separately against an RT-mode server explicitly started with buddy_path empty.
require $argv[1] ?? dirname(__DIR__).'/vendor/autoload.php';

use avadim\Manticore\Laravel\Database\ManticoreConnection;
use avadim\Manticore\Laravel\Database\ManticoreConnector;
use Illuminate\Database\QueryException;

$config = ['driver' => 'manticore', 'host' => getenv('MANTICORE_TEST_HOST') ?: '127.0.0.1',
    'port' => (int)(getenv('MANTICORE_TEST_PORT') ?: 9306), 'timeout' => 3];
$db = new ManticoreConnection(fn () => (new ManticoreConnector())->connect($config), '', '', $config);
$version = $db->selectOne("SHOW STATUS LIKE 'version'")->Value;
if (str_contains($version, '(buddy v')) {
    throw new RuntimeException('This test requires a server with Buddy disabled.');
}
$table = 'phpunit_'.bin2hex(random_bytes(8)).'_no_buddy';
$schema = $db->getSchemaBuilder();
try {
    $schema->create($table, fn ($blueprint) => $blueprint->text('title'));
    $db->table($table)->insert(['id' => 1, 'title' => 'preserved']);
    foreach (['autocomplete', 'fuzzy'] as $feature) {
        $evidence = $db->probeCapability($feature, $table);
        if ($evidence->supported || !$evidence->cause instanceof \avadim\Manticore\Laravel\Database\UnsupportedFeatureException) {
            throw new RuntimeException('Missing Buddy was not identified for '.$feature);
        }
    }
    try {
        $schema->rename($table, $table.'_renamed');
        throw new RuntimeException('RENAME unexpectedly succeeded without Buddy.');
    } catch (QueryException $error) {
        if (!str_contains($error->getSql(), 'rename')) {
            throw $error;
        }
    }
    if (!$schema->hasTable($table) || $schema->hasTable($table.'_renamed')
        || $db->table($table)->find(1)->title !== 'preserved') {
        throw new RuntimeException('Failed RENAME changed the table or its data.');
    }
    echo "OK: RENAME without Buddy raises QueryException and preserves data ($version)".PHP_EOL;
} finally {
    $schema->dropIfExists($table);
    $schema->dropIfExists($table.'_renamed');
}
