<?php
$env = [];
foreach (file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim($v);
}
$pdo = new PDO("mysql:host={$env['DB_HOST']};port=3306;dbname=hassal_probe", $env['DB_USERNAME'], $env['DB_PASSWORD']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "-- offer_likes (person-attributed) --\n";
foreach ($pdo->query('SELECT offer_id, user_id, organization_id FROM offer_likes ORDER BY id') as $r) {
    echo "offer={$r['offer_id']} user={$r['user_id']} org=" . var_export($r['organization_id'], true) . "\n";
}

echo "-- offer_comments --\n";
foreach ($pdo->query('SELECT id, offer_id, user_id, organization_id, parent_id, is_seller_reply, LEFT(body,40) b FROM offer_comments ORDER BY id') as $r) {
    echo "id={$r['id']} offer={$r['offer_id']} user={$r['user_id']} org=" . var_export($r['organization_id'], true)
        . " parent=" . var_export($r['parent_id'], true) . " seller={$r['is_seller_reply']} body={$r['b']}\n";
}

echo "-- consumers --\n";
foreach ($pdo->query("SELECT id, name, email, status FROM users WHERE email LIKE '%.consumer@%'") as $r) {
    echo "id={$r['id']} {$r['email']} status={$r['status']}\n";
}

echo "-- support_tickets null-org --\n";
foreach ($pdo->query('SELECT reference, category, status, organization_id FROM support_tickets ORDER BY id') as $r) {
    echo "{$r['reference']} {$r['category']} {$r['status']} org=" . var_export($r['organization_id'], true) . "\n";
}
