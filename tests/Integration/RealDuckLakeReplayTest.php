<?php

declare(strict_types=1);

use App\Services\WakeQueries;
use ArtisanBuild\WakeServer\Lake\DuckDb;
use ArtisanBuild\WakeServer\Lake\FlushLogBatches;
use ArtisanBuild\WakeServer\Lake\LakeWriterLock;
use ArtisanBuild\WakeServer\Models\StagingBatch;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

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
    $lake = resolve(DuckDb::class);
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

it('keeps the UTC day stable for storage replay detection and retention under a non UTC host timezone', function (): void {
    expect(is_executable(base_path('bin/duckdb')))->toBeTrue('Run scripts/install-duckdb.sh before the root test lane.');
    $previousPhpTimezone = date_default_timezone_get();
    $previousEnvironmentTimezone = getenv('TZ');
    putenv('TZ=Europe/Zurich');
    date_default_timezone_set('Europe/Zurich');

    try {
        $lake = resolve(DuckDb::class);
        $batchId = (string) Str::ulid();
        $row = [
            'event_id' => (string) Str::ulid(), 'app_id' => 'utc-boundary-app',
            'occurred_at' => '2026-10-11T23:30:00.000000Z', 'level' => 'error',
            'channel' => 'app', 'message' => 'utc boundary', 'context' => [], 'extra' => [],
            'exception' => null, 'environment' => 'testing', 'hostname' => 'localhost', 'correlation_id' => null,
        ];

        $lake->bootstrap();
        $lake->commitBatch($batchId, [$row]);
        $stored = $lake->query('SELECT CAST(event_date AS VARCHAR) AS event_date FROM lake.logs WHERE batch_id = '.$lake->literal($batchId).';');
        $detected = $lake->batchCommitted($batchId, ['2026-10-11']);
        $deleted = $lake->deleteBefore(new DateTimeImmutable('2026-10-12T00:00:00Z'));
        $remaining = realLakeBatchRows($lake, $batchId);

        expect([
            'event_date' => $stored[0]['event_date'] ?? null,
            'detected_on_utc_day' => $detected,
            'deleted_at_utc_cutoff' => $deleted,
            'remaining' => $remaining,
        ])->toBe([
            'event_date' => '2026-10-11',
            'detected_on_utc_day' => true,
            'deleted_at_utc_cutoff' => 1,
            'remaining' => 0,
        ]);
    } finally {
        if ($previousEnvironmentTimezone === false) {
            putenv('TZ');
        } else {
            putenv('TZ='.$previousEnvironmentTimezone);
        }
        date_default_timezone_set($previousPhpTimezone);
    }
});

it('converges exactly once after a real lake commit crash', function (): void {
    expect(is_executable(base_path('bin/duckdb')))->toBeTrue('Run scripts/install-duckdb.sh before the root test lane.');
    [$batchId, $eventId] = seedRealFlushBatch();
    $lake = resolve(DuckDb::class);
    $fault = static function (string $point): void {
        if ($point === 'after_lake_commit') {
            throw new RuntimeException('Injected after_lake_commit');
        }
    };

    expect(fn () => (new FlushLogBatches($lake, resolve(LakeWriterLock::class), $fault))->run(1))
        ->toThrow(RuntimeException::class, 'Injected after_lake_commit');
    expect(DB::table('wake_flush_batches')->where('batch_id', $batchId)->value('state'))->toBe('claimed')
        ->and(realLakeBatchRows($lake, $batchId))->toBe(1)
        ->and(DB::table('wake_event_locators')->count())->toBe(0)
        ->and(DB::table('wake_hourly_counts')->count())->toBe(0)
        ->and(DB::table('wake_error_events')->count())->toBe(0)
        ->and(DB::table('wake_connected_apps')->value('flushed_rows'))->toBe(0)
        ->and(DB::table('wake_staging_batches')->where('batch_id', $batchId)->value('status'))->toBe('flushing');

    expect((new FlushLogBatches($lake, resolve(LakeWriterLock::class)))->run(1))->toBe(['batches' => 1, 'rows' => 1])
        ->and(realLakeBatchRows($lake, $batchId))->toBe(1)
        ->and(DB::table('wake_event_locators')->where('event_id', $eventId)->count())->toBe(1)
        ->and(DB::table('wake_error_events')->where('event_id', $eventId)->count())->toBe(1)
        ->and(DB::table('wake_hourly_counts')->value('count'))->toBe(1)
        ->and(DB::table('wake_connected_apps')->value('flushed_rows'))->toBe(1)
        ->and(DB::table('wake_flush_batches')->where('batch_id', $batchId)->value('state'))->toBe('done')
        ->and(DB::table('wake_staging_batches')->where('batch_id', $batchId)->value('status'))->toBe('flushed')
        ->and(DB::table('wake_staging_batches')->where('batch_id', $batchId)->value('payload'))->toBeNull();
});

