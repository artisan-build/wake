<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeContracts;

use ArtisanBuild\WakeContracts\Contracts\ArrayContract;
use ArtisanBuild\WakeContracts\Contracts\SerializesContract;
use ArtisanBuild\WakeContracts\Enums\LogLevel;
use ArtisanBuild\WakeContracts\Exceptions\ContractValidationException;
use ArtisanBuild\WakeContracts\Support\CanonicalJson;
use ArtisanBuild\WakeContracts\Support\ContractFormat;
use ArtisanBuild\WakeContracts\Support\ContractInput;
use stdClass;
use ValueError;

final readonly class LogEvent implements ArrayContract
{
    use SerializesContract;

    public const int MAX_CHANNEL_BYTES = 255;

    public const int MAX_MESSAGE_BYTES = 65_535;

    public const int MAX_CONTEXT_BYTES = 65_536;

    public const int MAX_EXTRA_BYTES = 65_536;

    public const int MAX_ENVIRONMENT_BYTES = 255;

    public const int MAX_HOSTNAME_BYTES = 255;

    public const int MAX_CORRELATION_ID_BYTES = 255;

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $eventId,
        public string $occurredAt,
        public LogLevel $level,
        public string $channel,
        public string $message,
        public array $context,
        public array $extra,
        public ?ExceptionData $exception,
        public string $environment,
        public string $hostname,
        public ?string $correlationId = null,
    ) {
        ContractFormat::ulid($eventId, '$.event_id');
        ContractFormat::timestamp($occurredAt, '$.occurred_at');
        ContractInput::boundedString($channel, '$.channel', self::MAX_CHANNEL_BYTES);
        ContractInput::boundedString($message, '$.message', self::MAX_MESSAGE_BYTES, allowEmpty: true);
        ContractInput::jsonObject($context, '$.context', self::MAX_CONTEXT_BYTES);
        ContractInput::jsonObject($extra, '$.extra', self::MAX_EXTRA_BYTES);
        ContractInput::boundedString($environment, '$.environment', self::MAX_ENVIRONMENT_BYTES);
        ContractInput::boundedString($hostname, '$.hostname', self::MAX_HOSTNAME_BYTES);

        if ($correlationId !== null) {
            ContractInput::boundedString($correlationId, '$.correlation_id', self::MAX_CORRELATION_ID_BYTES);
        }
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input, string $path = '$'): self
    {
        ContractInput::assertShape(
            $input,
            ['event_id', 'occurred_at', 'level', 'channel', 'message', 'context', 'extra', 'environment', 'hostname'],
            ['exception', 'correlation_id'],
            $path,
        );

        $levelValue = ContractInput::string($input, 'level', $path);

        try {
            $level = LogLevel::from($levelValue);
        } catch (ValueError) {
            throw ContractValidationException::at(ContractInput::path($path, 'level'), 'is not a supported log level');
        }

        $exception = null;
        if (array_key_exists('exception', $input) && $input['exception'] !== null) {
            if (! is_array($input['exception']) || array_is_list($input['exception'])) {
                throw ContractValidationException::at(ContractInput::path($path, 'exception'), 'must be an object or null');
            }

            /** @var array<string, mixed> $exceptionInput */
            $exceptionInput = $input['exception'];
            $exception = ExceptionData::fromArray($exceptionInput, ContractInput::path($path, 'exception'));
        }

        /** @var array<string, mixed> $context */
        $context = ContractInput::objectArray($input, 'context', $path);
        /** @var array<string, mixed> $extra */
        $extra = ContractInput::objectArray($input, 'extra', $path);

        return new self(
            eventId: ContractInput::string($input, 'event_id', $path),
            occurredAt: ContractInput::string($input, 'occurred_at', $path),
            level: $level,
            channel: ContractInput::string($input, 'channel', $path),
            message: ContractInput::string($input, 'message', $path),
            context: $context,
            extra: $extra,
            exception: $exception,
            environment: ContractInput::string($input, 'environment', $path),
            hostname: ContractInput::string($input, 'hostname', $path),
            correlationId: ContractInput::nullableString($input, 'correlation_id', $path),
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
            'event_id' => $this->eventId,
            'occurred_at' => $this->occurredAt,
            'level' => $this->level->value,
            'channel' => $this->channel,
            'message' => $this->message,
            'context' => $this->context === [] ? new stdClass : CanonicalJson::normalize($this->context),
            'extra' => $this->extra === [] ? new stdClass : CanonicalJson::normalize($this->extra),
        ];

        if ($this->exception !== null) {
            $result['exception'] = $this->exception->toArray();
        }

        $result['environment'] = $this->environment;
        $result['hostname'] = $this->hostname;

        if ($this->correlationId !== null) {
            $result['correlation_id'] = $this->correlationId;
        }

        return $result;
    }
}
