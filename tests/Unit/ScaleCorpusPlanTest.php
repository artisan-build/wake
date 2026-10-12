<?php

declare(strict_types=1);

use App\Verification\ScaleCorpusPlan;
use ArtisanBuild\WakeContracts\Support\ContractFormat;

it('uses the final live verification defaults and a bounded chunk', function (): void {
    $plan = ScaleCorpusPlan::fromOptions([
        'run' => 'pr7-cloud', 'rows' => '50000000', 'apps' => '40', 'days' => '30', 'chunk' => '100000',
    ]);

    expect([$plan->rows, $plan->apps, $plan->days, $plan->chunk, $plan->chunks()])
        ->toBe([50_000_000, 40, 30, 100_000, 500]);
});

it('rejects ambiguous or oversized options', function (array $options): void {
    expect(fn () => ScaleCorpusPlan::fromOptions($options))->toThrow(InvalidArgumentException::class);
})->with([
    'zero rows' => [['run' => 'proof', 'rows' => '0', 'apps' => '1', 'days' => '1', 'chunk' => '1']],
    'negative apps' => [['run' => 'proof', 'rows' => '1', 'apps' => '-1', 'days' => '1', 'chunk' => '1']],
    'fractional days' => [['run' => 'proof', 'rows' => '1', 'apps' => '1', 'days' => '1.5', 'chunk' => '1']],
    'oversized chunk' => [['run' => 'proof', 'rows' => '100001', 'apps' => '1', 'days' => '1', 'chunk' => '100001']],
    'too many rows' => [['run' => 'proof', 'rows' => '50000001', 'apps' => '1', 'days' => '1', 'chunk' => '100000']],
    'too many apps' => [['run' => 'proof', 'rows' => '1', 'apps' => '41', 'days' => '1', 'chunk' => '1']],
    'too many days' => [['run' => 'proof', 'rows' => '1', 'apps' => '1', 'days' => '31', 'chunk' => '1']],
    'unsafe run key' => [['run' => '../proof', 'rows' => '1', 'apps' => '1', 'days' => '1', 'chunk' => '1']],
]);

it('derives stable identifiers and UTC timestamps from the run and ordinal', function (): void {
    $first = new ScaleCorpusPlan('stable-proof', 1_000, 4, 30, 100);
    $second = new ScaleCorpusPlan('stable-proof', 1_000, 4, 30, 100);
    ContractFormat::ulid($first->eventId(412), '$.event_id');
    ContractFormat::ulid($first->batchId(400), '$.batch_id');

    expect($first->eventId(412))->toBe($second->eventId(412))
        ->and($first->eventId(412))->toMatch('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/')
        ->and($first->eventId(412))->not->toBe($first->eventId(413))
        ->and($first->batchId(400))->toBe($second->batchId(400))
        ->and($first->batchId(400))->toMatch('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/')
        ->and($first->batchId(400))->not->toBe($first->batchId(500))
        ->and($first->eventId(412))->not->toBe((new ScaleCorpusPlan('other-proof', 1_000, 4, 30, 100))->eventId(412))
        ->and($first->occurredAt(0))->toBe('2026-09-12T00:00:00.000000Z')
        ->and($first->occurredAt(412))->toBe($second->occurredAt(412));
});
