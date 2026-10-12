<?php

declare(strict_types=1);

namespace App\Services;

use ArtisanBuild\WakeServer\Lake\LakeAppDeletion;
use ArtisanBuild\WakeServer\Lake\LakeWriterLock;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final readonly class DeleteAppLogs
{
    /** @param null|Closure(string, string): void $fault */
    public function __construct(
        private LakeAppDeletion $lake,
        private LakeWriterLock $lock,
        private ?Closure $fault = null,
    ) {}

    /** @return array{lake_rows: int, locators: int, hourly_groups: int, hourly_events: int, errors: int, staging_batches: int, staging_rows: int} */
    public function preview(string $appId): array
    {
        $appId = $this->appId($appId);
        $result = null;
        $acquired = $this->lock->execute(function () use ($appId, &$result): void {
            $result = $this->counts($appId, $this->lake->countAppRows($appId));
        });
        if (! $acquired || ! is_array($result)) {
            throw new RuntimeException('The lake writer is busy.');
        }

        return $result;
    }

    /** @return array{lake_rows: int, locators: int, hourly_groups: int, hourly_events: int, errors: int, staging_batches: int, staging_rows: int, manifests_reconciled: int} */
    public function run(string $appId, ?Closure $audit = null): array
    {
        $appId = $this->appId($appId);
        $result = null;
        $acquired = $this->lock->execute(function () use ($appId, $audit, &$result): void {
            $barrier = DB::transaction(function () use ($appId): array {
                $app = DB::table('wake_connected_apps')->where('id', $appId)->lockForUpdate()->first(['id']);
                if ($app === null) {
                    throw new RuntimeException('The target app does not exist.');
                }

                return $this->removeStagingAndReconcile($appId);
            }, 3);
            $this->hit('after_staging_barrier', $appId);
            $lakeRows = $this->lake->deleteAppRows($appId);
            $this->hit('after_lake_delete', $appId);
            $derived = DB::transaction(function () use ($appId, $audit): array {
                $counts = $this->counts($appId, 0);
                $counts['locators'] = DB::table('wake_event_locators')->where('app_id', $appId)->delete();
                $counts['hourly_groups'] = DB::table('wake_hourly_counts')->where('app_id', $appId)->delete();
                $counts['errors'] = DB::table('wake_error_events')->where('app_id', $appId)->delete();
                $audit?->__invoke();
                $this->hit('before_postgres_commit', $appId);

                return $counts;
            }, 3);
            $maintenanceAt = CarbonImmutable::now('UTC');
            $this->lake->expireSnapshots($maintenanceAt);
            $this->lake->cleanupFiles();
            $result = [
                'lake_rows' => $lakeRows,
                'locators' => $derived['locators'],
                'hourly_groups' => $derived['hourly_groups'],
                'hourly_events' => $derived['hourly_events'],
                'errors' => $derived['errors'],
                'staging_batches' => $barrier['staging_batches'],
                'staging_rows' => $barrier['staging_rows'],
                'manifests_reconciled' => $barrier['manifests_reconciled'],
            ];
        });
        if (! $acquired || ! is_array($result)) {
            throw new RuntimeException('The lake writer is busy.');
        }

        return $result;
    }

    /** @return array{lake_rows: int, locators: int, hourly_groups: int, hourly_events: int, errors: int, staging_batches: int, staging_rows: int} */
    private function counts(string $appId, int $lakeRows): array
    {
        return [
            'lake_rows' => $lakeRows,
            'locators' => DB::table('wake_event_locators')->where('app_id', $appId)->count(),
            'hourly_groups' => DB::table('wake_hourly_counts')->where('app_id', $appId)->count(),
            'hourly_events' => (int) DB::table('wake_hourly_counts')->where('app_id', $appId)->sum('count'),
            'errors' => DB::table('wake_error_events')->where('app_id', $appId)->count(),
            'staging_batches' => DB::table('wake_staging_batches')->where('app_id', $appId)->count(),
            'staging_rows' => (int) DB::table('wake_staging_batches')->where('app_id', $appId)->sum('row_count'),
        ];
    }

    /** @return array{staging_batches: int, staging_rows: int, manifests_reconciled: int} */
    private function removeStagingAndReconcile(string $appId): array
    {
        $target = DB::table('wake_staging_batches')->where('app_id', $appId)->lockForUpdate()->get(['batch_id', 'row_count']);
        $targetIds = $target->pluck('batch_id')->map(static fn (mixed $id): string => (string) $id)->all();
        $reconciled = 0;
        if ($targetIds !== []) {
            foreach (DB::table('wake_flush_batches')->where('state', '<>', 'done')->lockForUpdate()->get() as $manifest) {
                $ids = $this->stringList($manifest->staging_batch_ids ?? null);
                if (array_intersect($ids, $targetIds) === []) {
                    continue;
                }
                $remaining = array_values(array_diff($ids, $targetIds));
                if ($remaining === []) {
                    DB::table('wake_flush_batches')->where('batch_id', $manifest->batch_id)->delete();
                } else {
                    DB::table('wake_flush_batches')->where('batch_id', $manifest->batch_id)->update([
                        'staging_batch_ids' => json_encode($remaining, JSON_THROW_ON_ERROR),
                        'event_days' => json_encode($this->eventDays($remaining), JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
                }
                $reconciled++;
            }
        }
        $batches = $target->count();
        $rows = (int) $target->sum('row_count');
        if ($targetIds !== []) {
            DB::table('wake_staging_batches')->whereIn('batch_id', $targetIds)->delete();
        }

        return ['staging_batches' => $batches, 'staging_rows' => $rows, 'manifests_reconciled' => $reconciled];
    }

    /** @param list<string> $batchIds
     * @return list<string>
     */
    private function eventDays(array $batchIds): array
    {
        $days = [];
        foreach (DB::table('wake_staging_batches')->whereIn('batch_id', $batchIds)->get(['payload']) as $batch) {
            $payload = is_string($batch->payload ?? null) ? json_decode($batch->payload, true, 32, JSON_THROW_ON_ERROR) : $batch->payload;
            foreach (is_array($payload['rows'] ?? null) ? $payload['rows'] : [] as $row) {
                if (is_array($row) && is_string($row['occurred_at'] ?? null)) {
                    $days[] = CarbonImmutable::parse($row['occurred_at'])->utc()->toDateString();
                }
            }
        }

        return array_values(array_unique($days));
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        $value = is_string($value) ? json_decode($value, true, 32, JSON_THROW_ON_ERROR) : $value;
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException('A flush manifest has invalid staging coverage.');
        }

        return array_map(static function (mixed $id): string {
            if (! is_string($id)) {
                throw new RuntimeException('A flush manifest has invalid staging coverage.');
            }

            return $id;
        }, $value);
    }

    private function appId(string $appId): string
    {
        if (trim($appId) === '' || mb_strlen($appId) > 255) {
            throw new InvalidArgumentException('The app id must be a non-blank string of at most 255 characters.');
        }

        return $appId;
    }

    private function hit(string $point, string $appId): void
    {
        ($this->fault)?->__invoke($point, $appId);
    }
}
