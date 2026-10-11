<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeContracts;

use ArtisanBuild\WakeContracts\Exceptions\ContractValidationException;
use ArtisanBuild\WakeContracts\Support\ContractFormat;

final class IngestProtocol
{
    public const string PATH = '/api/v1/ingest';

    public const string AUTHORIZATION_HEADER = 'Authorization';

    public const string AUTHORIZATION_SCHEME = 'Bearer';

    public const string IDEMPOTENCY_HEADER = 'Idempotency-Key';

    public const string CONTENT_TYPE_HEADER = 'Content-Type';

    public const string CONTENT_TYPE = 'application/json';

    public const string RETRY_AFTER_HEADER = 'Retry-After';

    public const int CURRENT_ENVELOPE_VERSION = 1;

    /**
     * Add older versions here while their parsers remain supported.
     *
     * @var list<int>
     */
    public const array SUPPORTED_ENVELOPE_VERSIONS = [1];

    public const int MIN_ROWS = 1;

    public const int MAX_ROWS = 500;

    public const int MAX_REQUEST_BYTES = 1_048_576;

    public const int ACCEPTED_STATUS = 202;

    public const int UNAUTHORIZED_STATUS = 401;

    public const int FORBIDDEN_STATUS = 403;

    public const int PAYLOAD_TOO_LARGE_STATUS = 413;

    public const int UNPROCESSABLE_STATUS = 422;

    public const int RATE_LIMITED_STATUS = 429;

    /** @var list<int> */
    public const array ERROR_STATUSES = [
        self::UNAUTHORIZED_STATUS,
        self::FORBIDDEN_STATUS,
        self::PAYLOAD_TOO_LARGE_STATUS,
        self::UNPROCESSABLE_STATUS,
        self::RATE_LIMITED_STATUS,
    ];

    public static function assertSupportedEnvelopeVersion(int $version): void
    {
        if ($version < 1) {
            throw ContractValidationException::at('$.envelope_version', 'must be a positive integer');
        }

        if ($version > self::CURRENT_ENVELOPE_VERSION) {
            throw ContractValidationException::unsupportedEnvelopeVersion($version, self::CURRENT_ENVELOPE_VERSION);
        }

        if (! in_array($version, self::supportedEnvelopeVersions(), true)) {
            throw ContractValidationException::at(
                '$.envelope_version',
                "envelope version {$version} is no longer supported",
            );
        }
    }

    public static function acceptedStatus(bool $duplicate): int
    {
        return self::ACCEPTED_STATUS;
    }

    public static function assertIdempotencyHeader(string $headerValue, IngestEnvelope $envelope): void
    {
        ContractFormat::idempotencyKey($headerValue, self::IDEMPOTENCY_HEADER);

        if ($headerValue !== $envelope->idempotencyKey) {
            throw ContractValidationException::at(
                self::IDEMPOTENCY_HEADER,
                'must exactly match $.idempotency_key',
            );
        }
    }

    /** @return list<int> */
    private static function supportedEnvelopeVersions(): array
    {
        return self::SUPPORTED_ENVELOPE_VERSIONS;
    }
}
