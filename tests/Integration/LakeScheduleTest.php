<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

it('registers lake commands and schedules app-instance maintenance without overlap', function (): void {
    expect(Artisan::all())->toHaveKeys([
        'wake:lake-bootstrap', 'wake:lake-flush', 'wake:lake-compact', 'wake:lake-retain',
    ]);

    $events = collect(Schedule::events())->filter(fn (Event|CallbackEvent $event): bool => $event instanceof Event && str_contains($event->command ?? '', 'wake:lake-'));

    expect($events)->toHaveCount(3)
        ->and($events->every(fn (Event $event): bool => $event->withoutOverlapping))->toBeTrue()
        ->and($events->pluck('expression')->all())->toBe(['* * * * *', '15 3 * * *', '15 4 * * *']);
});
