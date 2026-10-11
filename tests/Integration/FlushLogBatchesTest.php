<?php

declare(strict_types=1);

use ArtisanBuild\WakeContracts\Enums\StagingBatchStatus;
use ArtisanBuild\WakeServer\Lake\FlushLogBatches;
use ArtisanBuild\WakeServer\Lake\LakeWriter;
use ArtisanBuild\WakeServer\Lake\LakeWriterLock;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

final class MemoryLakeWriter implements LakeWriter
{
    public int $bootstraps = 0;

    /** @var array<string, true> */
    public array $committedBatches = [];

    /** @var list<array{batch_id: string, event_days: list<string>}> */
    public array $checks = [];

    /** @var array<string, int> */
    public array $events = [];

    public function bootstrap(): void
    {
        $this->bootstraps++;
    }

    public function batchCommitted(string $batchId, array $eventDays): bool
    {
        $this->checks[] = ['batch_id' => $batchId, 'event_days' => $eventDays];

        return isset($this->committedBatches[$batchId]);
    }

    public function commitBatch(string $batchId, array $rows): void
    {
        foreach ($rows as $row) {
            $eventId = (string) $row['event_id'];
            $this->events[$eventId] = ($this->events[$eventId] ?? 0) + 1;
        }
        $this->committedBatches[$batchId] = true;
    }
}

final class ImmediateLakeWriterLock implements LakeWriterLock
{
    public function execute(Closure $operation): bool
    {
        $operation();

        return true;
    }
}

it('returns before starting DuckDB when no batches are claimed', function (): void {
    $lake = new MemoryLakeWriter;

    expect((new FlushLogBatches($lake, new ImmediateLakeWriterLock))->run())->toBe(['batches' => 0, 'rows' => 0])
        ->and($lake->bootstraps)->toBe(0);
});

it('claims on PostgreSQL with skip locked', function (): void {
    seedFlushBatch();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    (new FlushLogBatches(new MemoryLakeWriter, new ImmediateLakeWriterLock))->run(1);

    expect(DB::getDriverName())->toBe('pgsql')
        ->and(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'for update skip locked')))->toBeTrue();
});

it('does not overlap a batch locked by another PostgreSQL worker', function (): void {
    [$lockedBatch, $lockedEvent] = seedFlushBatch('worker-one');
    [, $availableEvent] = seedFlushBatch('worker-two');
    $primary = DB::connection('pgsql');
    $primary->beginTransaction();
    $primary->table('wake_staging_batches')->where('batch_id', $lockedBatch)->lockForUpdate()->first();

    config()->set('database.connections.pgsql_worker', config('database.connections.pgsql'));
    $lake = new MemoryLakeWriter;
    try {
        DB::setDefaultConnection('pgsql_worker');
        (new FlushLogBatches($lake, new ImmediateLakeWriterLock))->run(1);
    } finally {
        DB::setDefaultConnection('pgsql');
        $primary->rollBack();
        DB::purge('pgsql_worker');
    }

    expect($lake->events)->toHaveKey($availableEvent)->not->toHaveKey($lockedEvent);
});

