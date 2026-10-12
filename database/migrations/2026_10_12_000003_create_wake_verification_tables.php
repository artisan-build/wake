<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wake_verification_runs', function (Blueprint $table): void {
            $table->string('run_key', 24)->primary();
            $table->unsignedBigInteger('rows');
            $table->unsignedInteger('apps');
            $table->unsignedInteger('days');
            $table->unsignedInteger('chunk_rows');
            $table->timestampTz('start_at');
            $table->unsignedBigInteger('completed_rows')->default(0);
            $table->string('state', 16)->default('running');
            $table->timestampsTz(6);
        });

        Schema::create('wake_verification_chunks', function (Blueprint $table): void {
            $table->string('run_key', 24);
            $table->unsignedInteger('chunk_number');
            $table->char('batch_id', 26)->unique();
            $table->unsignedBigInteger('row_offset');
            $table->unsignedInteger('row_count');
            $table->string('state', 24)->default('planned');
            $table->timestampTz('lake_committed_at', 6)->nullable();
            $table->timestampTz('done_at', 6)->nullable();
            $table->timestampsTz(6);

            $table->primary(['run_key', 'chunk_number']);
            $table->foreign('run_key')->references('run_key')->on('wake_verification_runs')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wake_verification_chunks');
        Schema::dropIfExists('wake_verification_runs');
    }
};
