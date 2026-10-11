<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Lake;

use DateTimeInterface;

interface LakeMaintenance
{
    /** @return array{files_before: int, files_after: int} */
    public function compact(): array;

    public function deleteBefore(DateTimeInterface $cutoff): int;

    public function expireSnapshots(DateTimeInterface $through): void;

    public function cleanupFiles(): void;
}
