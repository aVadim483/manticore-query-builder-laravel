<?php

/**
 * Общая часть замеров Manticore.
 *
 * Всё, что должно быть одинаковым у всех скриптов, живёт здесь: подключение
 * автозагрузчиков, адреса демона, схема таблицы, документы, поисковые слова и
 * печать результата. Разойдись скрипты хоть в одном — сравнивать было бы нечего.
 *
 * Документы генерируются, а не читаются из чужой базы: замер должен запускаться
 * в этом репозитории, без приложения и без MySQL. Генератор детерминированный
 * (фиксированное зерно), поэтому все клиенты получают побайтово одни и те же
 * документы, а повторные прогоны сравнимы между собой.
 */

declare(strict_types=1);

// --- автозагрузка ----------------------------------------------------------

require __DIR__.'/../vendor/autoload.php';

// --- адреса демона ---------------------------------------------------------

/**
 * Хост и порты берутся из тех же переменных, что и у тестов (phpunit.xml.dist),
 * плюс порт HTTP-интерфейса.
 *
 * @return array{host: string, sql_port: int, http_port: int}
 */
function benchConfig(): array
{
    return [
        'host' => getenv('MANTICORE_TEST_HOST') ?: '127.0.0.1',
        'sql_port' => (int) (getenv('MANTICORE_TEST_PORT') ?: 9306),
        'http_port' => (int) (getenv('MANTICORE_TEST_HTTP_PORT') ?: 9308),
    ];
}

// --- схема -----------------------------------------------------------------

/**
 * Колонки в том порядке, в каком их ждёт REPLACE.
 *
 * @return list<string>
 */
function benchColumns(): array
{
    return [
        'id', 'title_id', 'name', 'normalized', 'type',
        'year', 'popularity', 'num_votes', 'source_mask', 'has_tmdb', 'is_primary',
    ];
}

/**
 * Настройки таблицы: та же морфология и те же инфиксы, что у боевого индекса,
 * ради которого замер и затевался — иначе сервер получился бы неправдоподобно
 * лёгким и разница ушла бы целиком в клиент.
 *
 * @return array<string, string|int>
 */
function benchTableOptions(): array
{
    return [
        'morphology' => 'lemmatize_ru_all,lemmatize_en_all',
        'min_infix_len' => 3,
        'index_exact_words' => 1,
        'html_strip' => 0,
    ];
}

/**
 * Та же схема текстом — для клиентов, которые ходят голым SQL.
 */
function benchCreateTableSql(string $table): string
{
    $options = [];

    foreach (benchTableOptions() as $name => $value) {
        $options[] = $name."='".$value."'";
    }

    return sprintf(
        'CREATE TABLE %s ('.
        'title_id bigint, name text, normalized text, type string attribute, '.
        'year integer, popularity float, num_votes bigint, source_mask integer, '.
        'has_tmdb bool, is_primary bool'.
        ') %s',
        $table,
        implode(' ', $options),
    );
}

// --- документы -------------------------------------------------------------

/**
 * Словарь названий: русские и английские слова вперемешку, чтобы обе
 * лемматизации получили работу, а не простаивали.
 *
 * @return list<string>
 */
function benchVocabulary(): array
{
    return [
        'кот', 'собака', 'город', 'ночь', 'звезда', 'дорога', 'сердце', 'война',
        'мир', 'лес', 'река', 'дом', 'время', 'жизнь', 'смерть', 'любовь',
        'тайна', 'охота', 'побег', 'возвращение', 'последний', 'великий',
        'чёрный', 'белый', 'красный', 'золотой', 'потерянный', 'забытый',
        'далёкий', 'тихий', 'холодный', 'железный', 'северный', 'южный',
        'cat', 'dog', 'city', 'night', 'star', 'road', 'heart', 'war',
        'peace', 'forest', 'river', 'house', 'time', 'life', 'death', 'love',
        'secret', 'hunt', 'escape', 'return', 'last', 'great',
        'black', 'white', 'red', 'golden', 'lost', 'forgotten',
        'distant', 'silent', 'cold', 'iron', 'northern', 'southern',
    ];
}

/**
 * Слова для поисковых запросов. Берутся из того же словаря, поэтому каждый
 * запрос гарантированно что-то находит: пустая выдача мерила бы не то.
 *
 * @return list<string>
 */
function benchSearchTerms(): array
{
    return ['кот', 'город', 'звезда', 'война', 'тайна', 'побег', 'золотой', 'забытый',
        'city', 'star', 'war', 'secret', 'escape', 'golden', 'forgotten', 'silent'];
}

/**
 * Документы для вставки.
 *
 * Генерация детерминированная: mt_srand с постоянным зерном и MT_RAND_PHP,
 * чтобы результат не зависел ни от версии PHP, ни от порядка запуска.
 *
 * @return list<array<string, mixed>>
 */
function benchDocuments(int $count): array
{
    mt_srand(20260816, MT_RAND_PHP);

    $vocabulary = benchVocabulary();
    $vocabularySize = count($vocabulary) - 1;
    $types = ['movie', 'series', 'short', 'game', 'episode'];

    $documents = [];

    for ($i = 1; $i <= $count; $i++) {
        $words = [];
        $wordCount = mt_rand(2, 6);

        for ($w = 0; $w < $wordCount; $w++) {
            $words[] = $vocabulary[mt_rand(0, $vocabularySize)];
        }

        $name = implode(' ', $words);

        $documents[] = [
            'id' => $i,
            'title_id' => mt_rand(1, $count),
            'name' => $name,
            'normalized' => mb_strtolower($name),
            'type' => $types[mt_rand(0, 4)],
            'year' => mt_rand(1950, 2026),
            'popularity' => mt_rand(0, 100000) / 1000,
            'num_votes' => mt_rand(0, 2000000),
            'source_mask' => mt_rand(1, 15),
            'has_tmdb' => mt_rand(0, 1) === 1,
            'is_primary' => mt_rand(0, 1) === 1,
        ];
    }

    return $documents;
}

