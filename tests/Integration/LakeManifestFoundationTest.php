<?php

declare(strict_types=1);

use ArtisanBuild\WakeServer\Lake\FlushBatchState;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(DatabaseMigrations::class);

it('creates the authoritative PostgreSQL flush batch manifest and enum', function (): void {
    expect(FlushBatchState::cases())->toHaveCount(3)
        ->and(array_column(FlushBatchState::cases(), 'value'))->toBe(['claimed', 'lake_committed', 'done'])
        ->and(Schema::getColumnListing('wake_flush_batches'))->toContain(
            'batch_id', 'staging_batch_ids', 'event_days', 'state', 'claimed_at', 'lake_committed_at', 'done_at', 'created_at', 'updated_at',
        )
        ->and(Schema::hasTable('wake_flush_manifest'))->toBeFalse();

    DB::table('wake_flush_batches')->insert([
        'batch_id' => (string) Str::ulid(),
        'staging_batch_ids' => json_encode([(string) Str::ulid()], JSON_THROW_ON_ERROR),
        'event_days' => json_encode(['2026-10-11'], JSON_THROW_ON_ERROR),
        'state' => FlushBatchState::Claimed->value,
        'claimed_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => DB::table('wake_flush_batches')->insert([
        'batch_id' => (string) Str::ulid(),
        'staging_batch_ids' => '[]', 'event_days' => '[]', 'state' => 'invalid',
        'claimed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('indexes recent errors for both global and per app newest first reads', function (): void {
    $definitions = DB::table('pg_indexes')
        ->where('schemaname', 'public')
        ->where('tablename', 'wake_error_events')
        ->pluck('indexdef')
        ->map(static fn (string $definition): string => strtolower($definition));

    expect($definitions->contains(
        static fn (string $definition): bool => str_contains($definition, '(app_id, occurred_at)'),
    ))->toBeTrue('The per-app occurred_at index must remain present.')
        ->and($definitions->contains(
            static fn (string $definition): bool => str_contains($definition, '(occurred_at)'),
        ))->toBeTrue('A global occurred_at-led index is required for all-app newest-first reads.');
});
