<?php

use Illuminate\Support\Facades\Route;

// Two browser-facing routes: the bare host, and the password-reset link an
// email hands to somebody who has forgotten their credentials. Both forward to
// the web client, whose address is a deployment fact — config('hassal.frontend'
// .url') resolves HASSAL_FRONTEND_URL and falls back to APP_URL. Hard-coding
// localhost here would ship a reset link that only works on a developer's
// machine.
Route::get('/', fn () => redirect(config('hassal.frontend.url')));

Route::get('/reset-password/{token}', function (string $token) {
    $email = request()->query('email', '');

    return redirect(config('hassal.frontend.url') . '/?reset_token=' . urlencode($token) . '&email=' . urlencode($email));
})->name('password.reset');
