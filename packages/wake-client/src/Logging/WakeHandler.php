<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeClient\Logging;

use ArtisanBuild\WakeClient\Contracts\PayloadFilter;
use ArtisanBuild\WakeClient\Contracts\Transport;
use ArtisanBuild\WakeContracts\IngestEnvelope;
use ArtisanBuild\WakeContracts\IngestProtocol;
use ArtisanBuild\WakeContracts\LogEvent;
use Illuminate\Support\Str;
use Monolog\Handler\AbstractHandler;
use Monolog\LogRecord;
use Throwable;

final class WakeHandler extends AbstractHandler
{
    /** @var list<LogEvent> */
    private array $buffer = [];

    private int $droppedCount = 0;

    private bool $delivering = false;

    private bool $handling = false;

    private ?bool $lastDeliverySucceeded = null;

    public function __construct(
        private readonly RecordNormalizer $normalizer,
        private readonly PayloadFilter $filter,
        private readonly Transport $transport,
        private readonly ?string $url,
        private readonly ?string $token,
        private readonly int $batchSize,
        private readonly int $maxBuffer,
    ) {
        parent::__construct();
    }

    /** @phpstan-impure */
    public function handle(LogRecord $record): bool
    {
        if (! $this->configured() || $this->delivering || $this->handling) {
            return false;
        }

        $this->handling = true;
        try {
            $event = $this->filter->filter($this->normalizer->normalize($record));
            if ($event === null || count($this->buffer) >= $this->maxBuffer) {
                $this->droppedCount++;

                return false;
            }

            $this->buffer[] = $event;
            if (count($this->buffer) >= $this->batchSize) {
                $this->flush();
            }
        } catch (Throwable) {
            $this->droppedCount++;
        } finally {
            $this->handling = false;
        }

        return false;
    }

    /** @phpstan-impure */
    public function flush(): bool
    {
        if ($this->buffer === [] || ! $this->configured() || $this->delivering) {
            return false;
        }

        $rows = array_splice($this->buffer, 0, min($this->batchSize, IngestProtocol::MAX_ROWS));
        $reportedDrops = $this->droppedCount;
        $key = (string) Str::ulid();

        try {
            $envelope = new IngestEnvelope(
                envelopeVersion: IngestProtocol::CURRENT_ENVELOPE_VERSION,
                clientVersion: '0.1.0',
                droppedCount: $reportedDrops,
                idempotencyKey: $key,
                rows: $rows,
            );

            $this->delivering = true;
            $this->lastDeliverySucceeded = $this->transport->send($envelope);
            if (! $this->lastDeliverySucceeded) {
                throw new \RuntimeException('Wake rejected the batch.');
            }

            $this->droppedCount = max(0, $this->droppedCount - $reportedDrops);

            return true;
        } catch (Throwable) {
            $this->droppedCount += count($rows);

            return false;
        } finally {
            $this->delivering = false;
        }
    }

    public function close(): void
    {
        $this->flush();
    }

    /** @return array{buffered: int, dropped: int} */
    public function state(): array
    {
        return ['buffered' => count($this->buffer), 'dropped' => $this->droppedCount];
    }

    public function lastDeliverySucceeded(): ?bool
    {
        return $this->lastDeliverySucceeded;
    }

    public function probe(LogRecord $record): bool
    {
        $this->lastDeliverySucceeded = null;
        $this->handle($record);
        if ($this->lastDeliverySucceeded === null) {
            $this->flush();
        }

        return $this->lastDeliverySucceeded === true;
    }

    private function configured(): bool
    {
        return is_string($this->url) && $this->url !== '' && is_string($this->token) && $this->token !== '';
    }
}
