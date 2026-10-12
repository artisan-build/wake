<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\DuckDbInstaller;
use Illuminate\Console\Command;
use Throwable;

final class InstallDuckDb extends Command
{
    protected $signature = 'wake:install-duckdb';

    protected $description = 'Install the pinned DuckDB CLI and extensions for Wake.';

    public function handle(DuckDbInstaller $installer): int
    {
        try {
            $installed = $installer->install();
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info($installed ? 'DuckDB installed.' : 'DuckDB is already installed.');

        return self::SUCCESS;
    }
}
