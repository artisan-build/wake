<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Console\Commands;

use ArtisanBuild\WakeServer\Lake\FlushLogBatches;
use Illuminate\Console\Command;

final class FlushLogs extends Command
{
    protected $signature = 'wake:lake-flush {--limit=}';

    protected $description = 'Flush staged Wake events into DuckLake.';

    public function handle(FlushLogBatches $flush): int
    {
        $result = $flush->run($this->option('limit') === null ? null : (int) $this->option('limit'));
        $this->components->info("Flushed {$result['rows']} events from {$result['batches']} batches.");

        return self::SUCCESS;
    }
}
