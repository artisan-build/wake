<?php

declare(strict_types=1);

use ArtisanBuild\WakeServer\Lake\DuckDb;
use ArtisanBuild\WakeServer\Lake\FlushLogBatches;
use ArtisanBuild\WakeServer\Lake\LakeWriterLock;
use ArtisanBuild\WakeServer\Models\StagingBatch;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->catalogId = 'wake_lake_'.strtolower((string) Str::ulid());
    $this->dataPath = storage_path('framework/testing/ducklake/'.$this->catalogId.'/');
    DB::statement('CREATE SCHEMA "'.$this->catalogId.'"');
    File::ensureDirectoryExists($this->dataPath);
    config()->set('wake-server.lake.postgres_catalog', true);
    config()->set('wake-server.lake.metadata_schema', $this->catalogId);
    config()->set('wake-server.lake.data_path', $this->dataPath);
});

afterEach(function (): void {
    config()->set('wake-server.lake.postgres_catalog', false);
    config()->set('wake-server.lake.metadata_schema');
    config()->set('wake-server.lake.data_path');
    DB::statement('DROP SCHEMA IF EXISTS "'.$this->catalogId.'" CASCADE');
    File::deleteDirectory($this->dataPath);
});

it('bootstraps real DuckLake on PostgreSQL and replays one batch without duplicate lake rows', function (): void {
    expect(is_executable(base_path('bin/duckdb')))->toBeTrue('Run scripts/install-duckdb.sh before the root test lane.');
    $lake = app(DuckDb::class);
    $batchId = (string) Str::ulid();
    $eventId = (string) Str::ulid();
    $days = ['2026-10-11'];
    $row = [
        'event_id' => $eventId, 'app_id' => 'real-engine-app', 'occurred_at' => '2026-10-11T12:00:00.000000Z',
        'level' => 'error', 'channel' => 'app', 'message' => 'real replay', 'context' => [], 'extra' => [],
        'exception' => null, 'environment' => 'testing', 'hostname' => 'localhost', 'correlation_id' => null,
    ];

    $lake->bootstrap();
    expect(DB::table('pg_catalog.pg_tables')->where('schemaname', $this->catalogId)->where('tablename', 'like', 'ducklake_%')->count())->toBeGreaterThan(0);
    expect($lake->batchCommitted($batchId, $days))->toBeFalse();
    $lake->commitBatch($batchId, [$row]);
    expect($lake->batchCommitted($batchId, $days))->toBeTrue();
    if (! $lake->batchCommitted($batchId, $days)) {
        $lake->commitBatch($batchId, [$row]);
    }
    $result = $lake->query('SELECT count(*) AS rows FROM lake.logs WHERE batch_id = '.$lake->literal($batchId).';');

    expect($result[0]['rows'] ?? null)->toBe(1);
});

it('converges exactly once after a real lake commit crash', function (): void {
    expect(is_executable(base_path('bin/duckdb')))->toBeTrue('Run scripts/install-duckdb.sh before the root test lane.');
    [$batchId, $eventId] = seedRealFlushBatch();
    $lake = app(DuckDb::class);
    $fault = static function (string $point): void {
        if ($point === 'after_lake_commit') {
            throw new RuntimeException('Injected after_lake_commit');
        }
    };

    expect(fn () => (new FlushLogBatches($lake, app(LakeWriterLock::class), $fault))->run(1))
        ->toThrow(RuntimeException::class, 'Injected after_lake_commit');
    expect(DB::table('wake_flush_batches')->where('batch_id', $batchId)->value('state'))->toBe('claimed')
        ->and(realLakeBatchRows($lake, $batchId))->toBe(1)
        ->and(DB::table('wake_event_locators')->count())->toBe(0)
        ->and(DB::table('wake_hourly_counts')->count())->toBe(0)
        ->and(DB::table('wake_error_events')->count())->toBe(0)
        ->and(DB::table('wake_connected_apps')->value('flushed_rows'))->toBe(0)
        ->and(DB::table('wake_staging_batches')->where('batch_id', $batchId)->value('status'))->toBe('flushing');

    expect((new FlushLogBatches($lake, app(LakeWriterLock::class)))->run(1))->toBe(['batches' => 1, 'rows' => 1])
        ->and(realLakeBatchRows($lake, $batchId))->toBe(1)
        ->and(DB::table('wake_event_locators')->where('event_id', $eventId)->count())->toBe(1)
        ->and(DB::table('wake_error_events')->where('event_id', $eventId)->count())->toBe(1)
        ->and(DB::table('wake_hourly_counts')->value('count'))->toBe(1)
        ->and(DB::table('wake_connected_apps')->value('flushed_rows'))->toBe(1)
        ->and(DB::table('wake_flush_batches')->where('batch_id', $batchId)->value('state'))->toBe('done')
        ->and(DB::table('wake_staging_batches')->where('batch_id', $batchId)->value('status'))->toBe('flushed')
        ->and(DB::table('wake_staging_batches')->where('batch_id', $batchId)->value('payload'))->toBeNull();
});

/** @return array{string, string} */
function seedRealFlushBatch(): array
{
    $batchId = (string) Str::ulid();
    $eventId = (string) Str::ulid();
    DB::table('wake_connected_apps')->insert([
        'id' => 'real-engine-app', 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    StagingBatch::query()->create([
        'batch_id' => $batchId, 'app_id' => 'real-engine-app', 'idempotency_key' => 'real-crash',
        'envelope_version' => 1, 'client_version' => 'test', 'dropped_count' => 0,
        'payload' => ['rows' => [[
            'event_id' => $eventId, 'occurred_at' => '2026-10-11T12:00:00.000000Z',
            'level' => 'error', 'channel' => 'app', 'message' => 'real crash', 'context' => [], 'extra' => [],
            'exception' => null, 'environment' => 'testing', 'hostname' => 'localhost', 'correlation_id' => null,
        ]]],
        'row_count' => 1, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$batchId, $eventId];
}

function realLakeBatchRows(DuckDb $lake, string $batchId): int
{
    $result = $lake->query('SELECT count(*) AS rows FROM lake.logs WHERE batch_id = '.$lake->literal($batchId).';');
    $rows = $result[0]['rows'] ?? null;

    return is_int($rows) ? $rows : 0;
}
