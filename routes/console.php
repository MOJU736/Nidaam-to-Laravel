<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// A copy of the main database and every company, every night (the newest 14 days are kept in storage/app/backups).
// Needs the Laravel scheduler running: one task that runs `php artisan schedule:run` every minute.
Schedule::command('backups:run')->dailyAt('02:00')->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
