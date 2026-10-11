<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Lake;

interface LakeWriter
{
    public function bootstrap(): void;

    /** @param list<array<string, mixed>> $rows */
    public function commitBatch(string $batchId, array $rows): void;
}
