<?php

declare(strict_types=1);

use ArtisanBuild\WakeContracts\Enums\LogLevel;
use ArtisanBuild\WakeContracts\ExceptionData;
use ArtisanBuild\WakeContracts\IngestEnvelope;
use ArtisanBuild\WakeContracts\LogEvent;

function contractEvent(array $overrides = []): LogEvent
{
    $values = array_replace([
        'eventId' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'occurredAt' => '2026-10-11T12:34:56.123456+00:00',
        'level' => LogLevel::Error,
        'channel' => 'production',
        'message' => 'Payment failed',
        'context' => ['zeta' => 2, 'alpha' => ['request' => 'req-1']],
        'extra' => ['memory' => 1024],
        'exception' => new ExceptionData(
            class: 'RuntimeException',
            message: 'Gateway unavailable',
            file: '/var/www/app/Service.php',
            line: 42,
            trace: "#0 /var/www/app/Job.php(10): fail()\n#1 {main}",
        ),
        'environment' => 'production',
        'hostname' => 'web-1',
        'correlationId' => 'req-1',
    ], $overrides);

    return new LogEvent(...$values);
}

function contractEnvelope(array $overrides = []): IngestEnvelope
{
    $values = array_replace([
        'envelopeVersion' => 1,
        'clientVersion' => '1.2.3',
        'droppedCount' => 7,
        'idempotencyKey' => '01ARZ3NDEKTSV4RRFFQ69G5FAX',
        'rows' => [contractEvent()],
    ], $overrides);

    return new IngestEnvelope(...$values);
}
