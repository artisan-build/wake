<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Lake;

interface LakeReader
{
    /** @return list<array<string, mixed>> */
    public function query(string $sql, int $timeout = 20): array;

    public function literal(?string $value): string;
}
