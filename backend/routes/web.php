<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('http://localhost:3000');
});

Route::get('/reset-password/{token}', function (string $token) {
    $email = request()->query('email', '');
    return redirect('http://localhost:3000/?reset_token=' . urlencode($token) . '&email=' . urlencode($email));
})->name('password.reset');
