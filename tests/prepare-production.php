<?php

declare(strict_types=1);

// Prepare an isolated consumer without the package's require-dev dependencies.
$major = $argv[1] ?? '13';
if (!in_array($major, ['11', '12', '13'], true)) {
    throw new InvalidArgumentException('Expected Laravel major 11, 12 or 13.');
}
$directory = dirname(__DIR__).'/build/production';
if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
    throw new RuntimeException('Cannot create the production consumer directory.');
}
$package = 'avadim/manticore-query-builder-laravel';
$manifest = [
    'name' => 'local/driver-production-smoke',
    'repositories' => [[
        'type' => 'path', 'url' => '../..',
        'options' => ['symlink' => true, 'versions' => [$package => 'dev-test']],
    ]],
    'require' => [$package => 'dev-test', 'illuminate/database' => '^'.$major.'.0'],
];
if (file_put_contents($directory.'/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL) === false) {
    throw new RuntimeException('Cannot write the production consumer manifest.');
}
