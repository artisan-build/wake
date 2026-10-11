<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Console\Commands;

use ArtisanBuild\WakeServer\Lake\LakeWriter;
use Illuminate\Console\Command;

final class BootstrapLake extends Command
{
    protected $signature = 'wake:lake-bootstrap';

    protected $description = 'Create the Wake DuckLake tables.';

    public function handle(LakeWriter $lake): int
    {
        $lake->bootstrap();
        $this->components->info('Wake DuckLake is ready.');

        return self::SUCCESS;
    }
}
