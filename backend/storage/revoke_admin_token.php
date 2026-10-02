<?php
// Remove the disposable live-check token created by mint_admin_token.php.
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$deleted = DB::table('personal_access_tokens')->where('name', 'live-check-queue-read')->delete();
echo "tokens deleted: $deleted\n";
