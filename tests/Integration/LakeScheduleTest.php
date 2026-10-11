<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\SystemAuthoritySchedule;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

it('registers lake commands and schedules app-instance maintenance without overlap', function (): void {
    expect(Artisan::all())->toHaveKeys([
        'wake:lake-bootstrap', 'wake:lake-flush', 'wake:lake-compact', 'wake:lake-retain',
    ]);

    $events = collect(Schedule::events())->filter(
        fn (mixed $event): bool => $event instanceof CallbackEvent && str_starts_with((string) $event->description, 'wake-lake-'),
    )->values();

    expect($events)->toHaveCount(3)
        ->and(app(SystemAuthoritySchedule::class))->toBeInstanceOf(SystemAuthoritySchedule::class)
        ->and($events->every(fn (CallbackEvent $event): bool => $event->withoutOverlapping))->toBeTrue()
        ->and($events->pluck('description')->all())->toBe(['wake-lake-flush', 'wake-lake-compact', 'wake-lake-retain'])
        ->and($events->pluck('expression')->all())->toBe(['* * * * *', '15 3 * * *', '15 4 * * *']);
});
