<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer;

use ArtisanBuild\WakeServer\Lake\DuckDb;
use ArtisanBuild\WakeServer\Lake\LakeWriter;
use Illuminate\Support\ServiceProvider;

final class WakeServerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/wake-server.php', 'wake-server');
        $this->app->singleton(LakeWriter::class, DuckDb::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
    }
}
