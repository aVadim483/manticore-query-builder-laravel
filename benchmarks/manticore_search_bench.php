<?php

/**
 * Замер чтения: цена одного поискового запроса у пяти клиентов.
 *
 * Вставка нагружает сериализацию, но почти не показывает цену «запрос-ответ»,
 * а именно там протоколы и расходятся: у HTTP на каждый запрос свои заголовки,
 * у MySQL-протокола — свои. Здесь запросы короткие и частые, то есть накладные
 * расходы видны в чистом виде.
 *
 * Клиенты:
 *   PDO           — голый PDO, 9306
 *   QB            — avadim/manticore-query-builder-php, 9306, отдаёт массивы
 *   QB+Laravel    — тот же билдер с Connection обёртки: Collection из Row
 *   HTTP JSON     — официальный клиент, /search, 9308
 *   HTTP SQL      — официальный клиент, /sql?mode=raw, 9308
 *
 * Три профиля запроса, потому что на разных профилях побеждают разные вещи:
 *   точечный      — MATCH + LIMIT 20: сервер почти не работает, видны накладные
 *                   расходы клиента и протокола;
 *   ранжирование  — то же плюс ORDER BY popularity DESC: сервер работает, и
 *                   доля накладных расходов падает;
 *   объём         — MATCH + LIMIT 1000: главная работа — разобрать ответ и
 *                   собрать из него объекты.
 *
 * Запуск:
 *   php benchmarks/manticore_search_bench.php [итераций] [документов в индексе]
 *   php benchmarks/manticore_search_bench.php 2000 200000
 */

declare(strict_types=1);

require __DIR__.'/shared.php';

use avadim\Manticore\QueryBuilder\Builder as ManticoreDb;

$iterations = (int) ($argv[1] ?? 2000);
$indexSize = (int) ($argv[2] ?? 200000);
$table = 'bench_search';
$warmup = 50;

benchHeader('чтение, пять клиентов', "таблица {$table}, документов {$indexSize}, итераций {$iterations}");

// --- индекс ----------------------------------------------------------------

$pdo = benchPdo();
$pdo->exec("DROP TABLE IF EXISTS {$table}");
$pdo->exec(benchCreateTableSql($table));

$documents = benchDocuments($indexSize);
$columnList = implode(', ', benchColumns());

$fillStart = microtime(true);

foreach (array_chunk($documents, 1000) as $batch) {
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

    $pdo->exec(sprintf('REPLACE INTO %s (%s) VALUES %s', $table, $columnList, implode(', ', $values)));
}

unset($documents);

benchLine('индекс наполнен за', number_format(microtime(true) - $fillStart, 1).' с');
benchLine('документов в индексе', (string) (int) $pdo->query("SELECT COUNT(*) AS c FROM {$table}")->fetch(PDO::FETCH_ASSOC)['c']);

// --- профили запроса -------------------------------------------------------

$profiles = [
    'точечный' => ['order' => null, 'limit' => 20, 'iterations' => $iterations],
    'ранжирование' => ['order' => 'popularity', 'limit' => 20, 'iterations' => $iterations],
    'объём' => ['order' => null, 'limit' => 1000, 'iterations' => max(50, (int) ($iterations / 5))],
];

$terms = benchSearchTerms();
$termCount = count($terms);

// --- клиенты ---------------------------------------------------------------

$client = benchHttpClient();

/**
 * Каждый клиент — замыкание (термин, профиль) => число найденных строк.
 * Возврат количества нужен не для отчёта, а как контроль: если клиенты
 * находят разное, они делают разную работу и сравнивать их нельзя.
 *
 * @var array<string, callable(string, array): int> $clients
 */
$clients = [];

$clients['PDO'] = static function (string $term, array $profile) use ($pdo, $table): int {
    $sql = sprintf(
        "SELECT * FROM %s WHERE MATCH('%s')%s LIMIT %d",
        $table,
        $term,
        $profile['order'] ? ' ORDER BY '.$profile['order'].' DESC' : '',
        $profile['limit'],
    );

    $stm = $pdo->prepare($sql);
    $stm->execute();

    return count($stm->fetchAll(PDO::FETCH_ASSOC));
};

$clients['HTTP JSON'] = static function (string $term, array $profile) use ($client, $table): int {
    $body = [
        'table' => $table,
        'query' => ['match' => ['*' => $term]],
        'limit' => $profile['limit'],
    ];

    if ($profile['order']) {
        $body['sort'] = [[$profile['order'] => 'desc']];
    }

    $response = $client->search(['body' => $body]);

    return count($response['hits']['hits']);
};

$clients['HTTP SQL'] = static function (string $term, array $profile) use ($client, $table): int {
    $sql = sprintf(
        "SELECT * FROM %s WHERE MATCH('%s')%s LIMIT %d",
        $table,
        $term,
        $profile['order'] ? ' ORDER BY '.$profile['order'].' DESC' : '',
        $profile['limit'],
    );

    return count($client->sql($sql, true));
};

// Билдер и обёртка используют один и тот же статический Builder, поэтому
// переключаются классом соединения — ровно как это делает ServiceProvider.
// Само переключение вынесено из замера: оно сбрасывает пул соединений.
$clients['QB'] = static function (string $term, array $profile) use ($table): int {
    $query = ManticoreDb::table($table)->match($term);

    if ($profile['order']) {
        $query->orderByDesc($profile['order']);
    }

    return count($query->limit($profile['limit'])->get());
};

