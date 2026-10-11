<?php

declare(strict_types=1);

use ArtisanBuild\WakeServer\Lake\DuckDb;
use ArtisanBuild\WakeServer\Lake\FlushLogBatches;
use ArtisanBuild\WakeServer\Lake\LakeWriterLock;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Sleep;

require dirname(__DIR__, 2).'/vendor/autoload.php';

[, $ready, $go, $result, $metadataSchema, $dataPath] = $argv;
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config()->set('wake-server.lake.postgres_catalog', true);
config()->set('wake-server.lake.metadata_schema', $metadataSchema);
config()->set('wake-server.lake.data_path', $dataPath);
file_put_contents($ready, 'ready');

$deadline = microtime(true) + 10;
while (! file_exists($go)) {
    if (microtime(true) >= $deadline) {
        exit(2);
    }
    Sleep::usleep(1_000);
}

$fault = static function (string $point): void {
    if ($point === 'after_lake_commit') {
        Sleep::usleep(500_000);
    }
};
$flush = new FlushLogBatches($app->make(DuckDb::class), $app->make(LakeWriterLock::class), $fault);
file_put_contents($result, json_encode($flush->run(1), JSON_THROW_ON_ERROR));
