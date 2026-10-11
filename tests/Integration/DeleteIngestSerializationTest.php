<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    Artisan::call('migrate:fresh', ['--force' => true]);
});

it('does not allow a pre-barrier in-flight ingest to survive app-log deletion', function (): void {
    $appId = 'delete-ingest-race';
    DB::table('wake_connected_apps')->insert([
        'id' => $appId, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $directory = storage_path('framework/testing/delete-ingest-'.Str::uuid());
    File::ensureDirectoryExists($directory);
    $ingestReady = $directory.'/ingest-ready';
    $ingestResult = $directory.'/ingest-result.json';
    $deleteStarted = $directory.'/delete-started';
    $deleteResult = $directory.'/delete-result.json';
    $applicationName = 'wake-delete-'.Str::uuid();
    $environment = deletionWorkerEnvironment();
    $ingest = new Process([PHP_BINARY, base_path('tests/Fixtures/pre-barrier-ingest-worker.php'), $ingestReady, $ingestResult, $appId], base_path(), $environment);
    $delete = new Process([PHP_BINARY, base_path('tests/Fixtures/delete-app-worker.php'), $deleteStarted, $deleteResult, $appId, $applicationName], base_path(), $environment);
    $ingest->setTimeout(15);
    $delete->setTimeout(15);

    try {
        $ingest->start();
        expect($ingest->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'INGEST_LOCKED')))->toBeTrue('Ingest worker did not acquire the connected-app row lock.');
        $delete->start();
        expect($delete->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'DELETE_STARTED')))->toBeTrue('Delete worker did not start.');

        $blockedOnAppLock = false;
        $deadline = hrtime(true) + 10_000_000_000;
        while ($delete->isRunning()) {
            $activity = DB::table('pg_stat_activity')->where('application_name', $applicationName)->first(['wait_event_type']);
            if (($activity->wait_event_type ?? null) === 'Lock') {
                $blockedOnAppLock = true;
                break;
            }
            if (hrtime(true) >= $deadline) {
                throw new RuntimeException('Delete worker neither completed nor blocked on the ingest row lock.');
            }
        }

        $ingest->signal(SIGCONT);
        $ingest->wait();
        $delete->wait();
        expect($ingest->isSuccessful())->toBeTrue($ingest->getErrorOutput().$ingest->getOutput())
            ->and($delete->isSuccessful())->toBeTrue($delete->getErrorOutput().$delete->getOutput())
            ->and(json_decode((string) file_get_contents($ingestResult), true, 8, JSON_THROW_ON_ERROR)['status'])->toBe(202);
        expect([
            'delete_blocked_on_ingest_lock' => $blockedOnAppLock,
            'surviving_target_staging' => DB::table('wake_staging_batches')->where('app_id', $appId)->count(),
        ])->toBe([
            'delete_blocked_on_ingest_lock' => true,
            'surviving_target_staging' => 0,
        ]);
    } finally {
        if ($ingest->isRunning()) {
            $ingest->signal(SIGCONT);
            $ingest->stop();
        }
        $delete->stop();
        File::deleteDirectory($directory);
    }
});

/** @return array<string, string> */
function deletionWorkerEnvironment(): array
{
    return [
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
    ];
}
