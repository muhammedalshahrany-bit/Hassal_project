<?php
$env = [];
foreach (file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim($v);
}
$pdo = new PDO("mysql:host={$env['DB_HOST']};port=3306;dbname={$env['DB_DATABASE']}", $env['DB_USERNAME'], $env['DB_PASSWORD']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

foreach (['offer_likes', 'offer_comments', 'offer_comment_reports'] as $t) {
    $rows = $pdo->query("SHOW COLUMNS FROM `$t` LIKE 'organization_id'")->fetchAll(PDO::FETCH_ASSOC);
    echo "$t organization_id NULLABLE=" . $rows[0]['Null'] . "\n";
}

foreach (['offer_likes', 'offer_comment_reports'] as $t) {
    $idx = $pdo->query("SHOW INDEX FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
    $names = array_unique(array_column($idx, 'Key_name'));
    echo "$t indexes: " . implode(', ', $names) . "\n";
}
echo "likes rows: " . $pdo->query('SELECT COUNT(*) c FROM offer_likes')->fetch(PDO::FETCH_ASSOC)['c'] . "\n";
