<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Ingest;

final readonly class IngestDecision
{
    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public int $status,
        public array $body,
        public array $headers = [],
    ) {}
}
