<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseMigrations::class);

it('finishes a fresh migration with only the authoritative flush batches table', function (): void {
    expect(Schema::hasTable('wake_flush_batches'))->toBeTrue()
        ->and(Schema::hasTable('wake_flush_manifest'))->toBeFalse()
        ->and(Schema::getColumnListing('wake_flush_batches'))->toContain(
            'batch_id', 'staging_batch_ids', 'event_days', 'state', 'claimed_at', 'lake_committed_at', 'done_at',
        );
});

it('transitions an already-recorded empty legacy manifest and rolls back coherently', function (): void {
    restoreLegacyFlushManifestFixture();
    $migration = flushManifestTransitionMigration();

    $migration->up();
    expect(Schema::hasTable('wake_flush_manifest'))->toBeFalse()
        ->and(Schema::hasTable('wake_flush_batches'))->toBeTrue();

    $migration->down();
    expect(Schema::hasTable('wake_flush_batches'))->toBeFalse()
        ->and(Schema::hasTable('wake_flush_manifest'))->toBeTrue();
});

it('refuses to discard a non-empty legacy manifest', function (): void {
    restoreLegacyFlushManifestFixture();
    DB::table('wake_flush_manifest')->insert([
        'batch_id' => '01M4PR4LEGACY000000000000',
        'lake_completed' => true, 'derived_completed' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => flushManifestTransitionMigration()->up())
        ->toThrow(RuntimeException::class, 'uncertain flush work requires manual arbitration')
        ->and(Schema::hasTable('wake_flush_manifest'))->toBeTrue()
        ->and(DB::table('wake_flush_manifest')->count())->toBe(1)
        ->and(Schema::hasTable('wake_flush_batches'))->toBeFalse();
});

function restoreLegacyFlushManifestFixture(): void
{
    Schema::dropIfExists('wake_flush_batches');
    Schema::dropIfExists('wake_flush_manifest');
    Schema::create('wake_flush_manifest', function (Blueprint $table): void {
        $table->char('batch_id', 26)->primary();
        $table->boolean('lake_completed')->default(false);
        $table->boolean('derived_completed')->default(false);
        $table->timestampTz('completed_at', 6)->nullable();
        $table->timestampsTz(6);
    });
}

function flushManifestTransitionMigration(): Migration
{
    return require base_path('packages/wake-server/database/migrations/2026_10_11_000003_transition_wake_flush_manifest_to_batches.php');
}
