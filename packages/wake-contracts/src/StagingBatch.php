<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeContracts;

use ArtisanBuild\WakeContracts\Contracts\ArrayContract;
use ArtisanBuild\WakeContracts\Contracts\SerializesContract;
use ArtisanBuild\WakeContracts\Enums\StagingBatchStatus;
use ArtisanBuild\WakeContracts\Exceptions\ContractValidationException;
use ArtisanBuild\WakeContracts\Support\ContractFormat;
use ArtisanBuild\WakeContracts\Support\ContractInput;
use ValueError;

final readonly class StagingBatch implements ArrayContract
{
    use SerializesContract;

    public const int MAX_APP_ID_BYTES = 255;

    public function __construct(
        public string $batchId,
        public string $appId,
        public string $idempotencyKey,
        public int $envelopeVersion,
        public string $clientVersion,
        public int $droppedCount,
        public IngestEnvelope $payload,
        public int $rowCount,
        public StagingBatchStatus $status,
        public string $createdAt,
        public string $updatedAt,
        public ?string $claimedAt = null,
        public ?string $flushedAt = null,
    ) {
        ContractFormat::ulid($batchId, '$.batch_id');
        ContractInput::boundedString($appId, '$.app_id', self::MAX_APP_ID_BYTES);
        ContractFormat::idempotencyKey($idempotencyKey, '$.idempotency_key');
        IngestProtocol::assertSupportedEnvelopeVersion($envelopeVersion);
        ContractInput::boundedString($clientVersion, '$.client_version', IngestEnvelope::MAX_CLIENT_VERSION_BYTES);

        if ($droppedCount < 0) {
            throw ContractValidationException::at('$.dropped_count', 'must be zero or greater');
        }

        if ($idempotencyKey !== $payload->idempotencyKey
            || $envelopeVersion !== $payload->envelopeVersion
            || $clientVersion !== $payload->clientVersion
            || $droppedCount !== $payload->droppedCount) {
            throw ContractValidationException::at('$.payload', 'metadata must match the staging columns');
        }

        if ($rowCount !== count($payload->rows)) {
            throw ContractValidationException::at('$.row_count', 'must match the number of payload rows');
        }

        ContractFormat::timestamp($createdAt, '$.created_at');
        ContractFormat::timestamp($updatedAt, '$.updated_at');

        if ($claimedAt !== null) {
            ContractFormat::timestamp($claimedAt, '$.claimed_at');
        }

        if ($flushedAt !== null) {
            ContractFormat::timestamp($flushedAt, '$.flushed_at');
        }
    }

    public static function fromEnvelope(
        string $authenticatedAppId,
        string $batchId,
        IngestEnvelope $envelope,
        string $acceptedAt,
    ): self {
        return new self(
            batchId: $batchId,
            appId: $authenticatedAppId,
            idempotencyKey: $envelope->idempotencyKey,
            envelopeVersion: $envelope->envelopeVersion,
            clientVersion: $envelope->clientVersion,
            droppedCount: $envelope->droppedCount,
            payload: $envelope,
            rowCount: count($envelope->rows),
            status: StagingBatchStatus::Pending,
            createdAt: $acceptedAt,
            updatedAt: $acceptedAt,
        );
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        ContractInput::assertShape(
            $input,
            [
                'batch_id', 'app_id', 'idempotency_key', 'envelope_version', 'client_version',
                'dropped_count', 'payload', 'row_count', 'status', 'created_at', 'updated_at',
            ],
            ['claimed_at', 'flushed_at'],
        );

        $payload = ContractInput::array($input, 'payload');
        if (array_is_list($payload)) {
            throw ContractValidationException::at('$.payload', 'must be an object');
        }

        /** @var array<string, mixed> $payload */
        $envelope = IngestEnvelope::fromArray($payload);
        $statusValue = ContractInput::string($input, 'status');

        try {
            $status = StagingBatchStatus::from($statusValue);
        } catch (ValueError) {
            throw ContractValidationException::at('$.status', 'is not a supported staging status');
        }

        return new self(
            batchId: ContractInput::string($input, 'batch_id'),
            appId: ContractInput::string($input, 'app_id'),
            idempotencyKey: ContractInput::string($input, 'idempotency_key'),
            envelopeVersion: ContractInput::integer($input, 'envelope_version'),
            clientVersion: ContractInput::string($input, 'client_version'),
            droppedCount: ContractInput::integer($input, 'dropped_count'),
            payload: $envelope,
            rowCount: ContractInput::integer($input, 'row_count'),
            status: $status,
            createdAt: ContractInput::string($input, 'created_at'),
            updatedAt: ContractInput::string($input, 'updated_at'),
            claimedAt: ContractInput::nullableString($input, 'claimed_at'),
            flushedAt: ContractInput::nullableString($input, 'flushed_at'),
        );
    }

    public static function fromJson(string $json): self
    {
        return self::fromArray(ContractInput::decodeObject($json));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $result = [
            'batch_id' => $this->batchId,
            'app_id' => $this->appId,
            'idempotency_key' => $this->idempotencyKey,
            'envelope_version' => $this->envelopeVersion,
            'client_version' => $this->clientVersion,
            'dropped_count' => $this->droppedCount,
            'payload' => $this->payload->toArray(),
            'row_count' => $this->rowCount,
            'status' => $this->status->value,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];

        if ($this->claimedAt !== null) {
            $result['claimed_at'] = $this->claimedAt;
        }

        if ($this->flushedAt !== null) {
            $result['flushed_at'] = $this->flushedAt;
        }

        return $result;
    }
}
