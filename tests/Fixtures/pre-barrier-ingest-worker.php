<?php

declare(strict_types=1);

use ArtisanBuild\WakeContracts\IngestEnvelope;
use ArtisanBuild\WakeServer\Ingest\IngestService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[$script, $ready, $result, $appId] = $argv;
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

DB::beginTransaction();
DB::table('wake_connected_apps')->where('id', $appId)->lockForUpdate()->firstOrFail();
file_put_contents($ready, 'locked');
echo "INGEST_LOCKED\n";
flush();
posix_kill(getmypid(), SIGSTOP);

$decision = resolve(IngestService::class)->accept($appId, IngestEnvelope::fromArray([
    'envelope_version' => 1,
    'client_version' => 'delete-race-test',
    'dropped_count' => 0,
    'idempotency_key' => 'pre-barrier-ingest',
    'rows' => [[
        'event_id' => '01K7F5M9YZ0000000000000001',
        'occurred_at' => '2026-10-12T00:00:00.000000Z',
        'level' => 'info',
        'channel' => 'test',
        'message' => 'pre-barrier ingest',
        'context' => [],
        'extra' => [],
        'environment' => 'testing',
        'hostname' => 'localhost',
    ]],
]));
DB::commit();

file_put_contents($result, json_encode(['status' => $decision->status], JSON_THROW_ON_ERROR));