it('rolls back every derived effect before commit and converges exactly once on retry', function (): void {
    [$batchId, $eventId] = seedRealFlushBatch();
    $lake = resolve(DuckDb::class);
    $fault = static function (string $point): void {
        if ($point === 'before_derived_commit') {
            throw new RuntimeException('Injected before_derived_commit');
        }
    };

    expect(fn () => (new FlushLogBatches($lake, resolve(LakeWriterLock::class), $fault))->run(1))
        ->toThrow(RuntimeException::class, 'Injected before_derived_commit');
    expect(DB::table('wake_flush_batches')->where('batch_id', $batchId)->value('state'))->toBe('lake_committed')
        ->and(realLakeBatchRows($lake, $batchId))->toBe(1)
        ->and(DB::table('wake_event_locators')->count())->toBe(0)
        ->and(DB::table('wake_error_events')->count())->toBe(0)
        ->and(DB::table('wake_hourly_counts')->count())->toBe(0)
        ->and(DB::table('wake_connected_apps')->value('flushed_rows'))->toBe(0)
        ->and(DB::table('wake_staging_batches')->where('batch_id', $batchId)->value('status'))->toBe('flushing')
        ->and(DB::table('wake_staging_batches')->where('batch_id', $batchId)->value('payload'))->not->toBeNull();

    (new FlushLogBatches($lake, resolve(LakeWriterLock::class)))->run(1);
    expect(realLakeBatchRows($lake, $batchId))->toBe(1)
        ->and(DB::table('wake_event_locators')->where('event_id', $eventId)->count())->toBe(1)
        ->and(DB::table('wake_error_events')->where('event_id', $eventId)->count())->toBe(1)
        ->and(DB::table('wake_hourly_counts')->value('count'))->toBe(1)
        ->and(DB::table('wake_connected_apps')->value('flushed_rows'))->toBe(1)
        ->and(DB::table('wake_flush_batches')->where('batch_id', $batchId)->value('state'))->toBe('done')
        ->and(DB::table('wake_staging_batches')->where('batch_id', $batchId)->value('status'))->toBe('flushed')
        ->and(DB::table('wake_staging_batches')->where('batch_id', $batchId)->value('payload'))->toBeNull();
});

it('allows only one synchronized independent flusher to perform real lake work', function (): void {
    [$batchId, $eventId] = seedRealFlushBatch();
    $coordination = storage_path('framework/testing/workers/'.$this->catalogId);
    File::ensureDirectoryExists($coordination);
    $go = $coordination.'/go';
    $processes = [];
    $environment = realFlushWorkerEnvironment();

    for ($worker = 1; $worker <= 2; $worker++) {
        $ready = "{$coordination}/ready-{$worker}";
        $result = "{$coordination}/result-{$worker}.json";
        $process = new Process([
            PHP_BINARY, base_path('tests/Fixtures/real-lake-flush-worker.php'),
            $ready, $go, $result, $this->catalogId, $this->dataPath,
        ], base_path(), $environment);
        $process->start();
        $processes[] = ['process' => $process, 'ready' => $ready, 'result' => $result];
    }

    try {
        waitForRealFlushFiles(array_column($processes, 'ready'));
        touch($go);
        foreach ($processes as $worker) {
            $worker['process']->wait();
            expect($worker['process']->isSuccessful())->toBeTrue($worker['process']->getErrorOutput());
        }
        $results = array_map(fn (array $worker): array => json_decode((string) file_get_contents($worker['result']), true, 8, JSON_THROW_ON_ERROR), $processes);
        sort($results);

        expect(array_column($results, 'batches'))->toBe([0, 1])
            ->and(realLakeBatchRows(resolve(DuckDb::class), $batchId))->toBe(1)
            ->and(DB::table('wake_event_locators')->where('event_id', $eventId)->count())->toBe(1)
            ->and(DB::table('wake_error_events')->where('event_id', $eventId)->count())->toBe(1)
            ->and(DB::table('wake_hourly_counts')->value('count'))->toBe(1)
            ->and(DB::table('wake_connected_apps')->value('flushed_rows'))->toBe(1)
            ->and(DB::table('wake_flush_batches')->where('batch_id', $batchId)->value('state'))->toBe('done');
    } finally {
        foreach ($processes as $worker) {
            $worker['process']->stop();
        }
        File::deleteDirectory($coordination);
    }
});

