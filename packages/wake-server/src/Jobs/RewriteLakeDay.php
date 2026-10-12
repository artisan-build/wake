<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Jobs;

use ArtisanBuild\WakeServer\Lake\LakeMaintenance;
use ArtisanBuild\WakeServer\Lake\LakeWriterLock;
use Illuminate\Contracts\Queue\ShouldQueue;
use InvalidArgumentException;
use RuntimeException;

final class RewriteLakeDay implements ShouldQueue
{
    public string $connection = 'cloud';

    public string $queue = 'wake-lake';

    public int $timeout = 85;

    public int $tries = 3;

    public function __construct(public readonly string $day)
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) !== 1) {
            throw new InvalidArgumentException('A lake rewrite job requires an explicit UTC day.');
        }
    }

    public function handle(LakeMaintenance $lake, LakeWriterLock $lock): void
    {
        if (! $lock->execute(fn (): bool => $lake->rewriteDay($this->day))) {
            throw new RuntimeException('The shared DuckLake writer is busy.');
        }
    }
}
