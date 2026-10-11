<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Lake;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class RetainLogs
{
    public function __construct(private LakeMaintenance $lake) {}

    /** @return array{cutoff: string, lake_rows: int, locators: int, hourly: int, errors: int} */
    public function run(?int $days = null): array
    {
        $retentionDays = max(1, $days ?? (int) config('wake-server.lake.retention_days', 30));
        $maintenanceAt = CarbonImmutable::now('UTC');
        $cutoff = $maintenanceAt->startOfDay()->subDays($retentionDays);
        $lakeRows = $this->lake->deleteBefore($cutoff);
        $deleted = DB::transaction(fn (): array => [
            'locators' => DB::table('wake_event_locators')->where('event_date', '<', $cutoff->toDateString())->delete(),
            'hourly' => DB::table('wake_hourly_counts')->where('hour', '<', $cutoff)->delete(),
            'errors' => DB::table('wake_error_events')->where('event_date', '<', $cutoff->toDateString())->delete(),
        ]);
        $this->lake->expireSnapshots($maintenanceAt);
        $this->lake->cleanupFiles();

        return ['cutoff' => $cutoff->toDateString(), 'lake_rows' => $lakeRows, ...$deleted];
    }
}
