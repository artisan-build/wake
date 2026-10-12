<?php

declare(strict_types=1);

use ArtisanBuild\WakeServer\Lake\DuckDb;

beforeEach(function (): void {
    $this->binary = tempnam(sys_get_temp_dir(), 'wake-duckdb-');
    chmod($this->binary, 0700);
    config()->set('wake-server.lake.binary', $this->binary);
    config()->set('wake-server.lake.extension_directory', base_path('extensions'));
});

afterEach(function (): void {
    @unlink($this->binary);
});

it('returns only rows inside explicit result markers', function (): void {
    file_put_contents($this->binary, <<<'SH'
#!/bin/sh
cat >/dev/null
printf '%s\n' '{"Success":true}' '{"marker":"__WAKE_RESULT_START__"}' '{"Success":true}' '{"event_id":"01ABC"}' '{"marker":"__WAKE_RESULT_END__"}' '{"trailing":"status"}'
SH);

    expect((new DuckDb)->query('SELECT 1'))->toBe([['event_id' => '01ABC']]);
});

it('applies independently configured reader memory and thread settings', function (): void {
    file_put_contents($this->binary, <<<'SH'
#!/bin/sh
set -e
input="$(cat)"
    printf '%s' "$input" | grep -q "SET threads=1"
    printf '%s' "$input" | grep -q "memory_limit='96MB'"
printf '%s\n' '{"marker":"__WAKE_RESULT_START__"}' '{"marker":"__WAKE_RESULT_END__"}'
SH);
    config()->set('wake-server.lake.read.threads', 1);
    config()->set('wake-server.lake.read.memory_limit', '96MB');

    expect((new DuckDb('wake-server.lake.read.threads', 'wake-server.lake.read.memory_limit'))->query('SELECT 1'))->toBe([]);
});

it('sends cloud credentials on stdin and redacts failures', function (): void {
    file_put_contents($this->binary, <<<'SH'
#!/bin/sh
set -e
input="$(cat)"
printf '%s' "$input" | grep -q 'db-private-value'
printf '%s' "$input" | grep -q 's3-private-value'
printf '%s\n' 'password=db-private-value SECRET '\''s3-private-value'\''' >&2
exit 1
SH);
    config()->set('wake-server.lake.cloud', true);
    config()->set('database.connections.pgsql', [
        'host' => 'db.internal', 'port' => 5432, 'database' => 'wake',
        'username' => 'wake', 'password' => 'db-private-value',
    ]);
    config()->set('filesystems.disks.s3', [
        'bucket' => 'wake', 'key' => 'key-private-value', 'secret' => 's3-private-value',
        'region' => 'auto', 'endpoint' => 'https://objects.example',
    ]);

    expect(fn () => (new DuckDb)->query('SELECT 1'))
        ->toThrow(RuntimeException::class, 'password=[REDACTED] SECRET \'[REDACTED]\'');
});

it('rejects an unsafe PostgreSQL metadata schema identifier', function (): void {
    config()->set('wake-server.lake.postgres_catalog', true);
    config()->set('wake-server.lake.metadata_schema', 'unsafe;drop');
    config()->set('wake-server.lake.data_path', '/tmp/wake-lake-test/');
    config()->set('database.connections.pgsql', [
        'host' => '127.0.0.1', 'port' => 5432, 'database' => 'wake_test',
        'username' => 'root', 'password' => '', 'sslmode' => 'prefer',
    ]);

    expect(fn () => (new DuckDb)->query('SELECT 1'))
        ->toThrow(RuntimeException::class, 'metadata schema is invalid');
});

it('defines the full v1 lake schema and physical layout', function (): void {
    file_put_contents($this->binary, <<<'SH'
#!/bin/sh
set -e
input="$(cat)"
printf '%s' "$input" | grep -q 'batch_id VARCHAR NOT NULL'
printf '%s' "$input" | grep -q 'exception JSON'
printf '%s' "$input" | grep -q 'PARTITIONED BY (event_date)'
printf '%s' "$input" | grep -q 'SORTED BY (app_id ASC, occurred_at ASC)'
printf '%s' "$input" | grep -q "target_file_size', '32MB'"
if printf '%s' "$input" | grep -q 'lake.flush_manifest'; then exit 1; fi
printf '%s\n' '{"marker":"__WAKE_RESULT_START__"}' '{"marker":"__WAKE_RESULT_END__"}'
SH);

    (new DuckDb)->bootstrap();
})->throwsNoExceptions();

it('commits one bounded batch transaction without a lake-side manifest', function (): void {
    file_put_contents($this->binary, <<<'SH'
#!/bin/sh
set -e
input="$(cat)"
printf '%s' "$input" | grep -q 'BEGIN TRANSACTION'
if printf '%s' "$input" | grep -q 'lake.flush_manifest'; then exit 1; fi
printf '%s' "$input" | grep -q 'COMMIT;'
printf '%s\n' '{"marker":"__WAKE_RESULT_START__"}' '{"marker":"__WAKE_RESULT_END__"}'
SH);
    $row = [
        'event_id' => '01ABC', 'app_id' => 'app-1', 'occurred_at' => '2026-10-11T12:00:00.000000Z',
        'level' => 'info', 'channel' => 'app', 'message' => 'ok', 'context' => [], 'extra' => [],
        'exception' => null, 'environment' => 'testing', 'hostname' => 'host', 'correlation_id' => null,
    ];

    (new DuckDb)->commitBatch('01BATCH', [$row]);
})->throwsNoExceptions();

