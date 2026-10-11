<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeContracts;

use ArtisanBuild\WakeContracts\Contracts\ArrayContract;
use ArtisanBuild\WakeContracts\Contracts\SerializesContract;
use ArtisanBuild\WakeContracts\Exceptions\ContractValidationException;
use ArtisanBuild\WakeContracts\Support\ContractFormat;
use ArtisanBuild\WakeContracts\Support\ContractInput;

final readonly class AcceptedReceipt implements ArrayContract
{
    use SerializesContract;

    public function __construct(
        public string $batchId,
        public int $acceptedCount,
        public bool $duplicate,
    ) {
        ContractFormat::ulid($batchId, '$.batch_id');

        if ($acceptedCount < IngestProtocol::MIN_ROWS || $acceptedCount > IngestProtocol::MAX_ROWS) {
            throw ContractValidationException::at(
                '$.accepted_count',
                'must be between '.IngestProtocol::MIN_ROWS.' and '.IngestProtocol::MAX_ROWS,
            );
        }
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        ContractInput::assertShape($input, ['batch_id', 'accepted_count', 'duplicate']);

        return new self(
            batchId: ContractInput::string($input, 'batch_id'),
            acceptedCount: ContractInput::integer($input, 'accepted_count'),
            duplicate: ContractInput::boolean($input, 'duplicate'),
        );
    }

    public static function fromJson(string $json): self
    {
        return self::fromArray(ContractInput::decodeObject($json));
    }

    /** @return array{batch_id: string, accepted_count: int, duplicate: bool} */
    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'accepted_count' => $this->acceptedCount,
            'duplicate' => $this->duplicate,
        ];
    }
}
