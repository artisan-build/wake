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
                    $this->lastOptions = $this->stringKeyed($options);
                })
                ->withHeaders([IngestProtocol::IDEMPOTENCY_HEADER => $envelope->idempotencyKey])
                ->post(rtrim($this->url, '/').IngestProtocol::PATH, $envelope->toArray());

            if ($response->status() !== IngestProtocol::ACCEPTED_STATUS) {
                return false;
            }

            $contentType = strtolower(trim(explode(';', $response->header('Content-Type'), 2)[0]));
            if ($contentType !== IngestProtocol::CONTENT_TYPE) {
                return false;
            }

            $body = $response->json();
            if (! is_array($body)) {
                return false;
            }

            return AcceptedReceipt::fromArray($this->stringKeyed($body))->acceptedCount === count($envelope->rows);
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

    /**
     * Convert an arbitrary decoded/options array to a contract object.
     *
     * @param  array<mixed, mixed>  $values
     * @return array<string, mixed>
     */
    private function stringKeyed(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
