<?php

declare(strict_types=1);

use ArtisanBuild\WakeServer\Lake\DuckDb;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function (): void {
    clearRealDuckLakeCatalog();
    $this->catalogId = 'wake_lake_'.strtolower((string) Str::ulid());
    $this->dataPath = storage_path('framework/testing/ducklake/'.$this->catalogId.'/');
    File::ensureDirectoryExists($this->dataPath);
    config()->set('wake-server.lake.postgres_catalog', true);
    config()->set('wake-server.lake.data_path', $this->dataPath);
});

afterEach(function (): void {
    config()->set('wake-server.lake.postgres_catalog', false);
    config()->set('wake-server.lake.data_path');
    clearRealDuckLakeCatalog();
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
    expect($lake->batchCommitted($batchId, $days))->toBeFalse();
    $lake->commitBatch($batchId, [$row]);
    expect($lake->batchCommitted($batchId, $days))->toBeTrue();
    if (! $lake->batchCommitted($batchId, $days)) {
        $lake->commitBatch($batchId, [$row]);
    }
    $result = $lake->query('SELECT count(*) AS rows FROM lake.logs WHERE batch_id = '.$lake->literal($batchId).';');

    expect($result[0]['rows'] ?? null)->toBe(1);
});

function clearRealDuckLakeCatalog(): void
{
    $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'ducklake_%'");
    foreach ($tables as $table) {
        $name = is_object($table) ? ($table->tablename ?? null) : null;
        if (! is_string($name) || preg_match('/^ducklake_[a-z0-9_]+$/D', $name) !== 1) {
            throw new RuntimeException('Unexpected DuckLake catalog table name.');
        }
        DB::statement('DROP TABLE IF EXISTS public."'.$name.'" CASCADE');
    }
}
