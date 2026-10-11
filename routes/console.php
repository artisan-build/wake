<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('wake:lake-flush')->everyMinute()->withoutOverlapping();
Schedule::command('wake:lake-compact')->dailyAt('03:15')->withoutOverlapping(60);
Schedule::command('wake:lake-retain')->dailyAt('04:15')->withoutOverlapping(120);

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
