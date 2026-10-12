<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\SystemAuthoritySchedule;
use ArtisanBuild\WakeServer\Jobs\RewriteLakeDay;
use ArtisanBuild\WakeServer\Lake\LakeMaintenance;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;

final class ScheduleDispatchLake implements LakeMaintenance
{
    /** @var list<string> */
    public array $rewritten = [];

    public function fragmentedDays(int $limit = 3): array
    {
        return ['2026-10-01', '2026-10-02'];
    }

    public function rewriteDay(string $day): bool
    {
        $this->rewritten[] = $day;

        return true;
    }

    public function deleteBefore(DateTimeInterface $cutoff): int
    {
        return 0;
    }

    public function expireSnapshots(DateTimeInterface $through): void {}

    public function cleanupFiles(): void {}
}

it('schedules lake maintenance and dispatches compact work without rewriting', function (): void {
    expect(Artisan::all())->toHaveKeys([
        'wake:lake-bootstrap', 'wake:lake-flush', 'wake:lake-compact', 'wake:lake-retain',
    ]);

    $events = collect(Schedule::events())->filter(
        fn (mixed $event): bool => $event instanceof CallbackEvent && str_starts_with((string) $event->description, 'wake-lake-'),
    )->values();
    $lake = new ScheduleDispatchLake;
    app()->instance(LakeMaintenance::class, $lake);
    Queue::fake();
    $compact = $events->first(fn (CallbackEvent $event): bool => $event->description === 'wake-lake-compact');
    expect($compact)->toBeInstanceOf(CallbackEvent::class);
    $compact->run(app());

    expect($events)->toHaveCount(3)
        ->and(resolve(SystemAuthoritySchedule::class))->toBeInstanceOf(SystemAuthoritySchedule::class)
        ->and($events->every(fn (CallbackEvent $event): bool => $event->withoutOverlapping))->toBeTrue()
        ->and($events->pluck('description')->all())->toBe(['wake-lake-flush', 'wake-lake-compact', 'wake-lake-retain'])
        ->and($events->pluck('expression')->all())->toBe(['* * * * *', '15 3 * * *', '15 4 * * *'])
        ->and($lake->rewritten)->toBe([]);
    Queue::assertPushed(RewriteLakeDay::class, 2);
});
