<?php

declare(strict_types=1);

use App\Verification\ScaleCorpusLoader;
use App\Verification\ScaleCorpusPlan;
use ArtisanBuild\WakeServer\Lake\DuckDb;
use ArtisanBuild\WakeServer\Lake\LakeWriterLock;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->catalogId = 'wake_verify_'.strtolower((string) Str::ulid());
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

it('loads a tiny corpus into the real lake and matching query plane exactly once', function (): void {
    expect(is_executable(base_path('bin/duckdb')))->toBeTrue('Run scripts/install-duckdb.sh before this test.');
    $plan = new ScaleCorpusPlan('tiny-agreement', 12, 2, 2, 5);
    $lake = resolve(DuckDb::class);
    $loader = new ScaleCorpusLoader($lake, resolve(LakeWriterLock::class));

    $first = $loader->run($plan);
    $second = $loader->run($plan);
    $lakeGroups = $lake->query("SELECT app_id, environment, level, strftime(occurred_at, '%Y-%m-%d %H:00:00+00') AS hour, count(*) AS count FROM lake.logs WHERE starts_with(app_id, 'verify-tiny-agreement-app-') GROUP BY ALL ORDER BY ALL;");
    $postgresGroups = DB::table('wake_hourly_counts')->where('app_id', 'like', 'verify-tiny-agreement-app-%')
        ->orderBy('app_id')->orderBy('environment')->orderBy('level')->orderBy('hour')->get()
        ->map(fn (object $row): array => [
            'app_id' => $row->app_id, 'environment' => $row->environment, 'level' => $row->level,
            'hour' => gmdate('Y-m-d H:00:00+00', strtotime((string) $row->hour)), 'count' => (int) $row->count,
        ])->all();
    $lakeGroups = array_map(fn (array $row): array => [...$row, 'count' => (int) $row['count']], $lakeGroups);
    $lakeEventIds = array_column($lake->query("SELECT event_id FROM lake.logs WHERE starts_with(app_id, 'verify-tiny-agreement-app-') ORDER BY event_id;"), 'event_id');
    $postgresEventIds = DB::table('wake_event_locators')->where('app_id', 'like', 'verify-tiny-agreement-app-%')->orderBy('event_id')->pluck('event_id')->all();
    $apps = DB::table('wake_connected_apps')->where('id', 'like', 'verify-tiny-agreement-app-%')->orderBy('id')->get();

    expect($first)->toBe(['lake_rows' => 12, 'locators' => 12, 'errors' => 1, 'hourly_events' => 12])
        ->and($second)->toBe($first)
        ->and($lakeGroups)->toBe($postgresGroups)
        ->and($lakeEventIds)->toBe($postgresEventIds)
        ->and($lakeEventIds)->each->toMatch('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/')
        ->and(DB::table('wake_verification_chunks')->where('run_key', $plan->runKey)->pluck('batch_id')->all())->each->toMatch('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/')
        ->and($apps->pluck('accepted_rows')->map(fn (mixed $count): int => (int) $count)->all())->toBe([6, 6])
        ->and($apps->pluck('flushed_rows')->map(fn (mixed $count): int => (int) $count)->all())->toBe([6, 6])
        ->and($apps->pluck('last_client_version')->all())->toBe([$plan->clientVersion(), $plan->clientVersion()])
        ->and($apps->pluck('last_seen_at')->all())->toBe($apps->pluck('last_flushed_at')->all())
        ->and(array_map(fn (mixed $at): string => gmdate('Y-m-d\TH:i:s.000000\Z', strtotime((string) $at)), $apps->pluck('last_seen_at')->all()))->toBe([$plan->occurredAt(10), $plan->occurredAt(11)])
        ->and(DB::table('wake_verification_runs')->where('run_key', $plan->runKey)->value('completed_rows'))->toBe(12)
        ->and(DB::table('wake_verification_chunks')->where('run_key', $plan->runKey)->where('state', 'done')->count())->toBe(3)
        ->and(gmdate('Y-m-d\TH:i:s.000000\Z', strtotime((string) DB::table('wake_event_locators')->where('event_id', $plan->eventId(0))->value('occurred_at'))))->toBe($plan->occurredAt(0))
        ->and($lake->query('SELECT message FROM lake.logs WHERE event_id = '.$lake->literal($plan->eventId(0)).';')[0]['message'])->toBe('WAKE_VERIFICATION_KNOWN_EVENT tiny-agreement');
});

