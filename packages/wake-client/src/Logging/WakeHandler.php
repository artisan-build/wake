<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeClient\Logging;

use ArtisanBuild\WakeClient\Contracts\PayloadFilter;
use ArtisanBuild\WakeContracts\AcceptedReceipt;
use ArtisanBuild\WakeContracts\IngestEnvelope;
use ArtisanBuild\WakeContracts\IngestProtocol;
use ArtisanBuild\WakeContracts\LogEvent;
use Illuminate\Http\Client\Factory;
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

    public function __construct(
        private readonly RecordNormalizer $normalizer,
        private readonly PayloadFilter $filter,
        private readonly Factory $http,
        private readonly ?string $url,
        private readonly ?string $token,
        private readonly int $batchSize,
        private readonly int $maxBuffer,
        private readonly float $connectTimeout,
        private readonly float $timeout,
    ) {
        parent::__construct();
    }

    public function handle(LogRecord $record): bool
    {
        if (! $this->configured() || $this->delivering) {
            return false;
        }

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
        }

        return false;
    }

    public function flush(): void
    {
        if ($this->buffer === [] || ! $this->configured() || $this->delivering) {
            return;
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
            $response = $this->http
                ->withToken((string) $this->token)
                ->acceptJson()
                ->asJson()
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->withHeaders([IngestProtocol::IDEMPOTENCY_HEADER => $key])
                ->post(rtrim((string) $this->url, '/').IngestProtocol::PATH, $envelope->toArray());

            if ($response->status() !== IngestProtocol::ACCEPTED_STATUS) {
                throw new \RuntimeException('Wake rejected the batch.');
            }

            $receipt = AcceptedReceipt::fromArray($response->json());
            if ($receipt->acceptedCount !== count($rows)) {
                throw new \RuntimeException('Wake returned an invalid receipt.');
            }

            $this->droppedCount = max(0, $this->droppedCount - $reportedDrops);
        } catch (Throwable) {
            $this->droppedCount += count($rows);
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

    private function configured(): bool
    {
        return is_string($this->url) && $this->url !== '' && is_string($this->token) && $this->token !== '';
    }
}
