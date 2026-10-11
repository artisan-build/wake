<?php

declare(strict_types=1);

use ArtisanBuild\WakeContracts\AcceptedReceipt;
use ArtisanBuild\WakeContracts\Enums\ErrorCode;
use ArtisanBuild\WakeContracts\ErrorResponse;
use ArtisanBuild\WakeContracts\Exceptions\ContractValidationException;
use ArtisanBuild\WakeContracts\IngestEnvelope;
use ArtisanBuild\WakeContracts\IngestProtocol;
use ArtisanBuild\WakeContracts\StagingBatch;

it('derives a staging batch identity from the authenticated app', function (): void {
    $staging = StagingBatch::fromEnvelope(
        authenticatedAppId: 'app-authenticated',
        batchId: '01ARZ3NDEKTSV4RRFFQ69G5FAW',
        envelope: contractEnvelope(),
        acceptedAt: '2026-10-11T12:35:00.000000Z',
    );
    $roundTrip = StagingBatch::fromJson($staging->toJson());

    expect($roundTrip->appId)->toBe('app-authenticated')
        ->and($roundTrip->rowCount)->toBe(1)
        ->and($roundTrip->status->value)->toBe('pending')
        ->and($roundTrip->payload->toJson())->toBe(contractEnvelope()->toJson());
});

it('rejects body-supplied identity instead of ignoring it', function (string $identityField): void {
    $payload = contractEnvelope()->toArray();
    $payload[$identityField] = 'app-forged';

    IngestEnvelope::fromArray($payload);
})->with(['app', 'app_id', 'application_id'])
    ->throws(ContractValidationException::class, 'is not a recognized contract field');

it('round trips accepted receipts and uses 202 for acceptance and replay', function (bool $duplicate): void {
    $receipt = new AcceptedReceipt('01ARZ3NDEKTSV4RRFFQ69G5FAW', 12, $duplicate);

    expect(AcceptedReceipt::fromJson($receipt->toJson())->toArray())->toBe($receipt->toArray())
        ->and(IngestProtocol::acceptedStatus($duplicate))->toBe(202);
})->with([false, true]);

it('requires the idempotency header to match the body', function (): void {
    $envelope = contractEnvelope();

    IngestProtocol::assertIdempotencyHeader($envelope->idempotencyKey, $envelope);

    expect(fn () => IngestProtocol::assertIdempotencyHeader('different-key', $envelope))
        ->toThrow(ContractValidationException::class, 'must exactly match $.idempotency_key');
});

it('covers the fixed error response vocabulary', function (int $status, ErrorCode $code, ?int $retryAfter): void {
    $response = new ErrorResponse(
        status: $status,
        error: $code,
        message: 'Request rejected',
        details: ['field' => 'reason'],
        retryAfter: $retryAfter,
    );

    expect(ErrorResponse::fromJson($response->toJson())->toArray())->toBe($response->toArray());
})->with([
    [401, ErrorCode::Unauthorized, null],
    [403, ErrorCode::Forbidden, null],
    [413, ErrorCode::PayloadTooLarge, null],
    [422, ErrorCode::ValidationFailed, null],
    [422, ErrorCode::UnsupportedEnvelopeVersion, null],
    [429, ErrorCode::RateLimited, 30],
]);

it('requires a positive retry after value for rate limits', function (?int $retryAfter): void {
    new ErrorResponse(429, ErrorCode::RateLimited, 'Slow down', retryAfter: $retryAfter);
})->with([null, 0, -1])
    ->throws(ContractValidationException::class, 'positive number of seconds');

it('keeps contract objects immutable', function (string $class): void {
    expect((new ReflectionClass($class))->isReadOnly())->toBeTrue();
})->with([
    AcceptedReceipt::class,
    ErrorResponse::class,
    IngestEnvelope::class,
    StagingBatch::class,
]);
