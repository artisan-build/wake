<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Lake;

use DateTimeInterface;

interface LakeMaintenance
{
    /** @return list<string> */
    public function fragmentedDays(int $limit = 3): array;

    public function rewriteDay(string $day): bool;

    public function deleteBefore(DateTimeInterface $cutoff): int;

    public function expireSnapshots(DateTimeInterface $through): void;

    public function cleanupFiles(): void;
}
