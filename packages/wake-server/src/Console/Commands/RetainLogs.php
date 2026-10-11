<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Console\Commands;

use ArtisanBuild\WakeServer\Lake\RetainLogs as Retention;
use Illuminate\Console\Command;

final class RetainLogs extends Command
{
    protected $signature = 'wake:lake-retain {--days=}';

    protected $description = 'Remove expired whole-day Wake log data.';

    public function handle(Retention $retention): int
    {
        $result = $retention->run($this->option('days') === null ? null : (int) $this->option('days'));
        $removed = $result['lake_rows'] + $result['locators'] + $result['hourly'] + $result['errors'];
        $this->components->info("Retention removed {$removed} rows before {$result['cutoff']} UTC.");

        return self::SUCCESS;
    }
}
