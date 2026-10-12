<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Lake;

final readonly class BoundedLakeReader implements LakeReader
{
    public function __construct(
        private DuckDb $duckDb,
        private PostgresLakeReadGate $gate,
    ) {}

    public function query(string $sql, int $timeout = 20): array
    {
        return $this->gate->execute(fn (): array => $this->duckDb->query($sql, $timeout));
    }

    public function literal(?string $value): string
    {
        return $this->duckDb->literal($value);
    }
}
