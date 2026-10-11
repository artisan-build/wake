<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Ingest;

use ArtisanBuild\WakeContracts\AcceptedReceipt;
use ArtisanBuild\WakeContracts\Enums\ErrorCode;
use ArtisanBuild\WakeContracts\Enums\StagingBatchStatus;
use ArtisanBuild\WakeContracts\ErrorResponse;
use ArtisanBuild\WakeContracts\IngestEnvelope;
use ArtisanBuild\WakeContracts\IngestProtocol;
use ArtisanBuild\WakeServer\Models\ConnectedApp;
use ArtisanBuild\WakeServer\Models\StagingBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class IngestService
{
    public function accept(string $appId, IngestEnvelope $envelope): IngestDecision
    {
        $decision = DB::transaction(function () use ($appId, $envelope): IngestDecision {
            /** @var ConnectedApp|null $app */
            $app = ConnectedApp::query()->whereKey($appId)->lockForUpdate()->first();

            if ($app === null || ! $app->enabled) {
                return $this->error(403, ErrorCode::Forbidden, 'Credential is not allowed to ingest.');
            }

            $existing = $this->existingBatch($appId, $envelope->idempotencyKey);
            if ($existing !== null) {
                return $this->receipt($existing, true);
            }

            $now = now();
            $windowStart = $now->copy()->startOfMinute();

            DB::table('wake_ingest_windows')->insertOrIgnore([
                'app_id' => $appId,
                'window_start' => $windowStart,
                'batch_count' => 0,
                'event_count' => 0,
            ]);

            $window = DB::table('wake_ingest_windows')
                ->where('app_id', $appId)
                ->where('window_start', $windowStart)
                ->lockForUpdate()
                ->first(['batch_count', 'event_count']);

            $batchCount = $this->databaseInteger($window->batch_count ?? null);
            $eventCount = $this->databaseInteger($window->event_count ?? null);
            $rowCount = count($envelope->rows);
            $batchLimit = $app->batches_per_minute ?? $this->defaultLimit('batches_per_minute');
            $eventLimit = $app->events_per_minute ?? $this->defaultLimit('events_per_minute');

            if ($batchCount + 1 > $batchLimit || $eventCount + $rowCount > $eventLimit) {
                $app->forceFill([
                    'rejected_batches' => $app->rejected_batches + 1,
                    'rate_limit_hits' => $app->rate_limit_hits + 1,
                ])->save();

                $retryAfter = max(1, 60 - (int) $now->format('s'));
                $error = new ErrorResponse(
                    status: IngestProtocol::RATE_LIMITED_STATUS,
                    error: ErrorCode::RateLimited,
                    message: 'The app ingest limit has been reached.',
                    retryAfter: $retryAfter,
                );

                return new IngestDecision(
                    status: IngestProtocol::RATE_LIMITED_STATUS,
                    body: $error->toArray(),
                    headers: [IngestProtocol::RETRY_AFTER_HEADER => (string) $retryAfter],
                );
            }

            DB::table('wake_ingest_windows')
                ->where('app_id', $appId)
                ->where('window_start', $windowStart)
                ->update([
                    'batch_count' => $batchCount + 1,
                    'event_count' => $eventCount + $rowCount,
                ]);

            $batch = StagingBatch::query()->create([
                'batch_id' => (string) Str::ulid(),
                'app_id' => $appId,
                'idempotency_key' => $envelope->idempotencyKey,
                'envelope_version' => $envelope->envelopeVersion,
                'client_version' => $envelope->clientVersion,
                'dropped_count' => $envelope->droppedCount,
                'payload' => $envelope->toArray(),
                'row_count' => $rowCount,
                'status' => StagingBatchStatus::Pending,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $app->forceFill([
                'accepted_rows' => $app->accepted_rows + $rowCount,
                'dropped_rows' => $app->dropped_rows + $envelope->droppedCount,
                'last_seen_at' => $now,
                'last_client_version' => $envelope->clientVersion,
            ])->save();

            return $this->receipt($batch, false);
        }, 3);

        if (! $decision instanceof IngestDecision) {
            throw new RuntimeException('Ingest transaction returned an invalid decision.');
        }

        return $decision;
    }

    public function duplicate(string $appId, string $idempotencyKey): ?IngestDecision
    {
        $batch = $this->existingBatch($appId, $idempotencyKey);

        return $batch === null ? null : $this->receipt($batch, true);
    }

    public function recordRejection(string $appId): void
    {
        ConnectedApp::query()->whereKey($appId)->increment('rejected_batches');
    }

    private function existingBatch(string $appId, string $idempotencyKey): ?StagingBatch
    {
        /** @var StagingBatch|null */
        return StagingBatch::query()
            ->where('app_id', $appId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function receipt(StagingBatch $batch, bool $duplicate): IngestDecision
    {
        $receipt = new AcceptedReceipt(
            batchId: (string) $batch->batch_id,
            acceptedCount: (int) $batch->row_count,
            duplicate: $duplicate,
        );

        return new IngestDecision(IngestProtocol::ACCEPTED_STATUS, $receipt->toArray());
    }

    private function error(int $status, ErrorCode $code, string $message): IngestDecision
    {
        return new IngestDecision($status, (new ErrorResponse($status, $code, $message))->toArray());
    }

    private function defaultLimit(string $key): int
    {
        $value = config("wake-server.ingest.{$key}");

        return is_int($value) && $value > 0 ? $value : match ($key) {
            'batches_per_minute' => 120,
            'events_per_minute' => 50_000,
            default => throw new RuntimeException("Unknown ingest limit {$key}."),
        };
    }

    private function databaseInteger(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : 0;
    }
}