it('detects a committed batch through its batch id and pruned event days', function (): void {
    file_put_contents($this->binary, <<<'SH'
#!/bin/sh
set -e
input="$(cat)"
printf '%s' "$input" | grep -q "event_date IN (CAST('2026-10-10' AS DATE), CAST('2026-10-11' AS DATE))"
printf '%s' "$input" | grep -q "batch_id = '01BATCH' LIMIT 1"
printf '%s\n' '{"marker":"__WAKE_RESULT_START__"}' '{"committed":1}' '{"marker":"__WAKE_RESULT_END__"}'
SH);

    expect((new DuckDb)->batchCommitted('01BATCH', ['2026-10-10', '2026-10-11']))->toBeTrue();
});

it('rejects an insertion chunk above the hard bound', function (): void {
    $row = ['event_id' => 'x'];

    expect(fn () => (new DuckDb)->commitBatch('batch', array_fill(0, 100_001, $row)))
        ->toThrow(RuntimeException::class, 'cannot exceed 100000');
});

it('selects at most three deterministic fragmented UTC days', function (): void {
    file_put_contents($this->binary, <<<'SH'
#!/bin/sh
set -e
input="$(cat)"
printf '%s' "$input" | grep -q 'HAVING count(DISTINCT filename) > 1'
printf '%s' "$input" | grep -q 'ORDER BY event_date ASC LIMIT 3'
printf '%s\n' '{"marker":"__WAKE_RESULT_START__"}' '{"event_date":"2026-10-01"}' '{"event_date":"2026-10-02"}' '{"event_date":"2026-10-03"}' '{"marker":"__WAKE_RESULT_END__"}'
SH);

    expect((new DuckDb)->fragmentedDays(9))->toBe(['2026-10-01', '2026-10-02', '2026-10-03']);
});

it('skips an already converged day without rewriting or expiring snapshots', function (): void {
    file_put_contents($this->binary, <<<'SH'
#!/bin/sh
set -e
input="$(cat)"
printf '%s' "$input" | grep -q "event_date = CAST('2026-10-01' AS DATE)"
if printf '%s' "$input" | grep -q 'CREATE TEMP TABLE'; then exit 1; fi
if printf '%s' "$input" | grep -q 'ducklake_expire_snapshots'; then exit 1; fi
printf '%s\n' '{"marker":"__WAKE_RESULT_START__"}' '{"files":1,"rows":2}' '{"marker":"__WAKE_RESULT_END__"}'
SH);

    expect((new DuckDb)->rewriteDay('2026-10-01'))->toBeFalse();
});

it('rewrites one day with worker settings and verifies convergence before expiry', function (): void {
    $state = $this->binary.'.state';
    $script = str_replace('__STATE__', escapeshellarg($state), <<<'SH'
#!/bin/sh
set -e
state=__STATE__
call=0
if [ -f "$state" ]; then call=$(cat "$state"); fi
call=$((call + 1))
printf '%s' "$call" > "$state"
input="$(cat)"
case "$call" in
  1) rows='{"files":2,"rows":2}' ;;
  2)
    printf '%s' "$input" | grep -q "CAST('2026-10-01' AS DATE)"
    printf '%s' "$input" | grep -q "memory_limit='384MB'"
    printf '%s' "$input" | grep -q "memory_limit='1433MB'"
    printf '%s' "$input" | grep -q "target_file_size', '32MB'"
    printf '%s' "$input" | grep -q 'SET threads=1'
    printf '%s' "$input" | grep -q 'SET temp_directory='
    printf '%s' "$input" | grep -q 'ORDER BY app_id, occurred_at'
    printf '%s' "$input" | grep -q 'BEGIN TRANSACTION'
    printf '%s' "$input" | grep -q 'COMMIT'
    rows='{"files":1,"rows":2}'
    ;;
  3)
    printf '%s' "$input" | grep -q 'ducklake_expire_snapshots'
    rows=''
    ;;
  *) exit 1 ;;
esac
printf '%s\n' '{"marker":"__WAKE_RESULT_START__"}'
if [ -n "$rows" ]; then printf '%s\n' "$rows"; fi
printf '%s\n' '{"marker":"__WAKE_RESULT_END__"}'
SH);
    file_put_contents($this->binary, $script);

    try {
        expect((new DuckDb)->rewriteDay('2026-10-01'))->toBeTrue()
            ->and((int) file_get_contents($state))->toBe(3);
    } finally {
        @unlink($state);
    }
});

it('uses the data cutoff for deletion then expires snapshots through maintenance and forces cleanup', function (): void {
    file_put_contents($this->binary, <<<'SH'
#!/bin/sh
set -e
input="$(cat)"
case "$input" in
  *"DELETE FROM lake.logs"*) printf '%s' "$input" | grep -q "event_date < CAST('2026-09-11' AS DATE)" ;;
  *"ducklake_expire_snapshots"*) printf '%s' "$input" | grep -q "older_than => CAST('2026-10-11 15:30:00.000000+00:00' AS TIMESTAMPTZ)" ;;
  *"ducklake_cleanup_old_files"*) printf '%s' "$input" | grep -q "cleanup_all => true" ;;
  *) exit 1 ;;
esac
printf '%s\n' '{"marker":"__WAKE_RESULT_START__"}' '{"deleted_rows":2}' '{"marker":"__WAKE_RESULT_END__"}'
SH);
    $lake = new DuckDb;
    $cutoff = new DateTimeImmutable('2026-09-11 00:00:00 UTC');
    $maintenanceAt = new DateTimeImmutable('2026-10-11 15:30:00 UTC');

    expect($lake->deleteBefore($cutoff))->toBe(2);
    $lake->expireSnapshots($maintenanceAt);
    $lake->cleanupFiles();
});
