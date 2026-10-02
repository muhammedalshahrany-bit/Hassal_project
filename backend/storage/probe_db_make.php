<?php
// Disposable probe database for sandbox-seed verification. Drops and recreates
// `hassal_probe`; never touches `hassal_production`.
$env = [];
foreach (file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim($v);
}
$pdo = new PDO("mysql:host={$env['DB_HOST']};port=3306", $env['DB_USERNAME'], $env['DB_PASSWORD']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('DROP DATABASE IF EXISTS hassal_probe');
$pdo->exec('CREATE DATABASE hassal_probe CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
echo "probe db ready\n";
