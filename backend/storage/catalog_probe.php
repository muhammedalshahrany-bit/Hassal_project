<?php
// Read-only inspection of the live schema for catalog rows. Temporary; delete after use.
require __DIR__ . '/../vendor/autoload.php';
$env = [];
foreach (file(__DIR__ . '/../.env') as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    $pos = strpos($line, '=');
    if ($pos === false) continue;
    $env[substr($line, 0, $pos)] = trim(substr($line, $pos + 1), " \"'");
}
$pdo = new PDO(
    'mysql:host=' . ($env['DB_HOST'] ?? '127.0.0.1') . ';port=' . ($env['DB_PORT'] ?? '3306')
        . ';dbname=' . ($env['DB_DATABASE'] ?? ''),
    $env['DB_USERNAME'] ?? '',
    $env['DB_PASSWORD'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
echo "database = " . ($env['DB_DATABASE'] ?? '?') . "\n";
foreach ($pdo->query('select "feature_flags" t, count(*) c from feature_flags union all select "system_settings", count(*) from system_settings union all select "organizations", count(*) from organizations union all select "users", count(*) from users') as $r) {
    echo str_pad($r['t'], 18) . $r['c'] . "\n";
}
echo "--- flags ---\n";
foreach ($pdo->query('select key_, is_enabled from (select `key` key_, is_enabled from feature_flags) x order by key_') as $r) {
    printf("%-24s %s\n", $r['key_'], $r['is_enabled']);
}
echo "--- new setting keys present? ---\n";
foreach ($pdo->query("select `key`, value from system_settings where `key` like 'marketplace.%' or `key` like 'community.%' or `key` like 'legal.%' order by `key`") as $r) {
    printf("%-45s %s\n", $r['key'], $r['value']);
}
echo "--- catalog keys missing from DB ---\n";
$have = $pdo->query('select `key` from system_settings')->fetchAll(PDO::FETCH_COLUMN);
$want = \App\Support\SettingCatalog::defaults();
foreach ($want as $s) {
    if (!in_array($s['key'], $have, true)) echo "  setting: " . $s['key'] . "\n";
}
$haveF = $pdo->query('select `key` from feature_flags')->fetchAll(PDO::FETCH_COLUMN);
foreach (\App\Support\FlagCatalog::defaults() as $f) {
    if (!in_array($f['key'], $haveF, true)) echo "  flag: " . $f['key'] . "\n";
}
