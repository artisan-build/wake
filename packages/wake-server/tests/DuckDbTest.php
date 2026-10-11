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

it('sends cloud credentials on stdin and redacts failures', function (): void {
    file_put_contents($this->binary, <<<'SH'
#!/bin/sh
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

it('defines the full v1 lake schema and physical layout', function (): void {
    file_put_contents($this->binary, <<<'SH'
#!/bin/sh
input="$(cat)"
printf '%s' "$input" | grep -q 'batch_id VARCHAR NOT NULL'
printf '%s' "$input" | grep -q 'exception JSON'
printf '%s' "$input" | grep -q 'PARTITIONED BY (event_date)'
printf '%s' "$input" | grep -q 'SORTED BY (app_id ASC, occurred_at ASC)'
printf '%s' "$input" | grep -q "target_file_size', '32MB'"
printf '%s\n' '{"marker":"__WAKE_RESULT_START__"}' '{"marker":"__WAKE_RESULT_END__"}'
SH);

    (new DuckDb)->bootstrap();
})->throwsNoExceptions();
