<?php

declare(strict_types=1);

use ArtisanBuild\WakeContracts\Enums\StagingBatchStatus;
use ArtisanBuild\WakeServer\Lake\FlushLogBatches;
use ArtisanBuild\WakeServer\Lake\LakeWriter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

final class MemoryLakeWriter implements LakeWriter
{
    public int $bootstraps = 0;

    /** @var array<string, true> */
    public array $manifest = [];

    /** @var array<string, int> */
    public array $events = [];

    public function bootstrap(): void
    {
        $this->bootstraps++;
    }

    public function commitBatch(string $batchId, array $rows): void
    {
        if (isset($this->manifest[$batchId])) {
            return;
        }
        foreach ($rows as $row) {
            $eventId = (string) $row['event_id'];
            $this->events[$eventId] = ($this->events[$eventId] ?? 0) + 1;
        }
        $this->manifest[$batchId] = true;
    }
}

it('returns before starting DuckDB when no batches are claimed', function (): void {
    $lake = new MemoryLakeWriter;

    expect((new FlushLogBatches($lake))->run())->toBe(['batches' => 0, 'rows' => 0])
        ->and($lake->bootstraps)->toBe(0);
});

it('claims on PostgreSQL with skip locked', function (): void {
    seedFlushBatch();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    (new FlushLogBatches(new MemoryLakeWriter))->run(1);

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
        (new FlushLogBatches($lake))->run(1);
    } finally {
        DB::setDefaultConnection('pgsql');
        $primary->rollBack();
        DB::purge('pgsql_worker');
    }

    expect($lake->events)->toHaveKey($availableEvent)->not->toHaveKey($lockedEvent);
});

it('converges after each deterministic crash point', function (string $crashPoint): void {
    [$batchId, $eventId] = seedFlushBatch();
    $lake = new MemoryLakeWriter;
    $crashed = false;
    $fault = function (string $point) use ($crashPoint, &$crashed): void {
        if (! $crashed && $point === $crashPoint) {
            $crashed = true;
            throw new RuntimeException("Injected {$point}");
        }
    };

    expect(fn () => (new FlushLogBatches($lake, $fault))->run(1))->toThrow(RuntimeException::class);
    DB::table('wake_staging_batches')->where('batch_id', $batchId)->update(['claimed_at' => now()->subMinutes(6)]);
    (new FlushLogBatches($lake))->run(1);

    expect($lake->events[$eventId])->toBe(1)
        ->and(DB::table('wake_event_locators')->where('event_id', $eventId)->count())->toBe(1)
        ->and(DB::table('wake_error_events')->where('event_id', $eventId)->count())->toBe(1)
        ->and(DB::table('wake_hourly_counts')->value('count'))->toBe(1)
        ->and(DB::table('wake_connected_apps')->value('flushed_rows'))->toBe(1)
        ->and(DB::table('wake_flush_manifest')->where('batch_id', $batchId)->where('derived_completed', true)->count())->toBe(1)
        ->and(DB::table('wake_staging_batches')->where('batch_id', $batchId)->value('status'))->toBe(StagingBatchStatus::Flushed->value)
        ->and(DB::table('wake_staging_batches')->where('batch_id', $batchId)->value('payload'))->toBeNull();
})->with(['after lake transaction' => 'after_lake_commit', 'before PostgreSQL commit' => 'before_derived_commit']);

/** @return array{string, string} */
function seedFlushBatch(string $idempotencyKey = 'flush-test'): array
{
    $batchId = (string) Str::ulid();
    $eventId = (string) Str::ulid();
    DB::table('wake_connected_apps')->insertOrIgnore(['id' => 'app-1', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('wake_staging_batches')->insert([
        'batch_id' => $batchId, 'app_id' => 'app-1', 'idempotency_key' => $idempotencyKey,
        'envelope_version' => 1, 'client_version' => 'test', 'dropped_count' => 0,
        'payload' => json_encode(['rows' => [[
            'event_id' => $eventId, 'occurred_at' => '2026-10-11T12:34:56.000000Z',
            'level' => 'error', 'channel' => 'app', 'message' => 'boom', 'context' => [], 'extra' => [],
            'exception' => ['class' => 'RuntimeException', 'message' => 'boom', 'file' => '/app/a.php', 'line' => 1, 'trace' => ''],
            'environment' => 'testing', 'hostname' => 'host', 'correlation_id' => null,
        ]]], JSON_THROW_ON_ERROR),
        'row_count' => 1, 'status' => StagingBatchStatus::Pending->value,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$batchId, $eventId];
}
