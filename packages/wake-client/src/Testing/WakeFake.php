<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeClient\Testing;

use ArtisanBuild\WakeClient\Contracts\Transport;
use ArtisanBuild\WakeContracts\IngestEnvelope;
use ArtisanBuild\WakeContracts\LogEvent;
use RuntimeException;

final class WakeFake implements Transport
{
    /** @var list<IngestEnvelope> */
    private array $batches = [];

    public function send(IngestEnvelope $envelope): bool
    {
        $this->batches[] = $envelope;

        return true;
    }

    /** @return list<IngestEnvelope> */
    public function batches(): array
    {
        return $this->batches;
    }

    /** @return list<LogEvent> */
    public function events(): array
    {
        return array_merge(...array_map(static fn (IngestEnvelope $batch): array => $batch->rows, $this->batches));
    }

    public function assertSentCount(int $count): void
    {
        if (count($this->events()) !== $count) {
            throw new RuntimeException("Expected Wake to capture {$count} events, captured ".count($this->events()).'.');
        }
    }
}
