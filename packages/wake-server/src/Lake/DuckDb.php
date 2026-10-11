<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Lake;

use RuntimeException;
use SensitiveParameter;
use Symfony\Component\Process\Process;

final class DuckDb
{
    /** @return list<array<string, mixed>> */
    public function query(#[SensitiveParameter] string $sql, int $timeout = 20): array
    {
        $output = $this->run($this->preamble()."\nSELECT '__WAKE_RESULT_START__' AS marker;\n{$sql}\nSELECT '__WAKE_RESULT_END__' AS marker;", $timeout);

        return $this->decodeFramedOutput(trim($output));
    }

    /** @return array{binary_exists: bool, exit_code: int|null, extensions_loaded: bool, result: mixed} */
    public function runtimeProbe(): array
    {
        $process = $this->process('-json');
        $process->setTimeout(10);
        $extensions = $this->literal((string) config('wake-server.lake.extension_directory'));
        $process->setInput("SET extension_directory={$extensions}; LOAD ducklake; LOAD postgres; LOAD httpfs; SELECT version() AS duckdb_version;");
        $process->run();

        return [
            'binary_exists' => is_executable((string) config('wake-server.lake.binary')),
            'exit_code' => $process->getExitCode(),
            'extensions_loaded' => $process->isSuccessful(),
            'result' => $process->isSuccessful() ? json_decode(trim($process->getOutput()), true) : null,
        ];
    }

    public function bootstrap(): void
    {
        $this->query(<<<'SQL'
            CREATE TABLE IF NOT EXISTS lake.logs (
                batch_id VARCHAR NOT NULL,
                event_id VARCHAR NOT NULL,
                app_id VARCHAR NOT NULL,
                event_date DATE NOT NULL,
                occurred_at TIMESTAMPTZ NOT NULL,
                level VARCHAR NOT NULL,
                channel VARCHAR NOT NULL,
                message VARCHAR NOT NULL,
                context JSON NOT NULL,
                extra JSON NOT NULL,
                exception JSON,
                environment VARCHAR NOT NULL,
                hostname VARCHAR NOT NULL,
                correlation_id VARCHAR,
                ingested_at TIMESTAMPTZ NOT NULL
            );
            ALTER TABLE lake.logs SET PARTITIONED BY (event_date);
            ALTER TABLE lake.logs SET SORTED BY (app_id ASC, occurred_at ASC);
            CALL lake.set_option('target_file_size', '32MB', table_name => 'logs');
            SQL, 60);
    }

    public function literal(?string $value): string
    {
        return $value === null ? 'NULL' : "'".str_replace("'", "''", $value)."'";
    }

    private function preamble(): string
    {
        $database = config('database.connections.pgsql');
        $disk = config('filesystems.disks.s3');
        $settings = 'SET threads=1; SET memory_limit='.$this->literal('384MB').'; SET preserve_insertion_order=false; SET autoinstall_known_extensions=false; SET autoload_known_extensions=false;';
        $extensions = 'SET extension_directory='.$this->literal((string) config('wake-server.lake.extension_directory')).'; LOAD ducklake; LOAD postgres; LOAD httpfs;';

        if (! config('wake-server.lake.cloud') || ! is_array($database) || empty($database['host']) || ! is_array($disk) || empty($disk['bucket'])) {
            $catalog = 'ducklake:'.storage_path('app/ducklake/catalog.ducklake');
            $data = storage_path('app/ducklake/data/');

            return "{$settings} {$extensions} ATTACH ".$this->literal($catalog).' AS lake (DATA_PATH '.$this->literal($data).', DATA_INLINING_ROW_LIMIT 0);';
        }

        $endpoint = (string) ($disk['endpoint'] ?? '');
        $endpointHost = parse_url($endpoint, PHP_URL_HOST) ?: preg_replace('#^https?://#', '', $endpoint);
        $secret = sprintf(
            'CREATE OR REPLACE TEMPORARY SECRET wake_s3 (TYPE s3, KEY_ID %s, SECRET %s, REGION %s, ENDPOINT %s, URL_STYLE %s, USE_SSL %s);',
            $this->literal((string) ($disk['key'] ?? '')),
            $this->literal((string) ($disk['secret'] ?? '')),
            $this->literal((string) ($disk['region'] ?? 'auto')),
            $this->literal((string) $endpointHost),
            $this->literal(! empty($disk['use_path_style_endpoint']) ? 'path' : 'vhost'),
            str_starts_with($endpoint, 'https://') ? 'true' : 'false',
        );
        $dsn = sprintf(
            'host=%s port=%s dbname=%s user=%s password=%s sslmode=require',
            $database['host'],
            $database['port'] ?? 5432,
            $database['database'],
            $database['username'],
            $database['password'],
        );
        $data = 's3://'.$disk['bucket'].'/'.ltrim((string) config('wake-server.lake.data_prefix'), '/');

        return "{$settings} {$extensions} {$secret} ATTACH ".$this->literal('ducklake:postgres:'.$dsn).' AS lake (DATA_PATH '.$this->literal($data).', DATA_INLINING_ROW_LIMIT 0);';
    }

    private function process(string $format): Process
    {
        return new Process([(string) config('wake-server.lake.binary'), '-unsigned', $format]);
    }

    private function run(#[SensitiveParameter] string $input, int $timeout): string
    {
        $process = $this->process('-jsonlines');
        $process->setTimeout($timeout);
        $process->setInput($input);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('DuckDB command failed with exit code '.$process->getExitCode().': '.$this->sanitizedError($process->getErrorOutput()));
        }

        return $process->getOutput();
    }

    /** @return list<array<string, mixed>> */
    private function decodeFramedOutput(string $output): array
    {
        $collecting = false;
        $rows = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                continue;
            }
            if (($decoded['marker'] ?? null) === '__WAKE_RESULT_START__') {
                $collecting = true;

                continue;
            }
            if (($decoded['marker'] ?? null) === '__WAKE_RESULT_END__') {
                return $rows;
            }
            if ($collecting && array_keys($decoded) !== ['Success']) {
                $rows[] = $decoded;
            }
        }

        throw new RuntimeException('DuckDB returned an unreadable response.');
    }

    private function sanitizedError(string $error): string
    {
        $secrets = [
            config('database.connections.pgsql.password'),
            config('filesystems.disks.s3.key'),
            config('filesystems.disks.s3.secret'),
        ];
        foreach ($secrets as $secret) {
            if (is_string($secret) && $secret !== '') {
                $error = str_replace($secret, '[REDACTED]', $error);
            }
        }
        $error = preg_replace('/password=[^\s\'";]+/i', 'password=[REDACTED]', $error) ?? $error;
        $error = preg_replace('/\b(KEY_ID|SECRET)\s+\'[^\']*\'/i', '$1 \'[REDACTED]\'', $error) ?? $error;

        return mb_substr(trim($error), 0, 1200);
    }
}
