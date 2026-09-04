<?php

/**
 * Замер массовой вставки через avadim/manticore-query-builder-php
 * (MySQL-протокол, 9306) — то есть через клиента этого пакета.
 *
 * На записи обёртка для Laravel в дело не вступает: writes возвращают скаляры
 * и наследуются от билдера без изменений. Поэтому здесь достаточно самого
 * билдера, а обёртка меряется в manticore_search_bench.php, где она реально
 * добавляет работу.
 *
 * Запуск:
 *   php benchmarks/manticore_insert_qb.php [документов] [размер пачки]
 */

declare(strict_types=1);

require __DIR__.'/shared.php';

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;

$docsLimit = (int) ($argv[1] ?? 200000);
$batchSize = (int) ($argv[2] ?? 500);
$table = 'bench_qb';

benchHeader('query builder (MySQL-протокол)', "таблица {$table}, документов {$docsLimit}, пачка {$batchSize}");

$documents = benchDocuments($docsLimit);
benchLine('документов сгенерировано', (string) count($documents));

benchBuilderInit();

ManticoreDb::table($table)->dropIfExists();
ManticoreDb::table($table)->options(benchTableOptions())->create(benchTableFields());

// Билдер собирает SQL внутри replaceResultSet(), поэтому подготовку отдельно
// не засечь. Зато ResultSet::execTime() отдаёт время самого обращения к серверу
// — из него и получается «подготовка = итого минус обмен».
$exchangeTime = 0.0;
$batches = 0;

$totalStart = microtime(true);

foreach (array_chunk($documents, $batchSize) as $batch) {
    $result = ManticoreDb::table($table)->replaceResultSet($batch);

    $exchangeTime += (float) $result->execTime();

    $batches++;
}

$totalTime = microtime(true) - $totalStart;

$indexed = (int) ManticoreDb::table($table)->count();

benchInsertReport('query builder', count($documents), $batches, $indexed, $totalTime - $exchangeTime, $exchangeTime, $totalTime);

ManticoreDb::table($table)->dropIfExists();
