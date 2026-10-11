<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\WakeContracts\IngestProtocol;
use ArtisanBuild\WakeServer\Models\ConnectedApp;
use ArtisanBuild\WakeServer\Models\StagingBatch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    Artisan::call('migrate:fresh', ['--force' => true]);
});

function concurrentApp(string $id, array $attributes = []): ConnectedApp
{
    return ConnectedApp::query()->create($attributes + ['id' => $id, 'enabled' => true]);
}

function concurrentCredential(string $appId, string $secret): void
{
    Credential::query()->create([
        'kind' => CredentialKind::Bearer,
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => $appId,
        'name' => 'Concurrent ingest credential',
        'status' => CredentialStatus::Active,
        'secret_hash' => hash('sha256', $secret),
    ]);
}

/** @return array<string, mixed> */
function concurrentEnvelope(string $key, int $dropped = 0): array
{
    return [
        'envelope_version' => 1,
        'client_version' => 'concurrency-test',
        'dropped_count' => $dropped,
        'idempotency_key' => $key,
        'rows' => [[
            'event_id' => (string) Str::ulid(),
            'occurred_at' => '2026-10-11T12:34:56.123456+00:00',
            'level' => 'info',
            'channel' => 'test',
            'message' => 'Concurrent event',
            'context' => [],
            'extra' => [],
            'environment' => 'testing',
            'hostname' => 'localhost',
        ]],
    ];
}

/**
 * @param  list<array{secret: string, key: string, body: array<string, mixed>}>  $submissions
 * @return list<array{status: int, body: array<string, mixed>}>
 */
function runConcurrentIngest(array $submissions): array
{
    $directory = storage_path('framework/testing/concurrency-'.Str::uuid());
    File::ensureDirectoryExists($directory);
    $go = $directory.'/go';
    $processes = [];
    $results = [];

    foreach ($submissions as $index => $submission) {
        $ready = "{$directory}/ready-{$index}";
        $result = "{$directory}/result-{$index}";
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Fixtures/concurrent-ingest-worker.php'),
            $ready,
            $go,
            $result,
            $submission['secret'],
            $submission['key'],
            base64_encode(json_encode($submission['body'], JSON_THROW_ON_ERROR)),
        ], base_path(), [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',
            'AUTH_GUARD' => 'unsupported',
            'AUTH_MODEL' => 'Unsupported\\User',
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '5432',
            'DB_DATABASE' => 'wake_test',
            'DB_USERNAME' => 'root',
            'DB_PASSWORD' => '',
            'DB_URL' => '',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
        ]);
        $process->setTimeout(15);
        $process->start();
        $processes[] = [$process, $ready, $result];
    }

    $deadline = microtime(true) + 10;
    while (collect($processes)->contains(fn (array $entry): bool => ! file_exists($entry[1]))) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Concurrent ingest workers did not reach the barrier.');
        }

        Sleep::usleep(1_000);
    }

    file_put_contents($go, 'go');

    foreach ($processes as [$process, $ready, $result]) {
        $process->wait();
        if (! $process->isSuccessful()) {
            throw new RuntimeException($process->getErrorOutput().$process->getOutput());
        }

        $decoded = json_decode((string) file_get_contents($result), true, 512, JSON_THROW_ON_ERROR);
        $results[] = $decoded;
    }

    File::deleteDirectory($directory);

    return $results;
}

it('serializes concurrent first submissions for one idempotency key', function (): void {
    $app = concurrentApp('same-key-app');
    concurrentCredential($app->id, 'same-key-secret');
    $key = 'concurrent-same-key';

    $responses = runConcurrentIngest([
        ['secret' => 'same-key-secret', 'key' => $key, 'body' => concurrentEnvelope($key, 3)],
        ['secret' => 'same-key-secret', 'key' => $key, 'body' => concurrentEnvelope($key, 7)],
    ]);

    expect(array_column($responses, 'status'))->each->toBe(202)
        ->and(array_unique(array_column(array_column($responses, 'body'), 'batch_id')))->toHaveCount(1)
        ->and(array_column(array_column($responses, 'body'), 'accepted_count'))->each->toBe(1)
        ->and(array_column(array_column($responses, 'body'), 'duplicate'))->toContain(false, true)
        ->and(StagingBatch::query()->where('app_id', $app->id)->count())->toBe(1)
        ->and(DB::table('wake_ingest_windows')->where('app_id', $app->id)->value('batch_count'))->toBe(1)
        ->and(DB::table('wake_ingest_windows')->where('app_id', $app->id)->value('event_count'))->toBe(1);

    $app->refresh();
    $batch = StagingBatch::query()->where('app_id', $app->id)->sole();
    expect($app->accepted_rows)->toBe(1)
        ->and($app->dropped_rows)->toBe($batch->dropped_count);
});

it('admits exactly one concurrent new key at the final cap slot', function (): void {
    $app = concurrentApp('cap-race-app', ['batches_per_minute' => 2, 'events_per_minute' => 2]);
    concurrentCredential($app->id, 'cap-race-secret');

    $this->postJson(IngestProtocol::PATH, concurrentEnvelope('cap-seed'), [
        'Authorization' => 'Bearer cap-race-secret',
        IngestProtocol::IDEMPOTENCY_HEADER => 'cap-seed',
    ])->assertAccepted();

    $responses = runConcurrentIngest([
        ['secret' => 'cap-race-secret', 'key' => 'cap-race-a', 'body' => concurrentEnvelope('cap-race-a')],
        ['secret' => 'cap-race-secret', 'key' => 'cap-race-b', 'body' => concurrentEnvelope('cap-race-b')],
    ]);

    $statuses = array_column($responses, 'status');
    sort($statuses);
    expect($statuses)->toBe([202, 429])
        ->and(StagingBatch::query()->where('app_id', $app->id)->count())->toBe(2)
        ->and(DB::table('wake_ingest_windows')->where('app_id', $app->id)->value('batch_count'))->toBe(2)
        ->and(DB::table('wake_ingest_windows')->where('app_id', $app->id)->value('event_count'))->toBe(2);

    $app->refresh();
    expect($app->accepted_rows)->toBe(2)
        ->and($app->rate_limit_hits)->toBe(1)
        ->and($app->rejected_batches)->toBe(1);
});