it('reconciles an interruption after the real lake commit without double counting', function (): void {
    $plan = new ScaleCorpusPlan('tiny-resume', 4, 1, 1, 4);
    $lake = resolve(DuckDb::class);
    $fault = static function (string $point): void {
        if ($point === 'after_lake_commit') {
            throw new RuntimeException('Injected after_lake_commit');
        }
    };

    expect(fn () => (new ScaleCorpusLoader($lake, resolve(LakeWriterLock::class), $fault))->run($plan))
        ->toThrow(RuntimeException::class, 'Injected after_lake_commit');
    $lake->commitBatch($plan->batchId(0), [[
        'event_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'app_id' => 'outside-day-decoy', 'occurred_at' => '2026-09-11T23:59:59.000000Z',
        'level' => 'info', 'channel' => 'stack', 'message' => 'outside day', 'context' => [], 'extra' => [], 'exception' => null,
        'environment' => 'testing', 'hostname' => 'localhost', 'correlation_id' => null,
    ]]);
    expect(verificationLakeRows($lake, $plan))->toBe(5)
        ->and(DB::table('wake_event_locators')->count())->toBe(0)
        ->and(DB::table('wake_hourly_counts')->count())->toBe(0)
        ->and(DB::table('wake_verification_chunks')->where('run_key', $plan->runKey)->value('state'))->toBe('planned');

    $result = (new ScaleCorpusLoader($lake, resolve(LakeWriterLock::class)))->run($plan);

    expect($result)->toBe(['lake_rows' => 4, 'locators' => 4, 'errors' => 1, 'hourly_events' => 4])
        ->and(verificationLakeRows($lake, $plan))->toBe(5)
        ->and(DB::table('wake_event_locators')->count())->toBe(4)
        ->and((int) DB::table('wake_hourly_counts')->sum('count'))->toBe(4)
        ->and((int) DB::table('wake_connected_apps')->value('accepted_rows'))->toBe(4)
        ->and((int) DB::table('wake_connected_apps')->value('flushed_rows'))->toBe(4)
        ->and(DB::table('wake_verification_chunks')->where('run_key', $plan->runKey)->value('state'))->toBe('done');
});

it('allows the final all-corpus summary longer than the default DuckDB timeout', function (): void {
    $binary = storage_path('framework/testing/slow-summary-duckdb-'.strtolower((string) Str::ulid()));
    File::put($binary, <<<'SH'
        #!/bin/sh
        input=$(cat)
        printf '%s\n' '{"marker":"__WAKE_RESULT_START__"}'
        case "$input" in
          *"starts_with(app_id"*) sleep 21; printf '%s\n' '{"rows":1}' ;;
          *"event_date IN"*) printf '%s\n' '{"rows":1}' ;;
        esac
        printf '%s\n' '{"marker":"__WAKE_RESULT_END__"}'
        SH);
    chmod($binary, 0755);
    $original = config('wake-server.lake.binary');
    config()->set('wake-server.lake.binary', $binary);

    try {
        $result = (new ScaleCorpusLoader(new DuckDb, resolve(LakeWriterLock::class)))->run(new ScaleCorpusPlan('slow-summary', 1, 1, 1, 1));

        expect($result)->toBe(['lake_rows' => 1, 'locators' => 1, 'errors' => 1, 'hourly_events' => 1]);
    } finally {
        config()->set('wake-server.lake.binary', $original);
        File::delete($binary);
    }
});

function verificationLakeRows(DuckDb $lake, ScaleCorpusPlan $plan): int
{
    $rows = $lake->query('SELECT count(*) AS rows FROM lake.logs WHERE batch_id = '.$lake->literal($plan->batchId(0)).';');

    return (int) ($rows[0]['rows'] ?? 0);
}