$clients['QB+Laravel'] = static function (string $term, array $profile) use ($table): int {
    $query = ManticoreDb::table($table)->match($term);

    if ($profile['order']) {
        $query->orderByDesc($profile['order']);
    }

    return $query->limit($profile['limit'])->get()->count();
};

// --- прогон ----------------------------------------------------------------

/**
 * Клиенты чередуются раундами, а не идут блоками подряд.
 *
 * Блоками мерить нельзя: фоновая нагрузка машины дрейфует, и клиент, которому
 * достался спокойный отрезок, выигрывает ни за что. Раундами тот же дрейф
 * ложится на всех одинаково.
 *
 * Почему не чередовать каждый запрос: у обоих билдерных клиентов один общий
 * статический Builder, и переключение класса соединения сбрасывает пул. На
 * каждой итерации это означало бы пересоздание PDO-соединения — мерился бы
 * коннект, а не запрос. Раунд достаточно велик, чтобы такое переключение
 * растворилось, и достаточно мал, чтобы дрейф размазался.
 */
$rounds = 5;
$results = [];

foreach ($profiles as $profileName => $profile) {
    $perRound = max(1, (int) ceil($profile['iterations'] / $rounds));
    $latencies = [];
    $rowsSeen = [];

    for ($round = 0; $round < $rounds; $round++) {
        foreach ($clients as $clientName => $call) {
            if (str_starts_with($clientName, 'QB')) {
                benchBuilderInit($clientName === 'QB+Laravel'
                    ? \avadim\Manticore\Laravel\Connection::class
                    : \avadim\Manticore\QueryBuilder\Connection::class);
            }

            // Прогрев в каждом раунде: после переключения билдера соединение
            // новое, и первый запрос по нему считал бы коннект.
            for ($i = 0; $i < $warmup; $i++) {
                $call($terms[$i % $termCount], $profile);
            }

            for ($i = 0; $i < $perRound; $i++) {
                $term = $terms[($round * $perRound + $i) % $termCount];

                $start = microtime(true);
                $rows = $call($term, $profile);
                $latencies[$clientName][] = microtime(true) - $start;

                $rowsSeen[$clientName] = ($rowsSeen[$clientName] ?? 0) + $rows;
            }
        }
    }

    foreach ($latencies as $clientName => $samples) {
        $stats = benchStats($samples);

        $results[$profileName][$clientName] = [
            'median' => $stats['median'],
            'p95' => $stats['p95'],
            'avg' => $stats['avg'],
            'rows' => $rowsSeen[$clientName],
            'qps' => $stats['avg'] > 0 ? 1 / $stats['avg'] : 0,
        ];
    }
}

// --- серверное время -------------------------------------------------------

/**
 * Сколько из задержки приходится на сам демон.
 *
 * Берётся только поле took из HTTP-ответа. У SQL сопоставимой метрики нет:
 * SHOW META отдаёт время поиска и не считает формирование ответа, поэтому на
 * профиле «объём» показывает сотые доли миллисекунды там, где took даёт
 * десятки. Сравнивать эти два числа между собой нельзя, и здесь приводится
 * одно — как оценка того, от какой базы вообще считается клиентская разница.
 */
$serverTimes = [];

foreach ($profiles as $profileName => $profile) {
    $samples = [];

    for ($i = 0; $i < 200; $i++) {
        $body = [
            'table' => $table,
            'query' => ['match' => ['*' => $terms[$i % $termCount]]],
            'limit' => $profile['limit'],
        ];

        if ($profile['order']) {
            $body['sort'] = [[$profile['order'] => 'desc']];
        }

        $samples[] = (float) $client->search(['body' => $body])['took'];
    }

    $serverTimes[$profileName] = benchStats($samples);
}

// --- отчёт -----------------------------------------------------------------

foreach ($results as $profileName => $clientResults) {
    echo PHP_EOL, "Профиль «{$profileName}» — ", $profiles[$profileName]['iterations'], ' запросов', PHP_EOL;
    printf("  %-12s %12s %12s %12s %10s%s", 'клиент', 'медиана, мс', 'p95, мс', 'средняя, мс', 'запр./с', PHP_EOL);
    echo '  ', str_repeat('-', 62), PHP_EOL;

    foreach ($clientResults as $clientName => $row) {
        printf(
            "  %-12s %12s %12s %12s %10s%s",
            $clientName,
            number_format($row['median'] * 1000, 3),
            number_format($row['p95'] * 1000, 3),
            number_format($row['avg'] * 1000, 3),
            number_format($row['qps'], 0),
            PHP_EOL,
        );
    }

    printf(
        "  из них демон (took, медиана): %s мс%s",
        number_format($serverTimes[$profileName]['median'], 1),
        PHP_EOL,
    );
}

// Контроль: все клиенты должны находить одинаковое число строк на профиль.
echo PHP_EOL, 'Контроль — суммарно найдено строк (числа обязаны совпадать):', PHP_EOL;

foreach ($results as $profileName => $clientResults) {
    $counts = [];

    foreach ($clientResults as $clientName => $row) {
        $counts[] = $clientName.'='.$row['rows'];
    }

    echo '  ', $profileName, ': ', implode(', ', $counts), PHP_EOL;
}

echo PHP_EOL;

$pdo->exec("DROP TABLE IF EXISTS {$table}");