// --- клиенты ---------------------------------------------------------------

/**
 * PDO поверх MySQL-протокола. Постоянное соединение — ради симметрии с
 * официальным клиентом, у которого persistent включён по умолчанию.
 */
function benchPdo(): PDO
{
    $config = benchConfig();

    return new PDO(
        sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config['host'], $config['sql_port']),
        '',
        '',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_PERSISTENT => true,
        ],
    );
}

/**
 * Официальный клиент поверх HTTP.
 */
function benchHttpClient(): \Manticoresearch\Client
{
    $autoload = __DIR__.'/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('Установите HTTP-клиент: composer --working-dir=benchmarks install');
    }
    require_once $autoload;
    $config = benchConfig();

    return new \Manticoresearch\Client([
        'host' => $config['host'],
        'port' => $config['http_port'],
        'persistent' => true,
    ]);
}

/** Создаёт то же нативное соединение, что и провайдер Laravel, без приложения. */
function benchNativeConnection(): \avadim\Manticore\Laravel\Database\ManticoreConnection
{
    $server = benchConfig();
    $config = [
        'name' => 'manticore', 'driver' => 'manticore',
        'host' => $server['host'], 'port' => $server['sql_port'],
        'options' => [PDO::ATTR_PERSISTENT => true],
    ];
    return new \avadim\Manticore\Laravel\Database\ManticoreConnection(
        static fn () => (new \avadim\Manticore\Laravel\Database\ManticoreConnector())->connect($config),
        '', '', $config,
    );
}

// --- печать ----------------------------------------------------------------

function benchHeader(string $client, string $what): void
{
    echo str_repeat('=', 64), PHP_EOL;
    echo "Manticore: {$client}", PHP_EOL;
    echo $what, PHP_EOL;
    echo str_repeat('=', 64), PHP_EOL;
}

function benchLine(string $label, string $value): void
{
    printf("  %-24s %s%s", $label, $value, PHP_EOL);
}

/**
 * @param array<string, int|float|string> $rows
 */
function benchReport(string $client, array $rows): void
{
    echo PHP_EOL, "Результат — {$client}:", PHP_EOL;

    foreach ($rows as $label => $value) {
        if (is_string($value)) {
            $formatted = $value;
        } elseif (is_float($value)) {
            $formatted = number_format($value, 3, '.', ' ');
        } else {
            $formatted = number_format($value, 0, '.', ' ');
        }

        printf("  %-20s %s%s", $label, $formatted, PHP_EOL);
    }

    echo PHP_EOL;
}

/**
 * Единый отчёт для всех скриптов вставки, чтобы столбцы у четырёх клиентов
 * означали одно и то же.
 *
 * «обмен» — время от отправки запроса до получения ответа: сеть плюс сервер.
 * Отделить сервер от сети Manticore на записи не даёт: SHOW META после REPLACE
 * отвечает метаданными последнего поиска, а bulk-ответ поля took не содержит.
 * «подготовка» — всё остальное клиентское время: сборка SQL или массива
 * документов, сериализация, разбор ответа.
 */
function benchInsertReport(string $client, int $documents, int $batches, int $indexed, float $prepare, float $exchange, float $total): void
{
    benchReport($client, [
        'документов' => $documents,
        'пачек' => $batches,
        'в индексе' => $indexed,
        'подготовка, с' => round($prepare, 3),
        'обмен, с' => round($exchange, 3),
        'итого, с' => round($total, 3),
        'на документ, мкс' => round($total * 1000000 / max($documents, 1), 1),
        'док./с' => (int) round($documents / max($total, 0.001)),
    ]);

    if ($indexed !== $documents) {
        fwrite(STDERR, sprintf(
            "ВНИМАНИЕ: в индексе %d документов вместо %d — замер сравнивает разную работу\n",
            $indexed,
            $documents,
        ));
    }
}

/**
 * Медиана и p95 вместо средней: средняя маскирует разброс, а именно разброс
 * и отличает протоколы друг от друга на коротких запросах.
 *
 * @param list<float> $samples значения в секундах
 *
 * @return array{median: float, p95: float, avg: float, min: float, max: float}
 */
function benchStats(array $samples): array
{
    sort($samples);

    $count = count($samples);

    return [
        'median' => benchPercentile($samples, 0.5),
        'p95' => benchPercentile($samples, 0.95),
        'avg' => $count ? array_sum($samples) / $count : 0.0,
        'min' => $count ? $samples[0] : 0.0,
        'max' => $count ? $samples[$count - 1] : 0.0,
    ];
}

/**
 * @param list<float> $sorted отсортированные по возрастанию значения
 */
function benchPercentile(array $sorted, float $percentile): float
{
    $count = count($sorted);

    if ($count === 0) {
        return 0.0;
    }

    $index = (int) floor($percentile * ($count - 1));

    return $sorted[$index];
}

/**
 * Таблицы замера сносятся сразу: демон общий с другими проектами, оставлять
 * на нём мусор нельзя.
 */
function benchDropTable(string $table): void
{
    benchPdo()->exec("DROP TABLE IF EXISTS {$table}");
}
