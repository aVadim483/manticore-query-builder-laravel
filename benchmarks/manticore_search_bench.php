<?php

/**
 * Поиск: PDO, нативный драйвер Laravel, официальный HTTP SQL и HTTP JSON.
 * Запуск: php benchmarks/manticore_search_bench.php [итераций] [документов]
 */

declare(strict_types=1);

require __DIR__.'/shared.php';

$iterations = (int) ($argv[1] ?? 2000);
$indexSize = (int) ($argv[2] ?? 200000);
if ($iterations < 1 || $indexSize < 1) {
    throw new InvalidArgumentException('Число запросов и документов должно быть положительным.');
}
$table = 'bench_search';
$pdo = benchPdo();
$db = benchNativeConnection();
$client = benchHttpClient();
$terms = benchSearchTerms();
$profiles = [
    'точечный' => ['order' => null, 'limit' => 20],
    'ранжирование' => ['order' => 'popularity', 'limit' => 20],
    'объём' => ['order' => null, 'limit' => 1000],
];

benchHeader('поиск, четыре клиента', "таблица {$table}, документов {$indexSize}, запросов {$iterations}");
$pdo->exec("DROP TABLE IF EXISTS {$table}");
try {
    $pdo->exec(benchCreateTableSql($table));
    foreach (array_chunk(benchDocuments($indexSize), 500) as $batch) {
        $db->table($table)->replace($batch);
    }

    $clients = [
        'PDO' => static function (string $term, array $profile) use ($pdo, $table): int {
            $order = $profile['order'] ? ' ORDER BY popularity DESC' : '';
            $statement = $pdo->prepare("SELECT * FROM {$table} WHERE MATCH(?){$order} LIMIT ".$profile['limit']);
            $statement->execute([$term]);
            return count($statement->fetchAll(PDO::FETCH_OBJ));
        },
        'Laravel' => static function (string $term, array $profile) use ($db, $table): int {
            $query = $db->table($table)->match($term)->limit($profile['limit']);
            if ($profile['order']) {
                $query->orderByDesc($profile['order']);
            }
            return $query->get()->count();
        },
        'HTTP SQL' => static function (string $term, array $profile) use ($client, $pdo, $table): int {
            $order = $profile['order'] ? ' ORDER BY popularity DESC' : '';
            $sql = "SELECT * FROM {$table} WHERE MATCH(".$pdo->quote($term)."){$order} LIMIT ".$profile['limit'];
            return count($client->sql($sql, true));
        },
        'HTTP JSON' => static function (string $term, array $profile) use ($client, $table): int {
            $body = ['table' => $table, 'query' => ['match' => ['*' => $term]], 'limit' => $profile['limit']];
            if ($profile['order']) {
                $body['sort'] = [[$profile['order'] => 'desc']];
            }
            return count($client->search(['body' => $body])['hits']['hits']);
        },
    ];

    foreach ($profiles as $profileName => $profile) {
        foreach ($clients as $call) {
            foreach ($terms as $term) {
                $call($term, $profile); // Прогрев соединений, схемы и поиска.
            }
        }
        $samples = array_fill_keys(array_keys($clients), []);
        $counts = array_fill_keys(array_keys($clients), 0);
        // Чередуем клиентов; начало каждого раунда меняется, чтобы снизить влияние порядка.
        $names = array_keys($clients);
        for ($i = 0; $i < $iterations; $i++) {
            $term = $terms[$i % count($terms)];
            foreach ($names as $name) {
                $start = microtime(true);
                $count = $clients[$name]($term, $profile);
                $samples[$name][] = microtime(true) - $start;
                $counts[$name] += $count;
            }
            $names[] = array_shift($names);
        }
        if (count(array_unique($counts)) !== 1) {
            throw new RuntimeException('Клиенты вернули разное число строк: '.json_encode($counts));
        }
        echo PHP_EOL, "Профиль: {$profileName}", PHP_EOL;
        foreach ($samples as $name => $latencies) {
            $stats = benchStats($latencies);
            benchReport($name, [
                'медиана, мс' => $stats['median'] * 1000,
                'p95, мс' => $stats['p95'] * 1000,
                'строк' => $counts[$name],
            ]);
        }
    }
} finally {
    $db->statement("DROP TABLE IF EXISTS {$table}");
}
