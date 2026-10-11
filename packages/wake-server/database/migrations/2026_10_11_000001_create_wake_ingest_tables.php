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
        Schema::create('wake_connected_apps', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('batches_per_minute')->nullable();
            $table->unsignedInteger('events_per_minute')->nullable();
            $table->unsignedBigInteger('accepted_rows')->default(0);
            $table->unsignedBigInteger('dropped_rows')->default(0);
            $table->unsignedBigInteger('rejected_batches')->default(0);
            $table->unsignedBigInteger('rate_limit_hits')->default(0);
            $table->timestampTz('last_seen_at', 6)->nullable();
            $table->string('last_client_version', 64)->nullable();
            $table->timestampsTz(6);
        });

        Schema::create('wake_staging_batches', function (Blueprint $table): void {
            $table->char('batch_id', 26)->primary();
            $table->string('app_id');
            $table->string('idempotency_key', 128);
            $table->smallInteger('envelope_version');
            $table->string('client_version', 64);
            $table->unsignedBigInteger('dropped_count');
            $table->jsonb('payload');
            $table->unsignedSmallInteger('row_count');
            $table->string('status', 16);
            $table->timestampTz('created_at', 6);
            $table->timestampTz('updated_at', 6);
            $table->timestampTz('claimed_at', 6)->nullable();
            $table->timestampTz('flushed_at', 6)->nullable();

            $table->foreign('app_id')->references('id')->on('wake_connected_apps')->restrictOnDelete();
            $table->unique(['app_id', 'idempotency_key'], 'wake_staging_app_idempotency_unique');
            $table->index(['status', 'created_at'], 'wake_staging_status_created_index');
        });

        Schema::create('wake_ingest_windows', function (Blueprint $table): void {
            $table->string('app_id');
            $table->timestampTz('window_start');
            $table->unsignedInteger('batch_count')->default(0);
            $table->unsignedBigInteger('event_count')->default(0);

            $table->primary(['app_id', 'window_start']);
            $table->foreign('app_id')->references('id')->on('wake_connected_apps')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE wake_staging_batches ADD CONSTRAINT wake_staging_status_check CHECK (status IN ('pending', 'flushing', 'flushed', 'failed'))");
            DB::statement('ALTER TABLE wake_staging_batches ADD CONSTRAINT wake_staging_envelope_version_check CHECK (envelope_version > 0)');
            DB::statement('ALTER TABLE wake_staging_batches ADD CONSTRAINT wake_staging_dropped_count_check CHECK (dropped_count >= 0)');
            DB::statement('ALTER TABLE wake_staging_batches ADD CONSTRAINT wake_staging_row_count_check CHECK (row_count BETWEEN 1 AND 500)');
            DB::statement('ALTER TABLE wake_connected_apps ADD CONSTRAINT wake_app_batches_limit_check CHECK (batches_per_minute IS NULL OR batches_per_minute > 0)');
            DB::statement('ALTER TABLE wake_connected_apps ADD CONSTRAINT wake_app_events_limit_check CHECK (events_per_minute IS NULL OR events_per_minute > 0)');
            DB::statement('ALTER TABLE wake_ingest_windows ADD CONSTRAINT wake_ingest_window_counts_check CHECK (batch_count >= 0 AND event_count >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wake_ingest_windows');
        Schema::dropIfExists('wake_staging_batches');
        Schema::dropIfExists('wake_connected_apps');
    }
};
