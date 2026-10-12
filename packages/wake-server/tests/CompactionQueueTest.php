<?php

declare(strict_types=1);

use ArtisanBuild\WakeServer\Jobs\RewriteLakeDay;
use ArtisanBuild\WakeServer\Lake\LakeMaintenance;
use ArtisanBuild\WakeServer\Lake\LakeWriterLock;
use Illuminate\Support\Facades\Queue;

final class QueueCompactionLake implements LakeMaintenance
{
    /** @var list<string> */
    public array $rewritten = [];

    /** @param list<string> $days */
    public function __construct(
        private readonly array $days = [],
        private readonly bool $rewriteResult = false,
        private readonly bool $rewriteFails = false,
    ) {}

    public function fragmentedDays(int $limit = 3): array
    {
        return array_slice($this->days, 0, $limit);
    }

    public function rewriteDay(string $day): bool
    {
        $this->rewritten[] = $day;
        if ($this->rewriteFails) {
            throw new RuntimeException('rewrite failed');
        }

        return $this->rewriteResult;
    }

    public function deleteBefore(DateTimeInterface $cutoff): int
    {
        return 0;
    }

    public function expireSnapshots(DateTimeInterface $through): void {}

    public function cleanupFiles(): void {}
}

final class RecordingRewriteLock implements LakeWriterLock
{
    public bool $called = false;

    public function __construct(private readonly bool $acquired = true) {}

    public function execute(Closure $operation): bool
    {
        $this->called = true;
        if (! $this->acquired) {
            return false;
        }
        $operation();

        return true;
    }
}

it('declares a bounded retry budget above the managed worker default', function (): void {
    $job = new RewriteLakeDay('2026-10-01');

    expect($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(85)
        ->and($job->connection)->toBe('cloud')
        ->and($job->queue)->toBe('wake-lake');
});

it('dispatches only the oldest three days to the managed cloud queue without rewriting', function (): void {
    Queue::fake();
    $lake = new QueueCompactionLake(['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04']);
    app()->instance(LakeMaintenance::class, $lake);

    $this->artisan('wake:lake-compact')
        ->expectsOutputToContain('Dispatched 3')
        ->assertSuccessful();

    Queue::assertPushed(RewriteLakeDay::class, 3);
    Queue::assertPushedOn('wake-lake', RewriteLakeDay::class);
    expect(Queue::pushed(RewriteLakeDay::class)->map(fn (RewriteLakeDay $job): string => $job->day)->all())
        ->toBe(['2026-10-01', '2026-10-02', '2026-10-03'])
        ->and(Queue::pushed(RewriteLakeDay::class)->every(fn (RewriteLakeDay $job): bool => $job->connection === 'cloud' && $job->queue === 'wake-lake'))
        ->toBeTrue()
        ->and($lake->rewritten)->toBe([]);
});

it('reports a dispatch skip when no fragmented day remains', function (): void {
    Queue::fake();
    app()->instance(LakeMaintenance::class, new QueueCompactionLake);

    $this->artisan('wake:lake-compact')
        ->expectsOutputToContain('No fragmented')
        ->assertSuccessful();
    Queue::assertNothingPushed();
});

it('runs duplicate-safe rewrites inside the shared writer lock', function (): void {
    $lake = new QueueCompactionLake(rewriteResult: false);
    $lock = new RecordingRewriteLock;

    (new RewriteLakeDay('2026-10-01'))->handle($lake, $lock);

    expect($lock->called)->toBeTrue()
        ->and($lake->rewritten)->toBe(['2026-10-01']);
});

it('propagates lock contention and rewrite failures for queue retry', function (): void {
    $lockedLake = new QueueCompactionLake;
    expect(fn () => (new RewriteLakeDay('2026-10-01'))->handle($lockedLake, new RecordingRewriteLock(false)))
        ->toThrow(RuntimeException::class, 'writer is busy')
        ->and($lockedLake->rewritten)->toBe([]);

    $failingLake = new QueueCompactionLake(rewriteFails: true);
    expect(fn () => (new RewriteLakeDay('2026-10-01'))->handle($failingLake, new RecordingRewriteLock))
        ->toThrow(RuntimeException::class, 'rewrite failed');
});
