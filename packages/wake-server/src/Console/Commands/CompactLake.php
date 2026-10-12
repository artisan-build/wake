<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Console\Commands;

use ArtisanBuild\WakeServer\Jobs\RewriteLakeDay;
use ArtisanBuild\WakeServer\Lake\LakeMaintenance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

final class CompactLake extends Command
{
    protected $signature = 'wake:lake-compact';

    protected $description = 'Dispatch bounded Wake DuckLake day rewrites.';

    public function handle(LakeMaintenance $lake): int
    {
        $days = $lake->fragmentedDays(3);
        if ($days === []) {
            $this->components->info('No fragmented DuckLake days to dispatch.');

            return self::SUCCESS;
        }
        foreach ($days as $day) {
            Queue::connection('cloud')->pushOn('wake-lake', new RewriteLakeDay($day));
        }
        $this->components->info('Dispatched '.count($days).' DuckLake day rewrite job(s).');

        return self::SUCCESS;
    }
}
