<?php

declare(strict_types=1);

namespace App\Verification;

use ArtisanBuild\WakeServer\Lake\DuckDb;
use ArtisanBuild\WakeServer\Lake\LakeWriterLock;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ScaleCorpusLoader
{
    /** @param null|Closure(string, int): void $fault */
    public function __construct(
        private readonly DuckDb $lake,
        private readonly LakeWriterLock $lock,
        private readonly ?Closure $fault = null,
    ) {}

    /**
     * @param  null|Closure(int, int, int): void  $progress
     * @return array{lake_rows: int, locators: int, errors: int, hourly_events: int}
     */
    public function run(ScaleCorpusPlan $plan, ?Closure $progress = null): array
    {
        $this->prepareRun($plan);
        $ran = $this->lock->execute(function () use ($plan, $progress): void {
            $this->lake->bootstrap();
            for ($offset = 0, $number = 1; $offset < $plan->rows; $offset += $plan->chunk, $number++) {
                $count = min($plan->chunk, $plan->rows - $offset);
                $this->processChunk($plan, $number, $offset, $count);
                $progress?->__invoke(min($offset + $count, $plan->rows), $plan->rows, $number);
            }
        });
        if (! $ran) {
            throw new RuntimeException('The shared DuckLake writer is busy.');
        }

        return $this->counts($plan);
    }

    private function prepareRun(ScaleCorpusPlan $plan): void
    {
        DB::transaction(function () use ($plan): void {
            DB::table('wake_verification_runs')->insertOrIgnore([
                'run_key' => $plan->runKey, 'rows' => $plan->rows, 'apps' => $plan->apps,
                'days' => $plan->days, 'chunk_rows' => $plan->chunk, 'start_at' => ScaleCorpusPlan::START_UTC,
                'completed_rows' => 0, 'state' => 'running', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $stored = DB::table('wake_verification_runs')->where('run_key', $plan->runKey)->lockForUpdate()->first();
            if (! is_object($stored) || [
                (int) $stored->rows, (int) $stored->apps, (int) $stored->days, (int) $stored->chunk_rows,
            ] !== [$plan->rows, $plan->apps, $plan->days, $plan->chunk]) {
                throw new RuntimeException('The run key already exists with a different corpus plan.');
            }

            $apps = [];
            for ($app = 1; $app <= $plan->apps; $app++) {
                $id = $this->appId($plan, $app);
                $apps[] = ['id' => $id, 'name' => "Synthetic verification {$plan->runKey} app {$app}", 'enabled' => true, 'created_at' => now(), 'updated_at' => now()];
            }
            DB::table('wake_connected_apps')->insertOrIgnore($apps);
        }, 3);
    }

    private function processChunk(ScaleCorpusPlan $plan, int $number, int $offset, int $count): void
    {
        if ($count > DuckDb::MAX_INSERT_ROWS) {
            throw new RuntimeException('A verification DuckLake insertion chunk cannot exceed 100000 events.');
        }
        $batchId = $plan->batchId($offset);
        DB::table('wake_verification_chunks')->insertOrIgnore([
            'run_key' => $plan->runKey, 'chunk_number' => $number, 'batch_id' => $batchId,
            'row_offset' => $offset, 'row_count' => $count, 'state' => 'planned',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $chunk = DB::table('wake_verification_chunks')->where(['run_key' => $plan->runKey, 'chunk_number' => $number])->first();
        if (($chunk->state ?? null) === 'done') {
            return;
        }

        $lakeRows = $this->lakeChunkRows($plan, $batchId, $offset, $count);
        if ($lakeRows === 0) {
            $this->insertLakeChunk($plan, $batchId, $offset, $count);
            $lakeRows = $count;
        }
        if ($lakeRows !== $count) {
            throw new RuntimeException("Verification chunk {$number} has an unexpected lake row count.");
        }
        $this->fault?->__invoke('after_lake_commit', $number);
        DB::table('wake_verification_chunks')->where(['run_key' => $plan->runKey, 'chunk_number' => $number])
            ->update(['state' => 'lake_committed', 'lake_committed_at' => now(), 'updated_at' => now()]);

        $this->completeChunk($plan, $number, $offset, $count);
    }

    private function completeChunk(ScaleCorpusPlan $plan, int $number, int $offset, int $count): void
    {
        DB::transaction(function () use ($plan, $number, $offset, $count): void {
            $chunk = DB::table('wake_verification_chunks')->where(['run_key' => $plan->runKey, 'chunk_number' => $number])->lockForUpdate()->first();
            if (($chunk->state ?? null) === 'done') {
                return;
            }
            if (($chunk->state ?? null) !== 'lake_committed') {
                throw new RuntimeException("Verification chunk {$number} is not lake committed.");
            }

            [$generated, $bindings] = $this->postgresGenerated($plan, $offset, $count);
            DB::statement($generated.' INSERT INTO wake_event_locators (event_id, app_id, event_date, occurred_at) SELECT event_id, app_id, occurred_at::date, occurred_at FROM generated ON CONFLICT (event_id) DO NOTHING', $bindings);
            DB::statement($generated." INSERT INTO wake_error_events (event_id, app_id, event_date, occurred_at, level, channel, message, context, extra, exception, environment, hostname, correlation_id) SELECT event_id, app_id, occurred_at::date, occurred_at, level, 'stack', message, context, extra, exception, environment, hostname, correlation_id FROM generated WHERE level IN ('error', 'critical', 'alert', 'emergency') ON CONFLICT (event_id) DO NOTHING", $bindings);
            DB::statement($generated.' INSERT INTO wake_hourly_counts (app_id, environment, level, hour, count) SELECT app_id, environment, level, date_trunc(\'hour\', occurred_at), count(*) FROM generated GROUP BY app_id, environment, level, date_trunc(\'hour\', occurred_at) ON CONFLICT (app_id, environment, level, hour) DO UPDATE SET count = wake_hourly_counts.count + excluded.count', $bindings);
            DB::statement($generated.' UPDATE wake_connected_apps AS apps SET accepted_rows = apps.accepted_rows + generated_apps.event_count, flushed_rows = apps.flushed_rows + generated_apps.event_count, last_seen_at = generated_apps.last_occurred_at, last_flushed_at = generated_apps.last_occurred_at, last_client_version = ?::text, updated_at = now() FROM (SELECT app_id, count(*) AS event_count, max(occurred_at) AS last_occurred_at FROM generated GROUP BY app_id) AS generated_apps WHERE apps.id = generated_apps.app_id', [...$bindings, $plan->clientVersion()]);

            DB::table('wake_verification_chunks')->where(['run_key' => $plan->runKey, 'chunk_number' => $number])
                ->update(['state' => 'done', 'done_at' => now(), 'updated_at' => now()]);
            $completed = (int) DB::table('wake_verification_chunks')->where('run_key', $plan->runKey)->where('state', 'done')->sum('row_count');
            DB::table('wake_verification_runs')->where('run_key', $plan->runKey)->update([
                'completed_rows' => $completed, 'state' => $completed === $plan->rows ? 'done' : 'running', 'updated_at' => now(),
            ]);
            $this->fault?->__invoke('before_postgres_commit', $number);
        }, 3);
    }

    private function insertLakeChunk(ScaleCorpusPlan $plan, string $batchId, int $offset, int $count): void
    {
        $run = $this->lake->literal($plan->runKey);
        $batch = $this->lake->literal($batchId);
        $start = $this->lake->literal(ScaleCorpusPlan::START_UTC);
        $end = $offset + $count;
        $seconds = $plan->days * 86_400;
        $eventId = $this->duckUlid($this->lake->literal($plan->eventIdPrefix));
        $sql = <<<SQL
            BEGIN TRANSACTION;
            INSERT INTO lake.logs (batch_id, event_id, app_id, event_date, occurred_at, level, channel, message, context, extra, exception, environment, hostname, correlation_id, ingested_at)
            SELECT {$batch}, {$eventId}, printf('verify-%s-app-%02d', {$run}, (ordinal % {$plan->apps}) + 1),
                occurred_at::DATE, occurred_at, level, 'stack',
                CASE WHEN ordinal = 0 THEN 'WAKE_VERIFICATION_KNOWN_EVENT ' || {$run} WHEN level = 'error' THEN 'Checkout failed [E500] request-' || ordinal::VARCHAR ELSE 'Synthetic request completed request-' || ordinal::VARCHAR END,
                json_object('request_id', 'request-' || ordinal::VARCHAR, 'duration_ms', 10 + (ordinal % 1200), 'route', CASE WHEN ordinal % 3 = 0 THEN '/checkout' ELSE '/dashboard' END),
                json_object('generator', 'wake:verification-load', 'run_key', {$run}),
                CASE WHEN level = 'error' THEN json_object('class', 'SyntheticVerificationException', 'message', 'Synthetic failure') ELSE NULL END,
                CASE ordinal % 3 WHEN 0 THEN 'production' WHEN 1 THEN 'staging' ELSE 'development' END,
                printf('verify-%s-host-%02d', {$run}, (ordinal % 8) + 1), 'verify-' || {$run} || '-request-' || floor(ordinal / 5)::VARCHAR,
                CAST({$start} AS TIMESTAMPTZ) + floor((ordinal::DECIMAL * {$seconds}) / {$plan->rows}) * INTERVAL '1 second'
            FROM (SELECT ordinal, CAST({$start} AS TIMESTAMPTZ) + floor((ordinal::DECIMAL * {$seconds}) / {$plan->rows}) * INTERVAL '1 second' AS occurred_at,
                CASE WHEN ordinal % 100 = 0 THEN 'error' WHEN ordinal % 31 = 0 THEN 'warning' WHEN ordinal % 7 = 0 THEN 'debug' ELSE 'info' END AS level
                FROM range({$offset}, {$end}) rows(ordinal));
            COMMIT;
            SQL;
        $this->lake->query($sql, 900);
    }

    /** @return array{string, list<int|string>} */
    private function postgresGenerated(ScaleCorpusPlan $plan, int $offset, int $count): array
    {
        $end = $offset + $count - 1;
        $eventId = $this->postgresUlid('?::text');
        $sql = <<<SQL
            WITH generated AS (
                SELECT ordinal, {$eventId} AS event_id,
                    format('verify-%s-app-%s', ?::text, lpad(((ordinal % ?::bigint) + 1)::text, 2, '0')) AS app_id,
                    timestamp with time zone '2026-09-12 00:00:00+00' + floor((ordinal::numeric * ?::bigint) / ?::bigint) * interval '1 second' AS occurred_at,
                    CASE WHEN ordinal % 100 = 0 THEN 'error' WHEN ordinal % 31 = 0 THEN 'warning' WHEN ordinal % 7 = 0 THEN 'debug' ELSE 'info' END AS level,
                    CASE WHEN ordinal = 0 THEN 'WAKE_VERIFICATION_KNOWN_EVENT ' || ?::text WHEN ordinal % 100 = 0 THEN 'Checkout failed [E500] request-' || ordinal::text ELSE 'Synthetic request completed request-' || ordinal::text END AS message,
                    jsonb_build_object('request_id', 'request-' || ordinal::text, 'duration_ms', 10 + (ordinal % 1200), 'route', CASE WHEN ordinal % 3 = 0 THEN '/checkout' ELSE '/dashboard' END) AS context,
                    jsonb_build_object('generator', 'wake:verification-load', 'run_key', ?::text) AS extra,
                    CASE WHEN ordinal % 100 = 0 THEN jsonb_build_object('class', 'SyntheticVerificationException', 'message', 'Synthetic failure') END AS exception,
                    CASE ordinal % 3 WHEN 0 THEN 'production' WHEN 1 THEN 'staging' ELSE 'development' END AS environment,
                    format('verify-%s-host-%s', ?::text, lpad(((ordinal % 8) + 1)::text, 2, '0')) AS hostname,
                    'verify-' || ?::text || '-request-' || floor(ordinal / 5)::text AS correlation_id
                FROM generate_series(?::bigint, ?::bigint) AS rows(ordinal)
            )
            SQL;

        return [$sql, [$plan->eventIdPrefix, $plan->runKey, $plan->apps, $plan->days * 86_400, $plan->rows, $plan->runKey, $plan->runKey, $plan->runKey, $plan->runKey, $offset, $end]];
    }

    private function lakeChunkRows(ScaleCorpusPlan $plan, string $batchId, int $offset, int $count): int
    {
        $days = implode(', ', array_map(fn (string $day): string => 'CAST('.$this->lake->literal($day).' AS DATE)', $plan->eventDays($offset, $count)));
        $rows = $this->lake->query('SELECT count(*) AS rows FROM lake.logs WHERE event_date IN ('.$days.') AND batch_id = '.$this->lake->literal($batchId).';');

        return (int) ($rows[0]['rows'] ?? 0);
    }

    /** @return array{lake_rows: int, locators: int, errors: int, hourly_events: int} */
    private function counts(ScaleCorpusPlan $plan): array
    {
        $prefix = "verify-{$plan->runKey}-app-";
        $lake = $this->lake->query('SELECT count(*) AS rows FROM lake.logs WHERE starts_with(app_id, '.$this->lake->literal($prefix).');');

        return [
            'lake_rows' => (int) ($lake[0]['rows'] ?? 0),
            'locators' => DB::table('wake_event_locators')->where('app_id', 'like', $prefix.'%')->count(),
            'errors' => DB::table('wake_error_events')->where('app_id', 'like', $prefix.'%')->count(),
            'hourly_events' => (int) DB::table('wake_hourly_counts')->where('app_id', 'like', $prefix.'%')->sum('count'),
        ];
    }

    private function appId(ScaleCorpusPlan $plan, int $app): string
    {
        return sprintf('verify-%s-app-%02d', $plan->runKey, $app);
    }

    private function duckUlid(string $prefix): string
    {
        $parts = [];
        foreach ([1_073_741_824, 33_554_432, 1_048_576, 32_768, 1_024, 32, 1] as $divisor) {
            $parts[] = "substr('0123456789ABCDEFGHJKMNPQRSTVWXYZ', ((floor(ordinal / {$divisor})::BIGINT % 32) + 1), 1)";
        }

        return $prefix.' || '.implode(' || ', $parts);
    }

    private function postgresUlid(string $prefix): string
    {
        $parts = [];
        foreach ([1_073_741_824, 33_554_432, 1_048_576, 32_768, 1_024, 32, 1] as $divisor) {
            $parts[] = "substr('0123456789ABCDEFGHJKMNPQRSTVWXYZ', (((ordinal / {$divisor}) % 32) + 1)::integer, 1)";
        }

        return $prefix.' || '.implode(' || ', $parts);
    }
}
