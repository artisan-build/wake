<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Lake;

use ArtisanBuild\WakeContracts\Enums\StagingBatchStatus;
use ArtisanBuild\WakeServer\Models\StagingBatch;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class FlushLogBatches
{
    /** @param null|Closure(string, string): void $fault */
    public function __construct(private readonly LakeWriter $lake, private readonly ?Closure $fault = null) {}

    /** @return array{batches: int, rows: int} */
    public function run(?int $limit = null): array
    {
        StagingBatch::query()->where('status', StagingBatchStatus::Flushing)
            ->where('claimed_at', '<', now()->subMinutes(5))
            ->update(['status' => StagingBatchStatus::Pending, 'claimed_at' => null]);

        $batches = $this->claim($limit ?? 20);
        $this->lake->bootstrap();
        $rows = 0;

        foreach ($batches as $batch) {
            $events = $this->events($batch);
            $this->lake->commitBatch((string) $batch->batch_id, $events);
            $this->hit('after_lake_commit', (string) $batch->batch_id);
            $this->complete($batch, $events);
            $rows += count($events);
        }

        return ['batches' => $batches->count(), 'rows' => $rows];
    }

    /** @return Collection<int, StagingBatch> */
    private function claim(int $limit): Collection
    {
        return DB::transaction(function () use ($limit): Collection {
            /** @var Collection<int, StagingBatch> $claimed */
            $claimed = StagingBatch::query()->where('status', StagingBatchStatus::Pending)
                ->oldest('created_at')->limit(max(1, $limit))->lock('FOR UPDATE SKIP LOCKED')->get();
            foreach ($claimed as $batch) {
                $batch->forceFill(['status' => StagingBatchStatus::Flushing, 'claimed_at' => now()])->save();
            }

            return $claimed;
        }, 3);
    }

    /** @return list<array<string, mixed>> */
    private function events(StagingBatch $batch): array
    {
        $payload = $batch->payload;
        $events = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];

        return array_map(function (array $event) use ($batch): array {
            $event['app_id'] = (string) $batch->app_id;

            return $event;
        }, $events);
    }

    /** @param list<array<string, mixed>> $events */
    private function complete(StagingBatch $batch, array $events): void
    {
        DB::transaction(function () use ($batch, $events): void {
            DB::table('wake_flush_manifest')->insertOrIgnore([
                'batch_id' => $batch->batch_id, 'lake_completed' => true, 'derived_completed' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $manifest = DB::table('wake_flush_manifest')->where('batch_id', $batch->batch_id)->lockForUpdate()->first();
            if ((bool) ($manifest->derived_completed ?? false)) {
                return;
            }

            $hourly = [];
            foreach ($events as $event) {
                $at = Carbon::parse((string) $event['occurred_at'])->utc();
                DB::table('wake_event_locators')->insertOrIgnore([
                    'event_id' => $event['event_id'], 'app_id' => $batch->app_id,
                    'event_date' => $at->toDateString(), 'occurred_at' => $at,
                ]);
                if (in_array($event['level'], ['error', 'critical', 'alert', 'emergency'], true)) {
                    DB::table('wake_error_events')->insertOrIgnore([
                        'event_id' => $event['event_id'], 'app_id' => $batch->app_id,
                        'event_date' => $at->toDateString(), 'occurred_at' => $at, 'level' => $event['level'],
                        'channel' => $event['channel'], 'message' => $event['message'],
                        'context' => json_encode($event['context'], JSON_THROW_ON_ERROR),
                        'extra' => json_encode($event['extra'], JSON_THROW_ON_ERROR),
                        'exception' => isset($event['exception']) ? json_encode($event['exception'], JSON_THROW_ON_ERROR) : null,
                        'environment' => $event['environment'], 'hostname' => $event['hostname'],
                        'correlation_id' => $event['correlation_id'] ?? null,
                    ]);
                }
                $hour = $at->copy()->startOfHour();
                $key = $batch->app_id.'|'.$event['environment'].'|'.$event['level'].'|'.$hour->toIso8601String();
                $hourly[$key] = ['app_id' => $batch->app_id, 'environment' => $event['environment'], 'level' => $event['level'], 'hour' => $hour, 'count' => ($hourly[$key]['count'] ?? 0) + 1];
            }
            foreach ($hourly as $count) {
                DB::statement('INSERT INTO wake_hourly_counts (app_id, environment, level, hour, count) VALUES (?, ?, ?, ?, ?) ON CONFLICT (app_id, environment, level, hour) DO UPDATE SET count = wake_hourly_counts.count + excluded.count', array_values($count));
            }
            DB::table('wake_connected_apps')->where('id', $batch->app_id)->increment('flushed_rows', count($events), ['last_flushed_at' => now()]);
            $this->hit('before_derived_commit', (string) $batch->batch_id);
            DB::table('wake_flush_manifest')->where('batch_id', $batch->batch_id)->update(['lake_completed' => true, 'derived_completed' => true, 'completed_at' => now(), 'updated_at' => now()]);
            StagingBatch::query()->whereKey($batch->batch_id)->update(['payload' => null, 'status' => StagingBatchStatus::Flushed, 'flushed_at' => now(), 'claimed_at' => null]);
        }, 3);
    }

    private function hit(string $point, string $batchId): void
    {
        ($this->fault)?->__invoke($point, $batchId);
    }
}
