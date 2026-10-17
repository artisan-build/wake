<?php

declare(strict_types=1);

use ArtisanBuild\WakeContracts\Enums\LogLevel;
use ArtisanBuild\WakeContracts\Enums\StagingBatchStatus;
use ArtisanBuild\WakeContracts\ExceptionData;
use ArtisanBuild\WakeContracts\IngestEnvelope;
use ArtisanBuild\WakeContracts\LogEvent;
use ArtisanBuild\WakeContracts\StagingBatch;

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

function stagingBatch(array $overrides = []): StagingBatch
{
    $envelopeOverrides = $overrides['envelope'] ?? [];
    unset($overrides['envelope']);

    $values = array_replace([
        'batchId' => '01ARZ3NDEKTSV4RRFFQ69G5FAW',
        'appId' => 'app-authenticated',
        'idempotencyKey' => '01ARZ3NDEKTSV4RRFFQ69G5FAX',
        'envelopeVersion' => 1,
        'clientVersion' => '1.2.3',
        'droppedCount' => 7,
        'payload' => contractEnvelope($envelopeOverrides),
        'rowCount' => 1,
        'status' => StagingBatchStatus::Pending,
        'createdAt' => '2026-10-11T12:35:00.000000Z',
        'updatedAt' => '2026-10-11T12:35:00.000000Z',
        'claimedAt' => null,
        'flushedAt' => null,
    ], $overrides);

    return new StagingBatch(...$values);
}
