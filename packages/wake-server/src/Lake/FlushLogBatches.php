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
    public function __construct(
        private readonly LakeWriter $lake,
        private readonly LakeWriterLock $lock,
        private readonly ?Closure $fault = null,
    ) {}

    /** @return array{batches: int, rows: int} */
    public function run(?int $limit = null): array
    {
        $result = ['batches' => 0, 'rows' => 0];
        $this->lock->execute(function () use ($limit, &$result): void {
            $result = $this->runLocked($limit);
        });

        return $result;
    }

    /** @return array{batches: int, rows: int} */
    private function runLocked(?int $limit): array
    {
        StagingBatch::query()->where('status', StagingBatchStatus::Flushing)
            ->where('claimed_at', '<', now()->subMinutes(5))
            ->update(['status' => StagingBatchStatus::Pending, 'claimed_at' => null]);

        $configuredLimit = config('wake-server.lake.flush_batch_limit', 20);
        $batches = $this->claim($limit ?? (is_int($configuredLimit) ? $configuredLimit : 20));
        if ($batches->isEmpty()) {
            return ['batches' => 0, 'rows' => 0];
        }

        $this->lake->bootstrap();
        $rows = 0;

        foreach ($batches as $batch) {
            $events = $this->events($batch);
            $this->lake->commitBatch((string) $batch->batch_id, $events);
            DB::table('wake_flush_batches')->where('batch_id', $batch->batch_id)->update([
                'state' => FlushBatchState::LakeCommitted->value,
                'lake_committed_at' => now(),
                'updated_at' => now(),
            ]);
            $this->hit('after_lake_commit', (string) $batch->batch_id);
            $this->complete($batch, $events);
            $rows += count($events);
        }

        return ['batches' => $batches->count(), 'rows' => $rows];
    }

    /** @return Collection<int, StagingBatch> */
    private function claim(int $limit): Collection
    {
        $result = DB::transaction(function () use ($limit): Collection {
            /** @var Collection<int, StagingBatch> $claimed */
            $claimed = StagingBatch::query()->where('status', StagingBatchStatus::Pending)
                ->oldest('created_at')->limit(max(1, $limit))->lock('FOR UPDATE SKIP LOCKED')->get();
            foreach ($claimed as $batch) {
                $batch->forceFill(['status' => StagingBatchStatus::Flushing, 'claimed_at' => now()])->save();
                DB::table('wake_flush_batches')->insertOrIgnore([
                    'batch_id' => $batch->batch_id,
                    'staging_batch_ids' => json_encode([(string) $batch->batch_id], JSON_THROW_ON_ERROR),
                    'event_days' => json_encode($this->eventDays($batch), JSON_THROW_ON_ERROR),
                    'state' => FlushBatchState::Claimed->value,
                    'claimed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $claimed;
        }, 3);
        if (! $result instanceof Collection) {
            throw new \RuntimeException('The staging claim returned an invalid result.');
        }

        return $result->map(function (mixed $batch): StagingBatch {
            if (! $batch instanceof StagingBatch) {
                throw new \RuntimeException('The staging claim returned an invalid batch.');
            }

            return $batch;
        })->values();
    }

    /** @return list<array<string, mixed>> */
    private function events(StagingBatch $batch): array
    {
        $payload = $batch->payload;
        $rawEvents = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
        $events = [];
        foreach ($rawEvents as $event) {
            if (! is_array($event)) {
                throw new \RuntimeException('A staged event is not an object.');
            }
            $normalized = [];
            foreach ($event as $key => $value) {
                if (! is_string($key)) {
                    throw new \RuntimeException('A staged event has a non-string key.');
                }
                $normalized[$key] = $value;
            }
            $normalized['app_id'] = (string) $batch->app_id;
            $events[] = $normalized;
        }

        return $events;
    }

    /** @param list<array<string, mixed>> $events */
    private function complete(StagingBatch $batch, array $events): void
    {
        DB::transaction(function () use ($batch, $events): void {
            $manifest = DB::table('wake_flush_batches')->where('batch_id', $batch->batch_id)->lockForUpdate()->first();
            if (($manifest->state ?? null) === FlushBatchState::Done->value) {
                return;
            }

            $hourly = [];
            foreach ($events as $event) {
                $at = Carbon::parse($this->eventString($event, 'occurred_at'))->utc();
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
                $environment = $this->eventString($event, 'environment');
                $level = $this->eventString($event, 'level');
                $key = $batch->app_id.'|'.$environment.'|'.$level.'|'.$hour->toIso8601String();
                $hourly[$key] = ['app_id' => $batch->app_id, 'environment' => $event['environment'], 'level' => $event['level'], 'hour' => $hour, 'count' => ($hourly[$key]['count'] ?? 0) + 1];
            }
            foreach ($hourly as $count) {
                DB::statement('INSERT INTO wake_hourly_counts (app_id, environment, level, hour, count) VALUES (?, ?, ?, ?, ?) ON CONFLICT (app_id, environment, level, hour) DO UPDATE SET count = wake_hourly_counts.count + excluded.count', array_values($count));
            }
            DB::table('wake_connected_apps')->where('id', $batch->app_id)->increment('flushed_rows', count($events), ['last_flushed_at' => now()]);
            $this->hit('before_derived_commit', (string) $batch->batch_id);
            DB::table('wake_flush_batches')->where('batch_id', $batch->batch_id)->update(['state' => FlushBatchState::Done->value, 'done_at' => now(), 'updated_at' => now()]);
            StagingBatch::query()->whereKey($batch->batch_id)->update(['payload' => null, 'status' => StagingBatchStatus::Flushed, 'flushed_at' => now(), 'claimed_at' => null]);
        }, 3);
    }

    private function hit(string $point, string $batchId): void
    {
        ($this->fault)?->__invoke($point, $batchId);
    }

    /** @return list<string> */
    private function eventDays(StagingBatch $batch): array
    {
        $days = [];
        foreach ($this->events($batch) as $event) {
            $days[] = Carbon::parse($this->eventString($event, 'occurred_at'))->utc()->toDateString();
        }

        return array_values(array_unique($days));
    }

    /** @param array<string, mixed> $event */
    private function eventString(array $event, string $key): string
    {
        $value = $event[$key] ?? null;
        if (! is_string($value)) {
            throw new \RuntimeException("A staged event has an invalid {$key}.");
        }

        return $value;
    }
}
