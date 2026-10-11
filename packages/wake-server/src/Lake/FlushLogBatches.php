<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Lake;

use ArtisanBuild\WakeContracts\Enums\StagingBatchStatus;
use ArtisanBuild\WakeServer\Models\StagingBatch;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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
        $configured = config('wake-server.lake.flush_batch_limit', 20);
        $limit = max(1, $limit ?? (is_int($configured) ? $configured : 20));
        $manifests = $this->manifests($limit);
        $this->claimPending($limit - count($manifests));
        $manifests = $this->manifests($limit);

        if ($manifests === []) {
            return ['batches' => 0, 'rows' => 0];
        }

        $this->lake->bootstrap();
        $rows = 0;
        foreach ($manifests as $manifest) {
            $rows += $this->process($manifest);
        }

        return ['batches' => count($manifests), 'rows' => $rows];
    }

    /** @return list<object> */
    private function manifests(int $limit): array
    {
        return array_values(DB::table('wake_flush_batches')
            ->where('state', '<>', FlushBatchState::Done->value)
            ->orderBy('created_at')->orderBy('batch_id')->limit($limit)->get()->all());
    }

    private function claimPending(int $limit): void
    {
        if ($limit < 1) {
            return;
        }

        DB::transaction(function () use ($limit): void {
            $claimed = StagingBatch::query()->where('status', StagingBatchStatus::Pending)
                ->oldest('created_at')->orderBy('batch_id')->limit($limit)->lock('FOR UPDATE SKIP LOCKED')->get();

            foreach ($claimed as $batch) {
                $batch = $this->stagingBatch($batch);
                $batch->forceFill(['status' => StagingBatchStatus::Flushing, 'claimed_at' => now()])->save();
                DB::table('wake_flush_batches')->insertOrIgnore([
                    'batch_id' => $batch->batch_id,
                    'staging_batch_ids' => json_encode([(string) $batch->batch_id], JSON_THROW_ON_ERROR),
                    'event_days' => json_encode($this->eventDays($batch), JSON_THROW_ON_ERROR),
                    'state' => FlushBatchState::Claimed->value,
                    'claimed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }, 3);
    }

    private function process(object $manifest): int
    {
        $batchId = $this->propertyString($manifest, 'batch_id');
        $stagingIds = $this->stringList($manifest->staging_batch_ids ?? null, 'staging_batch_ids');
        $eventDays = $this->stringList($manifest->event_days ?? null, 'event_days');
        $batches = StagingBatch::query()->whereIn('batch_id', $stagingIds)->orderBy('created_at')->get();
        $events = [];
        foreach ($batches as $batch) {
            $batch = $this->stagingBatch($batch);
            array_push($events, ...$this->events($batch));
        }

        $state = FlushBatchState::from($this->propertyString($manifest, 'state'));
        if ($state === FlushBatchState::Claimed) {
            if (! $this->lake->batchCommitted($batchId, $eventDays)) {
                $this->lake->commitBatch($batchId, $events);
            }
            $this->hit('after_lake_commit', $batchId);
            DB::table('wake_flush_batches')->where('batch_id', $batchId)
                ->where('state', FlushBatchState::Claimed->value)
                ->update(['state' => FlushBatchState::LakeCommitted->value, 'lake_committed_at' => now(), 'updated_at' => now()]);
        }

        $this->complete($batchId, $stagingIds, $events);

        return count($events);
    }

    /**
     * @param  list<string>  $stagingIds
     * @param  list<array<string, mixed>>  $events
     */
    private function complete(string $batchId, array $stagingIds, array $events): void
    {
        DB::transaction(function () use ($batchId, $stagingIds, $events): void {
            $manifest = DB::table('wake_flush_batches')->where('batch_id', $batchId)->lockForUpdate()->first();
            if (($manifest->state ?? null) === FlushBatchState::Done->value) {
                return;
            }
            if (($manifest->state ?? null) !== FlushBatchState::LakeCommitted->value) {
                throw new RuntimeException("Flush batch {$batchId} is not lake committed.");
            }

            $hourly = [];
            foreach ($events as $event) {
                $at = Carbon::parse($this->eventString($event, 'occurred_at'))->utc();
                $appId = $this->eventString($event, 'app_id');
                DB::table('wake_event_locators')->insertOrIgnore([
                    'event_id' => $event['event_id'], 'app_id' => $appId,
                    'event_date' => $at->toDateString(), 'occurred_at' => $at,
                ]);
                if (in_array($event['level'], ['error', 'critical', 'alert', 'emergency'], true)) {
                    DB::table('wake_error_events')->insertOrIgnore([
                        'event_id' => $event['event_id'], 'app_id' => $appId,
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
                $key = "{$appId}|{$environment}|{$level}|{$hour->toIso8601String()}";
                $hourly[$key] = ['app_id' => $appId, 'environment' => $environment, 'level' => $level, 'hour' => $hour, 'count' => ($hourly[$key]['count'] ?? 0) + 1];
            }
            foreach ($hourly as $count) {
                DB::statement('INSERT INTO wake_hourly_counts (app_id, environment, level, hour, count) VALUES (?, ?, ?, ?, ?) ON CONFLICT (app_id, environment, level, hour) DO UPDATE SET count = wake_hourly_counts.count + excluded.count', array_values($count));
            }

            $health = StagingBatch::query()->whereIn('batch_id', $stagingIds)->get()->groupBy('app_id');
            foreach ($health as $appId => $batches) {
                $rowCount = $batches->sum('row_count');
                if (! is_int($rowCount) && ! is_float($rowCount)) {
                    throw new RuntimeException('The flush health row count is invalid.');
                }
                DB::table('wake_connected_apps')->where('id', $appId)->increment('flushed_rows', $rowCount, ['last_flushed_at' => now()]);
            }
            StagingBatch::query()->whereIn('batch_id', $stagingIds)->update([
                'payload' => null, 'status' => StagingBatchStatus::Flushed, 'flushed_at' => now(), 'claimed_at' => null,
            ]);
            DB::table('wake_flush_batches')->where('batch_id', $batchId)->update([
                'state' => FlushBatchState::Done->value, 'done_at' => now(), 'updated_at' => now(),
            ]);
            $this->hit('before_derived_commit', $batchId);
        }, 3);
    }

    /** @return list<array<string, mixed>> */
    private function events(StagingBatch $batch): array
    {
        $payload = $batch->payload;
        $rawEvents = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
        $events = [];
        foreach ($rawEvents as $event) {
            if (! is_array($event)) {
                throw new RuntimeException('A staged event is not an object.');
            }
            $normalized = [];
            foreach ($event as $key => $value) {
                if (! is_string($key)) {
                    throw new RuntimeException('A staged event has a non-string key.');
                }
                $normalized[$key] = $value;
            }
            $normalized['app_id'] = (string) $batch->app_id;
            $events[] = $normalized;
        }

        return $events;
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

    /** @return list<string> */
    private function stringList(mixed $value, string $field): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        }
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException("Flush manifest {$field} is invalid.");
        }
        $result = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new RuntimeException("Flush manifest {$field} contains a non-string value.");
            }
            $result[] = $item;
        }

        return $result;
    }

    private function propertyString(object $value, string $field): string
    {
        $item = $value->{$field} ?? null;
        if (! is_string($item)) {
            throw new RuntimeException("Flush manifest {$field} is invalid.");
        }

        return $item;
    }

    private function stagingBatch(mixed $value): StagingBatch
    {
        if (! $value instanceof StagingBatch) {
            throw new RuntimeException('The flush manifest references an invalid staging batch.');
        }

        return $value;
    }

    /** @param array<string, mixed> $event */
    private function eventString(array $event, string $key): string
    {
        $value = $event[$key] ?? null;
        if (! is_string($value)) {
            throw new RuntimeException("A staged event has an invalid {$key}.");
        }

        return $value;
    }

    private function hit(string $point, string $batchId): void
    {
        ($this->fault)?->__invoke($point, $batchId);
    }
}
