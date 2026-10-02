<?php
// Remove only the personal access token minted for the live queue check.
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$n = DB::table('personal_access_tokens')->where('name', 'live-check-queue-read')->delete();
echo "tokens deleted: $n\n";
