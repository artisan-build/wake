<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeClient\Transport;

use ArtisanBuild\WakeClient\Contracts\Transport;
use ArtisanBuild\WakeContracts\AcceptedReceipt;
use ArtisanBuild\WakeContracts\IngestEnvelope;
use ArtisanBuild\WakeContracts\IngestProtocol;
use Illuminate\Http\Client\Factory;
use Throwable;

final class HttpTransport implements Transport
{
    /** @var array<string, mixed> */
    private array $lastOptions = [];

    private int $attempts = 0;

    public function __construct(
        private readonly Factory $http,
        private readonly string $url,
        private readonly string $token,
        private readonly float $connectTimeout,
        private readonly float $timeout,
    ) {}

    public function send(IngestEnvelope $envelope): bool
    {
        try {
            $this->attempts++;
            $response = $this->http
                ->withToken($this->token)
                ->acceptJson()
                ->asJson()
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->beforeSending(function ($request, array $options): void {
                    $this->lastOptions = $options;
                })
                ->withHeaders([IngestProtocol::IDEMPOTENCY_HEADER => $envelope->idempotencyKey])
                ->post(rtrim($this->url, '/').IngestProtocol::PATH, $envelope->toArray());

            if ($response->status() !== IngestProtocol::ACCEPTED_STATUS) {
                return false;
            }

            return AcceptedReceipt::fromArray($response->json())->acceptedCount === count($envelope->rows);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function lastOptions(): array
    {
        return $this->lastOptions;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }
}
