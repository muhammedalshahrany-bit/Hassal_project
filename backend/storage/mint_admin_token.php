<?php
// Mint a short-lived personal access token for the installed system admin so
// the live check can read the platform community queue. Adds a token row to
// the local dev database only; no password is read, reset, or stored.
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;

$admin = User::where('email', 'alshhranymuha39@gmail.com')->first()
    ?? User::role('SuperAdmin')->first();

if (!$admin) {
    fwrite(STDERR, "no platform admin account found\n");
    exit(1);
}

$token = $admin->createToken('live-check-queue-read');
echo $token->plainTextToken, "\n";
