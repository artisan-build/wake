<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wake_flush_manifest')) {
            if (DB::table('wake_flush_manifest')->exists()) {
                throw new RuntimeException('Cannot transition a non-empty wake_flush_manifest; uncertain flush work requires manual arbitration.');
            }

            Schema::drop('wake_flush_manifest');
        }

        if (! Schema::hasTable('wake_flush_batches')) {
            $this->createFlushBatches();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('wake_flush_batches')) {
            Schema::drop('wake_flush_batches');
        }

        if (! Schema::hasTable('wake_flush_manifest')) {
            Schema::create('wake_flush_manifest', function (Blueprint $table): void {
                $table->char('batch_id', 26)->primary();
                $table->boolean('lake_completed')->default(false);
                $table->boolean('derived_completed')->default(false);
                $table->timestampTz('completed_at', 6)->nullable();
                $table->timestampsTz(6);
            });
        }
    }

    private function createFlushBatches(): void
    {
        Schema::create('wake_flush_batches', function (Blueprint $table): void {
            $table->char('batch_id', 26)->primary();
            $table->jsonb('staging_batch_ids');
            $table->jsonb('event_days');
            $table->string('state', 24);
            $table->timestampTz('claimed_at', 6);
            $table->timestampTz('lake_committed_at', 6)->nullable();
            $table->timestampTz('done_at', 6)->nullable();
            $table->timestampsTz(6);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE wake_flush_batches ADD CONSTRAINT wake_flush_batches_state_check CHECK (state IN ('claimed', 'lake_committed', 'done'))");
        }
    }
};
