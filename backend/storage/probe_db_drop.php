<?php
$e = [];
foreach (file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
    if (str_starts_with(trim($l), '#') || !str_contains($l, '=')) continue;
    [$k, $v] = explode('=', $l, 2);
    $e[trim($k)] = trim($v);
}
$p = new PDO("mysql:host={$e['DB_HOST']};port=3306", $e['DB_USERNAME'], $e['DB_PASSWORD']);
$p->exec('DROP DATABASE IF EXISTS hassal_probe');
echo "probe dropped\n";
