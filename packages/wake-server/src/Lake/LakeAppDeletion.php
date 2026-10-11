<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Lake;

use DateTimeInterface;

interface LakeAppDeletion
{
    public function countAppRows(string $appId): int;

    public function deleteAppRows(string $appId): int;

    public function expireSnapshots(DateTimeInterface $through): void;

    public function cleanupFiles(): void;
}
