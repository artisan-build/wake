<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeClient\Logging;

use ArtisanBuild\WakeContracts\Enums\LogLevel;
use ArtisanBuild\WakeContracts\ExceptionData;
use ArtisanBuild\WakeContracts\LogEvent;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonSerializable;
use Monolog\LogRecord;
use Stringable;
use Throwable;
use UnitEnum;

final readonly class RecordNormalizer
{
    public function __construct(
        private CorrelationResolver $correlations,
        private string $environment,
        private string $hostname,
    ) {}

    public function normalize(LogRecord $record): LogEvent
    {
        $context = $record->context;
        $throwable = $context['exception'] ?? null;
        unset($context['exception']);

        return new LogEvent(
            eventId: (string) Str::ulid(),
            occurredAt: $record->datetime->format('Y-m-d\\TH:i:s.uP'),
            level: LogLevel::from(strtolower($record->level->getName())),
            channel: $record->channel,
            message: $record->message,
            context: $this->jsonObject($context),
            extra: $this->jsonObject($record->extra),
            exception: $throwable instanceof Throwable ? $this->exception($throwable) : null,
            environment: $this->environment,
            hostname: $this->hostname,
            correlationId: $this->correlations->resolve($record->context, $record->extra),
        );
    }

    private function exception(Throwable $throwable): ExceptionData
    {
        return new ExceptionData(
            class: $throwable::class,
            message: $throwable->getMessage(),
            file: $throwable->getFile(),
            line: max(1, $throwable->getLine()),
            trace: substr($throwable->getTraceAsString(), 0, ExceptionData::MAX_TRACE_BYTES),
        );
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function jsonObject(array $values): array
    {
        /** @var array<string, mixed> */
        return $this->jsonSafe($values);
    }

    private function jsonSafe(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 16) {
            throw new InvalidArgumentException('Wake payload nesting is too deep.');
        }

        if ($value === null || is_string($value) || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('Wake payload contains a non-finite number.');
            }

            return $value;
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->jsonSafe($item, $depth + 1), $value);
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DATE_RFC3339_EXTENDED);
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        if ($value instanceof JsonSerializable) {
            return $this->jsonSafe($value->jsonSerialize(), $depth + 1);
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        throw new InvalidArgumentException('Wake payload contains a value that cannot be encoded as JSON.');
    }
}
