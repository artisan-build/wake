<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Lake;

interface LakeWriter
{
    public function bootstrap(): void;

    /** @param list<string> $eventDays */
    public function batchCommitted(string $batchId, array $eventDays): bool;

    /** @param list<array<string, mixed>> $rows */
    public function commitBatch(string $batchId, array $rows): void;
}
