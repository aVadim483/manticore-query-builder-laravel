<?php

declare(strict_types=1);

// A separate process deliberately reading through the documented maintenance gap.
$pdo = new PDO('mysql:host='.$argv[1].';port='.$argv[2], '', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$table = '`'.str_replace('`', '``', $argv[3]).'`';
$observed = [];
$ready = false;
$deadline = microtime(true) + 15;
while (microtime(true) < $deadline) {
    clearstatcache(true, $argv[4]);
    if (is_file($argv[4])) { break; }
    try {
        $row = $pdo->query('SELECT title FROM '.$table.' WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        $observed[] = $row['title'] ?? 'empty';
    } catch (PDOException $error) {
        if (!preg_match('/(?:no such (?:index|table)|unknown (?:local )?(?:table|index)|(?:table|index).*?(?:not found|does not exist))/i', $error->getMessage())) {
            throw $error;
        }
        $observed[] = 'gap';
    }
    if (!$ready) {
        echo "READY\n";
        flush();
        $ready = true;
    }
    usleep(10000);
}
echo json_encode(array_values(array_unique($observed)), JSON_THROW_ON_ERROR)."\n";
