<?php

declare(strict_types=1);

use ArtisanBuild\WakeContracts\ExceptionData;
use ArtisanBuild\WakeContracts\Exceptions\ContractValidationException;
use ArtisanBuild\WakeContracts\IngestEnvelope;
use ArtisanBuild\WakeContracts\IngestProtocol;

it('round trips every envelope and event field through canonical JSON', function (): void {
    $envelope = contractEnvelope();
    $json = $envelope->toJson();
    $roundTrip = IngestEnvelope::fromJson($json);

    expect($roundTrip->toJson())->toBe($json)
        ->and($roundTrip->envelopeVersion)->toBe(1)
        ->and($roundTrip->clientVersion)->toBe('1.2.3')
        ->and($roundTrip->droppedCount)->toBe(7)
        ->and($roundTrip->idempotencyKey)->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAX')
        ->and($roundTrip->rows)->toHaveCount(1)
        ->and($roundTrip->rows[0]->eventId)->toBe('01ARZ3NDEKTSV4RRFFQ69G5FAV')
        ->and($roundTrip->rows[0]->occurredAt)->toBe('2026-10-11T12:34:56.123456+00:00')
        ->and($roundTrip->rows[0]->context)->toBe(['alpha' => ['request' => 'req-1'], 'zeta' => 2])
        ->and($roundTrip->rows[0]->exception?->trace)->toContain('#1 {main}')
        ->and($roundTrip->rows[0]->correlationId)->toBe('req-1');
});

it('accepts both batch bounds', function (int $count): void {
    $rows = array_fill(0, $count, contractEvent());

    expect(contractEnvelope(['rows' => $rows])->rows)->toHaveCount($count);
})->with([1, 500]);

it('rejects batches outside the bounds', function (array $rows): void {
    contractEnvelope(['rows' => $rows]);
})->with([
    'zero rows' => [[]],
    '501 rows' => [array_fill(0, 501, contractEvent())],
])->throws(ContractValidationException::class, 'must contain between 1 and 500 events');

it('rejects invalid event identifiers', function (string $eventId): void {
    contractEvent(['eventId' => $eventId]);
})->with([
    'uuid' => 'd6e09dcd-764a-4373-bd79-4cdb8b1f7b3f',
    'lowercase ulid' => '01arz3ndektsv4rrffq69g5fav',
    'overflow ulid' => '81ARZ3NDEKTSV4RRFFQ69G5FAV',
])->throws(ContractValidationException::class, 'canonical uppercase ULID');

it('rejects timestamps without both microseconds and an offset', function (string $occurredAt): void {
    contractEvent(['occurredAt' => $occurredAt]);
})->with([
    'no offset' => '2026-10-11T12:34:56.123456',
    'milliseconds' => '2026-10-11T12:34:56.123+00:00',
    'invalid date' => '2026-02-30T12:34:56.123456Z',
])->throws(ContractValidationException::class);

it('rejects invalid levels while parsing', function (): void {
    $payload = contractEnvelope()->toArray();
    $payload['rows'][0]['level'] = 'verbose';

    IngestEnvelope::fromArray($payload);
})->throws(ContractValidationException::class, 'supported log level');

it('rejects bounded strings and exception traces over their limits', function (Closure $build): void {
    $build();
})->with([
    'channel' => [fn () => contractEvent(['channel' => str_repeat('x', 256)])],
    'message' => [fn () => contractEvent(['message' => str_repeat('x', 65_536)])],
    'trace' => [fn () => contractEvent(['exception' => new ExceptionData(
        'RuntimeException', 'message', '/tmp/file.php', 1, str_repeat('x', 65_536),
    )])],
])->throws(ContractValidationException::class, 'must be at most');

it('rejects negative drops and newer versions with an actionable error', function (): void {
    expect(fn () => contractEnvelope(['droppedCount' => -1]))
        ->toThrow(ContractValidationException::class, 'must be zero or greater');

    expect(fn () => contractEnvelope(['envelopeVersion' => 2]))
        ->toThrow(
            ContractValidationException::class,
            'received envelope version 2; this server supports versions through 1',
        );
});

it('keeps the supported-version list explicit for additive compatibility', function (): void {
    expect(IngestProtocol::SUPPORTED_ENVELOPE_VERSIONS)->toBe([1])
        ->and(contractEnvelope(['envelopeVersion' => 1])->envelopeVersion)->toBe(1);
});

it('rejects absent required envelope fields', function (string $field): void {
    $payload = contractEnvelope()->toArray();
    unset($payload[$field]);

    IngestEnvelope::fromArray($payload);
})->with(['envelope_version', 'client_version', 'dropped_count', 'idempotency_key', 'rows'])
    ->throws(ContractValidationException::class);

it('rejects missing and unknown fields at every request level', function (Closure $change): void {
    $payload = contractEnvelope()->toArray();
    $change($payload);

    IngestEnvelope::fromArray($payload);
})->with([
    'unknown envelope field' => [function (array &$payload): void {
        $payload['future_field'] = true;
    }],
    'unknown event field' => [function (array &$payload): void {
        $payload['rows'][0]['future_field'] = true;
    }],
    'unknown exception field' => [function (array &$payload): void {
        $payload['rows'][0]['exception']['future_field'] = true;
    }],
    'missing event field' => [function (array &$payload): void {
        unset($payload['rows'][0]['message']);
    }],
    'missing exception field' => [function (array &$payload): void {
        unset($payload['rows'][0]['exception']['trace']);
    }],
])->throws(ContractValidationException::class);

it('serializes maps and contract fields deterministically', function (): void {
    $first = contractEnvelope()->toJson();
    $second = contractEnvelope([
        'rows' => [contractEvent(['context' => ['alpha' => ['request' => 'req-1'], 'zeta' => 2]])],
    ])->toJson();

    expect($first)->toBe($second)
        ->and($first)->toContain('"context":{"alpha"');
});