it('recovers each manifest state and applies every effect exactly once', function (string $crashPoint, string $expectedState): void {
    [$batchId, $eventId] = seedFlushBatch();
    $lake = new MemoryLakeWriter;
    $crashed = false;
    $fault = function (string $point) use ($crashPoint, &$crashed): void {
        if (! $crashed && $point === $crashPoint) {
            $crashed = true;
            throw new RuntimeException("Injected {$point}");
        }
    };

    expect(fn () => (new FlushLogBatches($lake, new ImmediateLakeWriterLock, $fault))->run(1))->toThrow(RuntimeException::class);
    expect(DB::table('wake_flush_batches')->where('batch_id', $batchId)->value('state'))->toBe($expectedState);
    (new FlushLogBatches($lake, new ImmediateLakeWriterLock))->run(1);

    expect($lake->events[$eventId])->toBe(1)
        ->and(DB::table('wake_event_locators')->where('event_id', $eventId)->count())->toBe(1)
        ->and(DB::table('wake_error_events')->where('event_id', $eventId)->count())->toBe(1)
        ->and(DB::table('wake_hourly_counts')->value('count'))->toBe(1)
        ->and(DB::table('wake_connected_apps')->value('flushed_rows'))->toBe(1)
        ->and(DB::table('wake_flush_batches')->where('batch_id', $batchId)->value('state'))->toBe('done')
        ->and(DB::table('wake_staging_batches')->where('batch_id', $batchId)->value('status'))->toBe(StagingBatchStatus::Flushed->value)
        ->and(DB::table('wake_staging_batches')->where('batch_id', $batchId)->value('payload'))->toBeNull()
        ->and($lake->checks)->toContain(['batch_id' => $batchId, 'event_days' => ['2026-10-11']]);
})->with([
    'claimed after lake transaction' => ['after_lake_commit', 'claimed'],
    'lake committed before PostgreSQL commit' => ['before_derived_commit', 'lake_committed'],
]);

it('recovers unfinished manifests in deterministic oldest order before claiming pending rows', function (): void {
    [$older] = seedFlushBatch('older', '2026-10-09T12:34:56.000000Z');
    [$newer] = seedFlushBatch('newer', '2026-10-10T12:34:56.000000Z');
    [$pending, $pendingEvent] = seedFlushBatch('pending', '2026-10-11T12:34:56.000000Z');
    seedFlushManifest($older, 'claimed', '2026-10-09 00:00:00', ['2026-10-09']);
    seedFlushManifest($newer, 'claimed', '2026-10-10 00:00:00', ['2026-10-10']);
    DB::table('wake_staging_batches')->whereIn('batch_id', [$older, $newer])->update(['status' => 'flushing']);
    $lake = new MemoryLakeWriter;

    expect((new FlushLogBatches($lake, new ImmediateLakeWriterLock))->run(2))->toBe(['batches' => 2, 'rows' => 2])
        ->and(array_column($lake->checks, 'batch_id'))->toBe([$older, $newer])
        ->and(DB::table('wake_staging_batches')->where('batch_id', $pending)->value('status'))->toBe('pending')
        ->and($lake->events)->not->toHaveKey($pendingEvent);
});

/** @return array{string, string} */
function seedFlushBatch(string $idempotencyKey = 'flush-test', string $occurredAt = '2026-10-11T12:34:56.000000Z'): array
{
    $batchId = (string) Str::ulid();
    $eventId = (string) Str::ulid();
    DB::table('wake_connected_apps')->insertOrIgnore(['id' => 'app-1', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('wake_staging_batches')->insert([
        'batch_id' => $batchId, 'app_id' => 'app-1', 'idempotency_key' => $idempotencyKey,
        'envelope_version' => 1, 'client_version' => 'test', 'dropped_count' => 0,
        'payload' => json_encode(['rows' => [[
            'event_id' => $eventId, 'occurred_at' => $occurredAt,
            'level' => 'error', 'channel' => 'app', 'message' => 'boom', 'context' => [], 'extra' => [],
            'exception' => ['class' => 'RuntimeException', 'message' => 'boom', 'file' => '/app/a.php', 'line' => 1, 'trace' => ''],
            'environment' => 'testing', 'hostname' => 'host', 'correlation_id' => null,
        ]]], JSON_THROW_ON_ERROR),
        'row_count' => 1, 'status' => StagingBatchStatus::Pending->value,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$batchId, $eventId];
}

/** @param list<string> $eventDays */
function seedFlushManifest(string $batchId, string $state, string $createdAt, array $eventDays): void
{
    DB::table('wake_flush_batches')->insert([
        'batch_id' => $batchId,
        'staging_batch_ids' => json_encode([$batchId], JSON_THROW_ON_ERROR),
        'event_days' => json_encode($eventDays, JSON_THROW_ON_ERROR),
        'state' => $state,
        'claimed_at' => $createdAt,
        'lake_committed_at' => $state === 'lake_committed' ? $createdAt : null,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}
