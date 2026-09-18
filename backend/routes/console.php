<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Background scheduled tasks for production lifecycle
Schedule::command('app:expire-reservations')->everyMinute()->withoutOverlapping();
Schedule::command('app:process-webhooks')->everyMinute()->withoutOverlapping();
