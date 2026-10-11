<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Console\Commands;

use ArtisanBuild\WakeServer\Lake\LakeMaintenance;
use Illuminate\Console\Command;

final class CompactLake extends Command
{
    protected $signature = 'wake:lake-compact';

    protected $description = 'Compact adjacent Wake DuckLake files.';

    public function handle(LakeMaintenance $lake): int
    {
        $result = $lake->compact();
        $this->components->info("Compacted DuckLake files ({$result['files_before']} -> {$result['files_after']}).");

        return self::SUCCESS;
    }
}
