<?php

declare(strict_types=1);

use App\Services\WakeQueries;
use ArtisanBuild\WakeServer\Lake\LakeReader;
use Illuminate\Support\Facades\DB;

final class RecordingLakeReader implements LakeReader
{
    /** @var list<string> */
    public array $sql = [];

    /** @var list<list<array<string, mixed>>> */
    public array $results = [];

    public function query(string $sql, int $timeout = 20): array
    {
        $this->sql[] = $sql;

        return array_shift($this->results) ?? [];
    }

    public function literal(?string $value): string
    {
        return $value === null ? 'NULL' : "'".str_replace("'", "''", $value)."'";
    }
}

beforeEach(function (): void {
    $this->lake = new RecordingLakeReader;
    $this->queries = new WakeQueries($this->lake);
});

it('builds bounded UTC day-pruned literal and regex searches without raw fragments', function (): void {
    $this->queries->search([
        'app' => "app'one",
        'from' => '2026-10-01T23:30:00-04:00',
        'to' => '2026-10-02T05:00:00Z',
        'environment' => 'production',
        'level' => 'ERROR',
        'text' => "can't fail",
        'limit' => 500,
    ]);

    expect($this->lake->sql)->toHaveCount(1);
    $sql = $this->lake->sql[0];
    expect($sql)->toContain(
        "event_date BETWEEN CAST('2026-10-02T03:30:00+00:00' AS TIMESTAMPTZ)::DATE AND CAST('2026-10-02T05:00:00+00:00' AS TIMESTAMPTZ)::DATE",
        "app_id = 'app''one'",
        "environment = 'production'",
        "level = 'error'",
        "contains(lower(message), lower('can''t fail'))",
        'ORDER BY occurred_at DESC, event_id DESC LIMIT 500',
    );

    $this->queries->search([
        'all_apps' => true,
        'from' => '2026-10-01T00:00:00Z',
        'to' => '2026-10-02T00:00:00Z',
        'regex' => "failed('|;) DROP TABLE logs",
    ]);
    expect($this->lake->sql[1])->toContain("regexp_matches(message, 'failed(''|;) DROP TABLE logs', 'i')")
        ->not->toContain('app_id =');
});

it('uses an opaque stable newest-first cursor for bounded tails', function (): void {
    $this->lake->results[] = [[
        'event_id' => '01EVENT',
        'occurred_at' => '2026-10-02T04:00:00+00:00',
    ]];
    $first = $this->queries->tail([
        'app' => 'alpha',
        'from' => '2026-10-01T00:00:00Z',
        'to' => '2026-10-02T00:00:00Z',
        'limit' => 25,
    ]);

    expect($first['next_cursor'])->toBeString()->not->toContain('01EVENT');
    $this->queries->tail([
        'app' => 'alpha',
        'from' => '2026-10-01T00:00:00Z',
        'to' => '2026-10-02T00:00:00Z',
        'cursor' => $first['next_cursor'],
        'limit' => 25,
    ]);

    expect($this->lake->sql[1])->toContain(
        "occurred_at < CAST('2026-10-02T04:00:00+00:00' AS TIMESTAMPTZ)",
        "event_id < '01EVENT'",
        'ORDER BY occurred_at DESC, event_id DESC LIMIT 25',
    );
});

it('uses PostgreSQL derived tables for aggregates errors apps and health', function (): void {
    DB::table('wake_connected_apps')->insert([
        'id' => 'alpha', 'enabled' => true, 'accepted_rows' => 12, 'dropped_rows' => 1,
        'rejected_batches' => 2, 'rate_limit_hits' => 3, 'flushed_rows' => 10,
        'last_seen_at' => '2026-10-02 04:00:00+00', 'last_flushed_at' => '2026-10-02 03:59:30+00',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('wake_hourly_counts')->insert([
        ['app_id' => 'alpha', 'environment' => 'production', 'level' => 'error', 'hour' => '2026-10-02 03:00:00+00', 'count' => 4],
        ['app_id' => 'alpha', 'environment' => 'staging', 'level' => 'info', 'hour' => '2026-10-02 04:00:00+00', 'count' => 6],
    ]);
    DB::table('wake_error_events')->insert([
        'event_id' => '01ERROR', 'app_id' => 'alpha', 'event_date' => '2026-10-02',
        'occurred_at' => '2026-10-02 03:30:00+00', 'level' => 'error', 'channel' => 'app',
        'message' => 'failure', 'context' => '{}', 'extra' => '{}', 'exception' => null,
        'environment' => 'production', 'hostname' => 'host', 'correlation_id' => null,
    ]);

    $aggregate = $this->queries->aggregate([
        'app' => 'alpha', 'from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-03T00:00:00Z',
        'group_by' => ['environment', 'level'],
    ]);
    $errors = $this->queries->recentErrors([
        'all_apps' => true, 'from' => '2026-01-01T00:00:00Z', 'to' => '2026-10-03T00:00:00Z', 'limit' => 1,
    ]);
    $apps = $this->queries->listApps(['all_apps' => true]);
    $health = $this->queries->ingestHealth(['app' => 'alpha']);

    expect($aggregate['rows'])->toHaveCount(2)
        ->and($errors['rows'])->toHaveCount(1)
        ->and($errors['rows'][0]['event_id'])->toBe('01ERROR')
        ->and($apps['rows'][0]['environments'])->toBe(['production', 'staging'])
        ->and($health['rows'][0]['pending_batches'])->toBe(0)
        ->and($health['rows'][0]['lag_seconds'])->toBe(30.0)
        ->and($this->lake->sql)->toBe([]);
});

it('looks up context in PostgreSQL then queries one exact app and UTC day with hard bounds', function (): void {
    DB::table('wake_event_locators')->insert([
        'event_id' => '01TARGET', 'app_id' => 'alpha', 'event_date' => '2026-10-02',
        'occurred_at' => '2026-10-02 03:30:00+00',
    ]);

    $this->queries->context('alpha', '01TARGET', 50, 50);
    expect($this->lake->sql)->toHaveCount(1);
    expect($this->lake->sql[0])->toContain(
        "event_date = CAST('2026-10-02' AS DATE)",
        "app_id = 'alpha'",
        "event_id <= '01TARGET'",
        'LIMIT 51',
        'LIMIT 50',
    );

    $missing = $this->queries->context('other', '01TARGET');
    expect($missing['rows'])->toBe([])->and($this->lake->sql)->toHaveCount(1);
});

it('rejects implicit scope invalid windows caps groupings searches and cursors', function (): void {
    $base = ['from' => '2026-10-01T00:00:00Z', 'to' => '2026-10-02T00:00:00Z', 'text' => 'x'];
    expect(fn () => $this->queries->search($base))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->queries->search([...$base, 'app' => 'a', 'all_apps' => true]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->queries->search([...$base, 'app' => 'a', 'regex' => 'x']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->queries->search([...$base, 'app' => 'a', 'to' => '2026-10-09T00:00:01Z']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->queries->search([...$base, 'app' => 'a', 'limit' => 501]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->queries->tail(['app' => 'a', 'from' => $base['from'], 'to' => $base['to'], 'cursor' => 'not-valid']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->queries->aggregate(['all_apps' => true, 'from' => $base['from'], 'to' => $base['to'], 'group_by' => ['raw_sql']]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->queries->context('a', 'event', 51, 0))->toThrow(InvalidArgumentException::class);
});
