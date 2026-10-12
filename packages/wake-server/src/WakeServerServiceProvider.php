<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer;

use ArtisanBuild\WakeServer\Console\Commands\BootstrapLake;
use ArtisanBuild\WakeServer\Console\Commands\CompactLake;
use ArtisanBuild\WakeServer\Console\Commands\FlushLogs;
use ArtisanBuild\WakeServer\Console\Commands\RetainLogs;
use ArtisanBuild\WakeServer\Lake\BoundedLakeReader;
use ArtisanBuild\WakeServer\Lake\DuckDb;
use ArtisanBuild\WakeServer\Lake\LakeAppDeletion;
use ArtisanBuild\WakeServer\Lake\LakeMaintenance;
use ArtisanBuild\WakeServer\Lake\LakeReader;
use ArtisanBuild\WakeServer\Lake\LakeWriter;
use ArtisanBuild\WakeServer\Lake\LakeWriterLock;
use ArtisanBuild\WakeServer\Lake\PostgresLakeReadGate;
use ArtisanBuild\WakeServer\Lake\PostgresLakeWriterLock;
use Illuminate\Support\ServiceProvider;

final class WakeServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/wake-server.php', 'wake-server');
        $this->app->singleton(LakeWriter::class, DuckDb::class);
        $this->app->singleton(LakeAppDeletion::class, DuckDb::class);
        $this->app->singleton(LakeMaintenance::class, DuckDb::class);
        $this->app->singleton(LakeReader::class, fn (): BoundedLakeReader => new BoundedLakeReader(
            new DuckDb('wake-server.lake.read.threads', 'wake-server.lake.read.memory_limit'),
            new PostgresLakeReadGate,
        ));
        $this->app->singleton(LakeWriterLock::class, PostgresLakeWriterLock::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        if ($this->app->runningInConsole()) {
            $this->commands([BootstrapLake::class, FlushLogs::class, CompactLake::class, RetainLogs::class]);
        }
    }
}
