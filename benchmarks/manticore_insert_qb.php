<?php

/**
 * Массовый REPLACE нативным Laravel Query Builder.
 * Запуск: php benchmarks/manticore_insert_qb.php [документов] [размер пачки]
 */

declare(strict_types=1);

require __DIR__.'/shared.php';

$docsLimit = (int) ($argv[1] ?? 200000);
$batchSize = (int) ($argv[2] ?? 500);
if ($docsLimit < 1 || $batchSize < 1) {
    throw new InvalidArgumentException('Число документов и размер пачки должны быть положительными.');
}
$table = 'bench_native';
$db = benchNativeConnection();
benchHeader('нативный Laravel Query Builder', "таблица {$table}, документов {$docsLimit}, пачка {$batchSize}");
$documents = benchDocuments($docsLimit);

$db->statement("DROP TABLE IF EXISTS {$table}");
try {
    $db->statement(benchCreateTableSql($table));
    $db->getTableSchema($table); // Прогрев DESCRIBE вне измерения.
    $batches = 0;
    $start = microtime(true);
    foreach (array_chunk($documents, $batchSize) as $batch) {
        $db->table($table)->replace($batch);
        $batches++;
    }
    $total = microtime(true) - $start;
    $indexed = $db->table($table)->count();
    benchReport('native Laravel', [
        'документов' => count($documents),
        'пачек' => $batches,
        'в индексе' => $indexed,
        'итого, с' => round($total, 3),
        'док./с' => (int) round(count($documents) / max($total, 0.001)),
    ]);
    if ($indexed !== count($documents)) {
        throw new RuntimeException('Число записанных документов отличается от ожидаемого.');
    }
} finally {
    $db->statement("DROP TABLE IF EXISTS {$table}");
}