it('executes Wake query search tail and context through the production DuckDB driver', function (): void {
    expect(is_executable(base_path('bin/duckdb')))->toBeTrue('Run scripts/install-duckdb.sh before the root test lane.');
    $lake = resolve(DuckDb::class);
    $queries = new WakeQueries($lake);
    $older = (string) Str::ulid();
    $target = (string) Str::ulid();
    $newest = (string) Str::ulid();
    $other = (string) Str::ulid();
    $row = static fn (string $eventId, string $app, string $at, string $message): array => [
        'event_id' => $eventId, 'app_id' => $app, 'occurred_at' => $at,
        'level' => 'info', 'channel' => 'app', 'message' => $message,
        'context' => ['source' => $app], 'extra' => [], 'exception' => null,
        'environment' => 'testing', 'hostname' => 'localhost', 'correlation_id' => 'query-proof',
    ];
    $lake->bootstrap();
    $lake->commitBatch((string) Str::ulid(), [
        $row($older, 'alpha', '2026-10-11T12:00:00.000000Z', 'needle older'),
        $row($target, 'alpha', '2026-10-11T12:02:00.000000Z', 'needle target'),
        $row($newest, 'alpha', '2026-10-11T12:03:00.000000Z', 'tail newest'),
        $row($other, 'beta', '2026-10-11T12:04:00.000000Z', 'needle other app'),
    ]);
    DB::table('wake_event_locators')->insert([
        'event_id' => $target, 'app_id' => 'alpha', 'event_date' => '2026-10-11',
        'occurred_at' => '2026-10-11 12:02:00+00',
    ]);
    $window = ['app' => 'alpha', 'from' => '2026-10-11T00:00:00Z', 'to' => '2026-10-12T00:00:00Z'];

    $search = $queries->search([...$window, 'text' => 'needle', 'limit' => 10]);
    $tail = $queries->tail([...$window, 'limit' => 2]);
    $tailNext = $queries->tail([...$window, 'cursor' => $tail['next_cursor'], 'limit' => 2]);
    $context = $queries->context('alpha', $target, 1, 1);

    expect(array_column($search['rows'], 'event_id'))->toBe([$target, $older])
        ->and(array_column($tail['rows'], 'event_id'))->toBe([$newest, $target])
        ->and($tail['next_cursor'])->toBeString()
        ->and(array_column($tailNext['rows'], 'event_id'))->toBe([$older])
        ->and(array_column($context['rows'], 'event_id'))->toBe([$older, $target, $newest])
        ->and(array_unique(array_column($context['rows'], 'app_id')))->toBe(['alpha'])
        ->and(array_column($search['rows'], 'event_id'))->not->toContain($other);
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

/** @return array<string, string> */
function realFlushWorkerEnvironment(): array
{
    $database = config('database.connections.pgsql');

    return [
        'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'),
        'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) $database['host'],
        'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => (string) $database['database'],
        'DB_USERNAME' => (string) $database['username'], 'DB_PASSWORD' => (string) $database['password'],
    ];
}

/** @param list<string> $files */
function waitForRealFlushFiles(array $files): void
{
    $deadline = microtime(true) + 10;
    while (collect($files)->contains(fn (string $file): bool => ! file_exists($file))) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for real flush workers.');
        }
        Sleep::usleep(1_000);
    }
}
