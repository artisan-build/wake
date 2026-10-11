<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Console\Commands;

use ArtisanBuild\WakeServer\Lake\LakeMaintenance;
use ArtisanBuild\WakeServer\Lake\LakeWriterLock;
use Illuminate\Console\Command;

final class CompactLake extends Command
{
    protected $signature = 'wake:lake-compact';

    protected $description = 'Compact adjacent Wake DuckLake files.';

    public function handle(LakeMaintenance $lake, LakeWriterLock $lock): int
    {
        $result = null;
        if (! $lock->execute(function () use ($lake, &$result): void {
            $result = $lake->compact();
        })) {
            $this->components->info('DuckLake writer is busy; compaction skipped.');

            return self::SUCCESS;
        }
        if (! is_array($result)) {
            return self::FAILURE;
        }
        $this->components->info("Compacted DuckLake files ({$result['files_before']} -> {$result['files_after']}).");

        return self::SUCCESS;
    }
}
