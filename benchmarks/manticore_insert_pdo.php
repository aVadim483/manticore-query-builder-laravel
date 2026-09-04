<?php

/**
 * Замер массовой вставки в Manticore на голом PDO (MySQL-протокол, 9306).
 *
 * Это опорная точка для остальных трёх скриптов: столько времени стоит вставка,
 * когда между PHP и демоном нет ничего, кроме драйвера.
 *
 * Запуск:
 *   php benchmarks/manticore_insert_pdo.php [документов] [размер пачки]
 *   php benchmarks/manticore_insert_pdo.php 200000 500
 */

declare(strict_types=1);

require __DIR__.'/shared.php';

$docsLimit = (int) ($argv[1] ?? 200000);
$batchSize = (int) ($argv[2] ?? 500);
$table = 'bench_pdo';

benchHeader('голый PDO (MySQL-протокол)', "таблица {$table}, документов {$docsLimit}, пачка {$batchSize}");

$documents = benchDocuments($docsLimit);
benchLine('документов сгенерировано', (string) count($documents));

$pdo = benchPdo();

$pdo->exec("DROP TABLE IF EXISTS {$table}");
$pdo->exec(benchCreateTableSql($table));

$columnList = implode(', ', benchColumns());

$prepareTime = 0.0;
$exchangeTime = 0.0;
$batches = 0;

$totalStart = microtime(true);

foreach (array_chunk($documents, $batchSize) as $batch) {
    $prepareStart = microtime(true);

    $values = [];

    foreach ($batch as $document) {
        $values[] = sprintf(
            '(%d, %d, %s, %s, %s, %d, %F, %d, %d, %d, %d)',
            $document['id'],
            $document['title_id'],
            $pdo->quote($document['name']),
            $pdo->quote($document['normalized']),
            $pdo->quote($document['type']),
            $document['year'],
            $document['popularity'],
            $document['num_votes'],
            $document['source_mask'],
            (int) $document['has_tmdb'],
            (int) $document['is_primary'],
        );
    }

    $sql = sprintf('REPLACE INTO %s (%s) VALUES %s', $table, $columnList, implode(', ', $values));

    $prepareTime += microtime(true) - $prepareStart;

    $exchangeStart = microtime(true);
    $pdo->exec($sql);
    $exchangeTime += microtime(true) - $exchangeStart;

    $batches++;
}

$totalTime = microtime(true) - $totalStart;

$indexed = (int) $pdo->query("SELECT COUNT(*) AS c FROM {$table}")->fetch(PDO::FETCH_ASSOC)['c'];

benchInsertReport('голый PDO', count($documents), $batches, $indexed, $prepareTime, $exchangeTime, $totalTime);

$pdo->exec("DROP TABLE IF EXISTS {$table}");
