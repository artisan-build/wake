<?php

declare(strict_types=1);

use App\Services\DeleteAppLogs;
use ArtisanBuild\WakeServer\Lake\LakeAppDeletion;
use ArtisanBuild\WakeServer\Lake\LakeWriterLock;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

final class PostgresDeletionProbeLake implements LakeAppDeletion
{
    public function countAppRows(string $appId): int
    {
        return DB::table('wake_event_locators')->where('app_id', $appId)->count();
    }

    public function deleteAppRows(string $appId): int
    {
        DB::selectOne('SELECT 1 AS probed');

        return 0;
    }

    public function expireSnapshots(DateTimeInterface $through): void
    {
        DB::selectOne('SELECT 1 AS expired');
    }

    public function cleanupFiles(): void
    {
        DB::selectOne('SELECT 1 AS cleaned');
    }
}

[$script, $started, $result, $appId, $applicationName] = $argv;
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DB::selectOne("SELECT set_config('application_name', ?, false)", [$applicationName]);
file_put_contents($started, 'started');
echo "DELETE_STARTED\n";
flush();

$deleted = (new DeleteAppLogs(
    new PostgresDeletionProbeLake,
    resolve(LakeWriterLock::class),
))->run($appId);

file_put_contents($result, json_encode($deleted, JSON_THROW_ON_ERROR));
