<?php

declare(strict_types=1);

use ArtisanBuild\WakeClient\Contracts\PayloadFilter;
use ArtisanBuild\WakeClient\Logging\RecordNormalizer;
use ArtisanBuild\WakeContracts\LogEvent;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;

it('registers a cached-config-safe wake channel with safe defaults', function (): void {
    expect(config('logging.channels.wake'))->toBe(['driver' => 'wake'])
        ->and(config('wake.auto_attach'))->toBeTrue()
        ->and(config('wake.batch_size'))->toBe(100)
        ->and(config('wake.max_buffer'))->toBe(500)
        ->and(config('wake.connect_timeout'))->toBe(0.5)
        ->and(config('wake.timeout'))->toBe(2.0)
        ->and(app('log')->channel('wake')->getLogger())->toBeInstanceOf(Logger::class);
});

it('binds a pass-through payload filter that consumers can replace', function (): void {
    $event = app(RecordNormalizer::class)->normalize(wakeRecord());

    expect(app(PayloadFilter::class)->filter($event))->toBe($event);
});

it('normalizes a complete contract event and selects record correlation first', function (): void {
    $event = app(RecordNormalizer::class)->normalize(wakeRecord(
        context: ['correlation_id' => 'record-123', 'object' => new class implements JsonSerializable
        {
            public function jsonSerialize(): array
            {
                return ['safe' => true];
            }
        }, 'exception' => new RuntimeException('broken')],
        extra: ['request_id' => 'extra-456'],
    ));

    expect($event)->toBeInstanceOf(LogEvent::class)
        ->and($event->eventId)->toMatch('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/')
        ->and($event->occurredAt)->toBe('2026-10-11T12:34:56.123456+00:00')
        ->and($event->correlationId)->toBe('record-123')
        ->and($event->context)->toBe(['correlation_id' => 'record-123', 'object' => ['safe' => true]])
        ->and($event->exception?->message)->toBe('broken')
        ->and($event->environment)->toBe(config('app.env'));
});

it('fails closed when record material is not JSON safe', function (): void {
    $resource = fopen('php://memory', 'r');

    expect(fn () => app(RecordNormalizer::class)->normalize(wakeRecord(context: ['bad' => $resource])))
        ->toThrow(InvalidArgumentException::class);

    fclose($resource);
});

function wakeRecord(array $context = [], array $extra = []): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable('2026-10-11T12:34:56.123456+00:00'),
        channel: 'production',
        level: Level::Error,
        message: 'Payment failed',
        context: $context,
        extra: $extra,
    );
}
