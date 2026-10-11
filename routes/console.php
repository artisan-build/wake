<?php

use ArtisanBuild\BuiltForCloud\SystemAuthoritySchedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/** @var Illuminate\Console\Scheduling\Schedule $schedule */
$schedule = Schedule::getFacadeRoot();
$authority = resolve(SystemAuthoritySchedule::class);

$authority->call($schedule, static fn (): int => Artisan::call('wake:lake-flush'))
    ->everyMinute()->name('wake-lake-flush')->withoutOverlapping();
$authority->call($schedule, static fn (): int => Artisan::call('wake:lake-compact'))
    ->dailyAt('03:15')->name('wake-lake-compact')->withoutOverlapping(60);
$authority->call($schedule, static fn (): int => Artisan::call('wake:lake-retain'))
    ->dailyAt('04:15')->name('wake-lake-retain')->withoutOverlapping(120);

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
