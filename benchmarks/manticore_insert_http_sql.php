<?php

/**
 * Замер массовой вставки официальным клиентом, но сырым SQL поверх HTTP (9308,
 * эндпоинт /sql?mode=raw).
 *
 * Смысл скрипта — вычитание. Текст запроса собирается тем же кодом, что и в
 * manticore_insert_pdo.php, значит подготовка у них одинаковая, и всё различие
 * итога приходится на транспорт: HTTP против MySQL-протокола. А различие с
 * manticore_insert_http_json.php при одном и том же HTTP приходится на формат.
 *
 * Запуск:
 *   php benchmarks/manticore_insert_http_sql.php [документов] [размер пачки]
 */

declare(strict_types=1);

require __DIR__.'/shared.php';

$docsLimit = (int) ($argv[1] ?? 200000);
$batchSize = (int) ($argv[2] ?? 500);
$table = 'bench_http_sql';

benchHeader('официальный клиент, SQL поверх HTTP', "таблица {$table}, документов {$docsLimit}, пачка {$batchSize}");

$documents = benchDocuments($docsLimit);
benchLine('документов сгенерировано', (string) count($documents));

$pdo = benchPdo();
$pdo->exec("DROP TABLE IF EXISTS {$table}");
$pdo->exec(benchCreateTableSql($table));

$client = benchHttpClient();

$columnList = implode(', ', benchColumns());

/**
 * Экранирование строки для SphinxQL.
 *
 * У PDO для этого есть quote(), у HTTP-клиента — ничего: он рассчитан на JSON.
 * Правила те же, что у MySQL, и на наших данных (буквы и пробелы) экранировать
 * нечего, но опустить шаг нельзя — тогда скрипт делал бы меньше работы, чем
 * PDO-вариант, и сравнение поехало бы.
 */
function benchQuote(string $value): string
{
    return "'".strtr($value, [
        "\\" => "\\\\",
        "'" => "\\'",
        '"' => '\\"',
        "\0" => '\\0',
        "\n" => '\\n',
        "\r" => '\\r',
        "\x1a" => '\\Z',
    ])."'";
}

$exchangeTime = 0.0;
$batches = 0;

$totalStart = microtime(true);

foreach (array_chunk($documents, $batchSize) as $batch) {
    $values = [];

    foreach ($batch as $document) {
        $values[] = sprintf(
            '(%d, %d, %s, %s, %s, %d, %F, %d, %d, %d, %d)',
            $document['id'],
            $document['title_id'],
            benchQuote($document['name']),
            benchQuote($document['normalized']),
            benchQuote($document['type']),
            $document['year'],
            $document['popularity'],
            $document['num_votes'],
            $document['source_mask'],
            (int) $document['has_tmdb'],
            (int) $document['is_primary'],
        );
    }

    $sql = sprintf('REPLACE INTO %s (%s) VALUES %s', $table, $columnList, implode(', ', $values));

    $client->sql($sql, true);

    $exchangeTime += (float) $client->getLastResponse()->getTime();

    $batches++;
}

$totalTime = microtime(true) - $totalStart;

$indexed = (int) $pdo->query("SELECT COUNT(*) AS c FROM {$table}")->fetch(PDO::FETCH_ASSOC)['c'];

benchInsertReport('официальный клиент, SQL', count($documents), $batches, $indexed, $totalTime - $exchangeTime, $exchangeTime, $totalTime);

$pdo->exec("DROP TABLE IF EXISTS {$table}");
