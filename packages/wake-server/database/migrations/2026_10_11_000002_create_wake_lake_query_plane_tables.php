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

        Schema::create('wake_event_locators', function (Blueprint $table): void {
            $table->char('event_id', 26)->primary();
            $table->string('app_id');
            $table->date('event_date');
            $table->timestampTz('occurred_at', 6);
            $table->index(['app_id', 'occurred_at'], 'wake_locator_app_occurred_index');
            $table->index(['event_date'], 'wake_locator_day_index');
        });

        Schema::create('wake_hourly_counts', function (Blueprint $table): void {
            $table->string('app_id');
            $table->string('environment', 255);
            $table->string('level', 20);
            $table->timestampTz('hour', 6);
            $table->unsignedBigInteger('count');
            $table->primary(['app_id', 'environment', 'level', 'hour'], 'wake_hourly_counts_primary');
            $table->index(['hour'], 'wake_hourly_hour_index');
        });

        Schema::create('wake_error_events', function (Blueprint $table): void {
            $table->char('event_id', 26)->primary();
            $table->string('app_id');
            $table->date('event_date');
            $table->timestampTz('occurred_at', 6);
            $table->string('level', 20);
            $table->string('channel', 255);
            $table->text('message');
            $table->jsonb('context');
            $table->jsonb('extra');
            $table->jsonb('exception')->nullable();
            $table->string('environment', 255);
            $table->string('hostname', 255);
            $table->string('correlation_id', 255)->nullable();
            $table->index(['occurred_at'], 'wake_error_occurred_index');
            $table->index(['app_id', 'occurred_at'], 'wake_error_app_occurred_index');
            $table->index(['event_date'], 'wake_error_day_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wake_error_events');
        Schema::dropIfExists('wake_hourly_counts');
        Schema::dropIfExists('wake_event_locators');
        Schema::dropIfExists('wake_flush_batches');
    }
};
