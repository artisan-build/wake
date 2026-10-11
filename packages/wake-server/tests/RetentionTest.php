<?php

declare(strict_types=1);

use ArtisanBuild\WakeServer\Lake\LakeMaintenance;
use ArtisanBuild\WakeServer\Lake\LakeWriterLock;
use ArtisanBuild\WakeServer\Lake\RetainLogs;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RecordingLakeMaintenance implements LakeMaintenance
{
    /** @var list<string> */
    public array $calls = [];

    public ?string $deletedBefore = null;

    public ?string $expiredBefore = null;

    public bool $cleaned = false;

    public function compact(): array
    {
        return ['files_before' => 0, 'files_after' => 0];
    }

    public function deleteBefore(DateTimeInterface $cutoff): int
    {
        $this->deletedBefore = $cutoff->format('Y-m-d');
        $this->calls[] = 'delete';

        return 2;
    }

    public function expireSnapshots(DateTimeInterface $through): void
    {
        $this->expiredBefore = $through->format('Y-m-d H:i:sP');
        $this->calls[] = 'expire';
    }

    public function cleanupFiles(): void
    {
        $this->cleaned = true;
        $this->calls[] = 'cleanup';
    }
}

final class ImmediateRetentionLock implements LakeWriterLock
{
    public function __construct(private readonly bool $acquired = true) {}

    public function execute(Closure $operation): bool
    {
        if (! $this->acquired) {
            return false;
        }
        $operation();

        return true;
    }
}

beforeEach(function (): void {
    Schema::create('wake_event_locators', function (Blueprint $table): void {
        $table->string('event_id');
        $table->date('event_date');
    });
    Schema::create('wake_hourly_counts', function (Blueprint $table): void {
        $table->dateTime('hour');
    });
    Schema::create('wake_error_events', function (Blueprint $table): void {
        $table->string('event_id');
        $table->date('event_date');
    });
    CarbonImmutable::setTestNow('2026-10-11 15:30:00 UTC');
});

it('skips every retention write when the shared lake lock is held', function (): void {
    $lake = new RecordingLakeMaintenance;

    expect((new RetainLogs($lake, new ImmediateRetentionLock(false)))->run())->toBe([
        'cutoff' => '2026-09-11', 'lake_rows' => 0, 'locators' => 0, 'hourly' => 0, 'errors' => 0,
    ])->and($lake->calls)->toBe([]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Schema::dropAllTables();
});

it('uses a whole-day default cutoff and prunes only older derived rows', function (): void {
    foreach (['2026-09-10', '2026-09-11', '2026-10-10'] as $date) {
        DB::table('wake_event_locators')->insert(['event_id' => 'locator-'.$date, 'event_date' => $date]);
        DB::table('wake_error_events')->insert(['event_id' => 'error-'.$date, 'event_date' => $date]);
        DB::table('wake_hourly_counts')->insert(['hour' => $date.' 00:00:00']);
    }
    $lake = new RecordingLakeMaintenance;

    $result = (new RetainLogs($lake, new ImmediateRetentionLock))->run();

    expect($result)->toBe([
        'cutoff' => '2026-09-11', 'lake_rows' => 2, 'locators' => 1, 'hourly' => 1, 'errors' => 1,
    ])->and(DB::table('wake_event_locators')->pluck('event_date')->all())->toHaveCount(2)
        ->and($lake->deletedBefore)->toBe('2026-09-11')
        ->and($lake->expiredBefore)->toBe('2026-10-11 15:30:00+00:00')
        ->and($lake->cleaned)->toBeTrue()
        ->and($lake->calls)->toBe(['delete', 'expire', 'cleanup']);
});
