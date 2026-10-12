<?php

declare(strict_types=1);

namespace App\Services;

use ArtisanBuild\WakeServer\Models\ConnectedApp;
use Illuminate\Support\Facades\DB;

final readonly class ConnectedAppHealth
{
    /**
     * @return array{
     *   last_seen_at: mixed,
     *   environments: list<string>,
     *   accepted_rows: int,
     *   flushed_rows: int,
     *   rejected_batches: int,
     *   rate_limit_hits: int,
     *   dropped_rows: int,
     *   pending_batches: int,
     *   lag_seconds: float|null,
     *   last_client_version: string|null,
     *   state: string
     * }
     */
    public function for(ConnectedApp $app): array
    {
        /** @var list<string> $environments */
        $environments = DB::table('wake_hourly_counts')
            ->where('app_id', $app->id)
            ->distinct()
            ->orderBy('environment')
            ->pluck('environment')
            ->all();
        $pendingBatches = DB::table('wake_staging_batches')
            ->where('app_id', $app->id)
            ->whereIn('status', ['pending', 'flushing'])
            ->count();
        $lagSeconds = WakeQueries::lagSeconds($app->last_seen_at, $app->last_flushed_at);
        $state = match (true) {
            $app->last_seen_at === null => 'never_seen',
            $app->last_flushed_at === null => 'pending',
            $pendingBatches > 0 || $lagSeconds > 0 => 'lagging',
            default => 'caught_up',
        };

        return [
            'last_seen_at' => $app->last_seen_at,
            'environments' => $environments,
            'accepted_rows' => $app->accepted_rows,
            'flushed_rows' => $app->flushed_rows,
            'rejected_batches' => $app->rejected_batches,
            'rate_limit_hits' => $app->rate_limit_hits,
            'dropped_rows' => $app->dropped_rows,
            'pending_batches' => $pendingBatches,
            'lag_seconds' => $lagSeconds,
            'last_client_version' => $app->last_client_version,
            'state' => $state,
        ];
    }
}
