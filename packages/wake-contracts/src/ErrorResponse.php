<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeContracts;

use ArtisanBuild\WakeContracts\Contracts\ArrayContract;
use ArtisanBuild\WakeContracts\Contracts\SerializesContract;
use ArtisanBuild\WakeContracts\Enums\ErrorCode;
use ArtisanBuild\WakeContracts\Exceptions\ContractValidationException;
use ArtisanBuild\WakeContracts\Support\CanonicalJson;
use ArtisanBuild\WakeContracts\Support\ContractInput;
use ValueError;

final readonly class ErrorResponse implements ArrayContract
{
    use SerializesContract;

    public const int MAX_MESSAGE_BYTES = 1_024;

    public const int MAX_DETAILS_BYTES = 16_384;

    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public int $status,
        public ErrorCode $error,
        public string $message,
        public array $details = [],
        public ?int $retryAfter = null,
    ) {
        if (! in_array($status, IngestProtocol::ERROR_STATUSES, true)) {
            throw ContractValidationException::at('$.status', 'must be one of 401, 403, 413, 422, or 429');
        }

        $expectedStatus = match ($error) {
            ErrorCode::Unauthorized => 401,
            ErrorCode::Forbidden => 403,
            ErrorCode::PayloadTooLarge => 413,
            ErrorCode::ValidationFailed, ErrorCode::UnsupportedEnvelopeVersion => 422,
            ErrorCode::RateLimited => 429,
        };

        if ($status !== $expectedStatus) {
            throw ContractValidationException::at('$.status', "must be {$expectedStatus} for error {$error->value}");
        }

        ContractInput::boundedString($message, '$.message', self::MAX_MESSAGE_BYTES);
        ContractInput::jsonObject($details, '$.details', self::MAX_DETAILS_BYTES);

        if ($status === 429 && ($retryAfter === null || $retryAfter < 1)) {
            throw ContractValidationException::at('$.retry_after', 'must be a positive number of seconds for a 429 response');
        }

        if ($status !== 429 && $retryAfter !== null) {
            throw ContractValidationException::at('$.retry_after', 'is only valid for a 429 response');
        }
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        ContractInput::assertShape($input, ['status', 'error', 'message'], ['details', 'retry_after']);
        $errorValue = ContractInput::string($input, 'error');

        try {
            $error = ErrorCode::from($errorValue);
        } catch (ValueError) {
            throw ContractValidationException::at('$.error', 'is not a supported error code');
        }

        $details = [];
        if (array_key_exists('details', $input)) {
            /** @var array<string, mixed> $details */
            $details = ContractInput::array($input, 'details');
        }

        return new self(
            status: ContractInput::integer($input, 'status'),
            error: $error,
            message: ContractInput::string($input, 'message'),
            details: $details,
            retryAfter: ContractInput::nullableInteger($input, 'retry_after'),
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
            'status' => $this->status,
            'error' => $this->error->value,
            'message' => $this->message,
        ];

        if ($this->details !== []) {
            $result['details'] = CanonicalJson::normalize($this->details);
        }

        if ($this->retryAfter !== null) {
            $result['retry_after'] = $this->retryAfter;
        }

        return $result;
    }
}
