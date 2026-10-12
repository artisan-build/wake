<?php

declare(strict_types=1);

use App\Verification\ScaleCorpusPlan;

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
    'unsafe run key' => [['run' => '../proof', 'rows' => '1', 'apps' => '1', 'days' => '1', 'chunk' => '1']],
]);

it('derives stable identifiers and UTC timestamps from the run and ordinal', function (): void {
    $first = new ScaleCorpusPlan('stable-proof', 1_000, 4, 30, 100);
    $second = new ScaleCorpusPlan('stable-proof', 1_000, 4, 30, 100);

    expect($first->eventId(412))->toBe($second->eventId(412))
        ->and($first->eventId(412))->toHaveLength(26)
        ->and($first->batchId(400))->toBe($second->batchId(400))
        ->and($first->occurredAt(0))->toBe('2026-09-12T00:00:00.000000Z')
        ->and($first->occurredAt(412))->toBe($second->occurredAt(412));
});
