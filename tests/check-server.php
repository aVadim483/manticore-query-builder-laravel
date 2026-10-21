<?php

declare(strict_types=1);

// Preflight for CI and local compatibility runs; no application or table is required.
$minimum = '28.6.6';
$expected = getenv('MANTICORE_TEST_EXPECTED_VERSION') ?: null;
$host = getenv('MANTICORE_TEST_HOST') ?: '127.0.0.1';
$port = (int)(getenv('MANTICORE_TEST_PORT') ?: 9306);
$lastError = 'The server did not become ready.';
for ($attempt = 0; $attempt < 60; $attempt++) {
    try {
        $pdo = new PDO("mysql:host={$host};port={$port}", '', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 1,
        ]);
        $status = $pdo->query('SHOW STATUS')->fetchAll(PDO::FETCH_KEY_PAIR);
        $pdo = null;
        $version = $status['version'] ?? '';
        if (!preg_match('/^(\d+\.\d+\.\d+)\b/', $version, $matches)) {
            throw new RuntimeException('Cannot identify the Manticore version: '.$version);
        }
        if (version_compare($matches[1], $minimum, '<')) {
            throw new RuntimeException("Manticore {$minimum}+ is required; found {$version}");
        }
        if ($expected !== null && $matches[1] !== $expected) {
            throw new RuntimeException("Expected Manticore {$expected}; found {$version}");
        }
        if (preg_match('/\(knn \d+\./', $version)
            && preg_match('/\(buddy v\d+\./', $version)) {
            $report = json_encode([
                'minimum' => $minimum, 'expected' => $expected,
                'image' => getenv('MANTICORE_TEST_IMAGE') ?: null,
                'version' => $version,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
            echo $report;
            $path = getenv('MANTICORE_TEST_SERVER_REPORT');
            if ($path && file_put_contents($path, $report) === false) {
                throw new RuntimeException('Cannot write server report: '.$path);
            }
            exit(0);
        }
        $lastError = 'Buddy or KNN is not ready: '.$version;
    } catch (PDOException $error) {
        $lastError = $error->getMessage();
    }
    sleep(1);
}
throw new RuntimeException($lastError);
