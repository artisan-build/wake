<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeContracts;

use ArtisanBuild\WakeContracts\Contracts\ArrayContract;
use ArtisanBuild\WakeContracts\Contracts\SerializesContract;
use ArtisanBuild\WakeContracts\Exceptions\ContractValidationException;
use ArtisanBuild\WakeContracts\Support\ContractFormat;
use ArtisanBuild\WakeContracts\Support\ContractInput;

final readonly class IngestEnvelope implements ArrayContract
{
    use SerializesContract;

    public const int MAX_CLIENT_VERSION_BYTES = 64;

    /** @var non-empty-list<LogEvent> */
    public array $rows;

    /** @param list<mixed> $rows */
    public function __construct(
        public int $envelopeVersion,
        public string $clientVersion,
        public int $droppedCount,
        public string $idempotencyKey,
        array $rows,
    ) {
        IngestProtocol::assertSupportedEnvelopeVersion($envelopeVersion);
        ContractInput::boundedString($clientVersion, '$.client_version', self::MAX_CLIENT_VERSION_BYTES);

        if ($droppedCount < 0) {
            throw ContractValidationException::at('$.dropped_count', 'must be zero or greater');
        }

        ContractFormat::idempotencyKey($idempotencyKey, '$.idempotency_key');

        $validatedRows = [];
        foreach ($rows as $index => $row) {
            if (! $row instanceof LogEvent) {
                throw ContractValidationException::at("$.rows[{$index}]", 'must be a LogEvent');
            }

            $validatedRows[] = $row;
        }

        $count = count($validatedRows);
        if ($count < IngestProtocol::MIN_ROWS || $count > IngestProtocol::MAX_ROWS) {
            throw ContractValidationException::at(
                '$.rows',
                'must contain between '.IngestProtocol::MIN_ROWS.' and '.IngestProtocol::MAX_ROWS.' events',
            );
        }

        $this->rows = $validatedRows;
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        ContractInput::assertShape(
            $input,
            ['envelope_version', 'client_version', 'dropped_count', 'idempotency_key', 'rows'],
        );

        $rawRows = ContractInput::array($input, 'rows');
        if (! array_is_list($rawRows)) {
            throw ContractValidationException::at('$.rows', 'must be a JSON array');
        }

        $rows = [];
        foreach ($rawRows as $index => $rawRow) {
            if (! is_array($rawRow) || array_is_list($rawRow)) {
                throw ContractValidationException::at("$.rows[{$index}]", 'must be an object');
            }

            /** @var array<string, mixed> $rawRow */
            $rows[] = LogEvent::fromArray($rawRow, "$.rows[{$index}]");
        }

        return new self(
            envelopeVersion: ContractInput::integer($input, 'envelope_version'),
            clientVersion: ContractInput::string($input, 'client_version'),
            droppedCount: ContractInput::integer($input, 'dropped_count'),
            idempotencyKey: ContractInput::string($input, 'idempotency_key'),
            rows: $rows,
        );
    }

    public static function fromJson(string $json): self
    {
        return self::fromArray(ContractInput::decodeObject($json));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'envelope_version' => $this->envelopeVersion,
            'client_version' => $this->clientVersion,
            'dropped_count' => $this->droppedCount,
            'idempotency_key' => $this->idempotencyKey,
            'rows' => array_map(
                static fn (LogEvent $event): array => $event->toArray(),
                $this->rows,
            ),
        ];
    }
}
