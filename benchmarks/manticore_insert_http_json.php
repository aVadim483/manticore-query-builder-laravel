<?php

/**
 * Замер массовой вставки официальным клиентом manticoresoftware/manticoresearch-php
 * в его штатном режиме: JSON-документы поверх HTTP (9308), эндпоинт /bulk.
 *
 * Парный к нему manticore_insert_http_sql.php шлёт тем же клиентом и по тому же
 * HTTP сырой SQL. Вдвоём они разделяют цену транспорта и цену формата: если
 * JSON и SQL поверх HTTP разойдутся — виноват формат, если оба одинаково
 * отстанут от PDO — виноват транспорт.
 *
 * Запуск:
 *   php benchmarks/manticore_insert_http_json.php [документов] [размер пачки]
 */

declare(strict_types=1);

require __DIR__.'/shared.php';

$docsLimit = (int) ($argv[1] ?? 200000);
$batchSize = (int) ($argv[2] ?? 500);
$table = 'bench_http_json';

benchHeader('официальный клиент, JSON поверх HTTP', "таблица {$table}, документов {$docsLimit}, пачка {$batchSize}");

$documents = benchDocuments($docsLimit);
benchLine('документов сгенерировано', (string) count($documents));

// Таблица создаётся тем же SQL, что и у остальных: сравнивать надо вставку,
// а не разные способы объявить схему.
$pdo = benchPdo();
$pdo->exec("DROP TABLE IF EXISTS {$table}");
$pdo->exec(benchCreateTableSql($table));

$client = benchHttpClient();

$exchangeTime = 0.0;
$batches = 0;

$totalStart = microtime(true);

foreach (array_chunk($documents, $batchSize) as $batch) {
    // Клиент сам разложит документы в тело bulk-запроса и сам его сериализует,
    // поэтому подготовку отдельно не засечь — ровно как у билдера. Чистое
    // время curl_exec отдаёт Response::getTime(), из него и считается «обмен».
    $client->table($table)->replaceDocuments($batch);

    $exchangeTime += (float) $client->getLastResponse()->getTime();

    $batches++;
}

$totalTime = microtime(true) - $totalStart;

$indexed = (int) $pdo->query("SELECT COUNT(*) AS c FROM {$table}")->fetch(PDO::FETCH_ASSOC)['c'];

benchInsertReport('официальный клиент, JSON', count($documents), $batches, $indexed, $totalTime - $exchangeTime, $exchangeTime, $totalTime);

$pdo->exec("DROP TABLE IF EXISTS {$table}");
