<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Lake;

use DateTimeInterface;
use RuntimeException;
use SensitiveParameter;
use Symfony\Component\Process\Process;

final class DuckDb implements LakeMaintenance, LakeWriter
{
    public const int MAX_INSERT_ROWS = 100_000;

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
        $extensions = $this->literal($this->configString('wake-server.lake.extension_directory'));
        $process->setInput("SET extension_directory={$extensions}; LOAD ducklake; LOAD postgres; LOAD httpfs; SELECT version() AS duckdb_version;");
        $process->run();

        return [
            'binary_exists' => is_executable($this->configString('wake-server.lake.binary')),
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

    /** @param list<string> $eventDays */
    public function batchCommitted(string $batchId, array $eventDays): bool
    {
        if ($eventDays === []) {
            return false;
        }

        $days = implode(', ', array_map(fn (string $day): string => 'CAST('.$this->literal($day).' AS DATE)', $eventDays));
        $rows = $this->query('SELECT 1 AS committed FROM lake.logs WHERE event_date IN ('.$days.') AND batch_id = '.$this->literal($batchId).' LIMIT 1;');

        return $rows !== [];
    }

    /** @param list<array<string, mixed>> $rows */
    public function commitBatch(string $batchId, array $rows): void
    {
        if (count($rows) > self::MAX_INSERT_ROWS) {
            throw new RuntimeException('A DuckLake insertion chunk cannot exceed 100000 events.');
        }

        $values = array_map(fn (array $row): string => $this->rowSql($batchId, $row), $rows);
        $insert = $values === [] ? '' : 'INSERT INTO lake.logs VALUES '.implode(",\n", $values).';';

        $this->query("BEGIN TRANSACTION;\n{$insert}\nCOMMIT;", 120);
    }

    public function compact(): array
    {
        $before = $this->query("SELECT count(*) AS files FROM ducklake_list_files('lake', 'logs');", 300);
        $after = $this->query("CALL ducklake_merge_adjacent_files('lake', 'logs', max_compacted_files => 3); SELECT count(*) AS files FROM ducklake_list_files('lake', 'logs');", 300);
        $lastAfter = $after === [] ? [] : $after[count($after) - 1];

        return ['files_before' => $this->integer($before[0]['files'] ?? 0), 'files_after' => $this->integer($lastAfter['files'] ?? 0)];
    }

    public function deleteBefore(DateTimeInterface $cutoff): int
    {
        $date = $this->literal($cutoff->format('Y-m-d'));
        $result = $this->query("SELECT count(*) AS deleted_rows FROM lake.logs WHERE event_date < CAST({$date} AS DATE); DELETE FROM lake.logs WHERE event_date < CAST({$date} AS DATE);", 300);

        return $this->integer($result[0]['deleted_rows'] ?? 0);
    }

    public function expireSnapshots(DateTimeInterface $through): void
    {
        $timestamp = $this->literal($through->format('Y-m-d H:i:sP'));
        $this->query("CALL ducklake_expire_snapshots('lake', older_than => CAST({$timestamp} AS TIMESTAMPTZ));", 300);
    }

    public function cleanupFiles(): void
    {
        $this->query("CALL ducklake_cleanup_old_files('lake', cleanup_all => true);", 300);
    }

    public function literal(?string $value): string
    {
        return $value === null ? 'NULL' : "'".str_replace("'", "''", $value)."'";
    }

    /** @param array<string, mixed> $row */
    private function rowSql(string $batchId, array $row): string
    {
        $json = static fn (mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $occurredAt = $this->rowString($row, 'occurred_at');
        $exception = $row['exception'] ?? null;

        return sprintf(
            '(%s,%s,%s,CAST(%s AS TIMESTAMPTZ)::DATE,CAST(%s AS TIMESTAMPTZ),%s,%s,%s,CAST(%s AS JSON),CAST(%s AS JSON),%s,%s,%s,%s,now())',
            $this->literal($batchId), $this->literal($this->rowString($row, 'event_id')), $this->literal($this->rowString($row, 'app_id')),
            $this->literal($occurredAt), $this->literal($occurredAt), $this->literal($this->rowString($row, 'level')),
            $this->literal($this->rowString($row, 'channel')), $this->literal($this->rowString($row, 'message')),
            $this->literal($json($row['context'])), $this->literal($json($row['extra'])),
            $exception === null ? 'NULL' : 'CAST('.$this->literal($json($exception)).' AS JSON)',
            $this->literal($this->rowString($row, 'environment')), $this->literal($this->rowString($row, 'hostname')),
            $this->literal($this->rowNullableString($row, 'correlation_id')),
        );
    }

    private function preamble(): string
    {
        $database = config('database.connections.pgsql');
        $disk = config('filesystems.disks.s3');
        $settings = "SET TimeZone='UTC'; SET threads=1; SET memory_limit=".$this->literal('384MB').'; SET preserve_insertion_order=false; SET autoinstall_known_extensions=false; SET autoload_known_extensions=false;';
        $extensions = 'SET extension_directory='.$this->literal($this->configString('wake-server.lake.extension_directory')).'; LOAD ducklake; LOAD postgres; LOAD httpfs;';
        $metadataSchema = $this->metadataSchemaOption();

        if (config('wake-server.lake.postgres_catalog') === true) {
            if (! is_array($database) || empty($database['host'])) {
                throw new RuntimeException('The PostgreSQL DuckLake catalog requires a configured database.');
            }
            $dsn = $this->postgresDsn($database, $this->scalarString($database['sslmode'] ?? 'prefer', 'database sslmode'));

            return "{$settings} {$extensions} ATTACH ".$this->literal('ducklake:postgres:'.$dsn).' AS lake (DATA_PATH '.$this->literal($this->configString('wake-server.lake.data_path')).", DATA_INLINING_ROW_LIMIT 0{$metadataSchema});";
        }

        if (! config('wake-server.lake.cloud') || ! is_array($database) || empty($database['host']) || ! is_array($disk) || empty($disk['bucket'])) {
            $catalog = 'ducklake:'.storage_path('app/ducklake/catalog.ducklake');
            $data = storage_path('app/ducklake/data/');

            return "{$settings} {$extensions} ATTACH ".$this->literal($catalog).' AS lake (DATA_PATH '.$this->literal($data).", DATA_INLINING_ROW_LIMIT 0{$metadataSchema});";
        }

        $endpoint = $this->scalarString($disk['endpoint'] ?? '', 'S3 endpoint');
        $endpointHost = parse_url($endpoint, PHP_URL_HOST) ?: preg_replace('#^https?://#', '', $endpoint);
        $secret = sprintf(
            'CREATE OR REPLACE TEMPORARY SECRET wake_s3 (TYPE s3, KEY_ID %s, SECRET %s, REGION %s, ENDPOINT %s, URL_STYLE %s, USE_SSL %s);',
            $this->literal($this->scalarString($disk['key'] ?? '', 'S3 key')),
            $this->literal($this->scalarString($disk['secret'] ?? '', 'S3 secret')),
            $this->literal($this->scalarString($disk['region'] ?? 'auto', 'S3 region')),
            $this->literal((string) $endpointHost),
            $this->literal(! empty($disk['use_path_style_endpoint']) ? 'path' : 'vhost'),
            str_starts_with($endpoint, 'https://') ? 'true' : 'false',
        );
        $dsn = $this->postgresDsn($database);
        $data = 's3://'.$this->scalarString($disk['bucket'], 'S3 bucket').'/'.ltrim($this->configString('wake-server.lake.data_prefix'), '/');

        return "{$settings} {$extensions} {$secret} ATTACH ".$this->literal('ducklake:postgres:'.$dsn).' AS lake (DATA_PATH '.$this->literal($data).", DATA_INLINING_ROW_LIMIT 0{$metadataSchema});";
    }

    /** @param array<mixed> $database */
    private function postgresDsn(array $database, string $sslmode = 'require'): string
    {
        return sprintf(
            'host=%s port=%s dbname=%s user=%s password=%s sslmode=%s',
            $this->scalarString($database['host'], 'database host'),
            $this->scalarString($database['port'] ?? 5432, 'database port'),
            $this->scalarString($database['database'] ?? '', 'database name'),
            $this->scalarString($database['username'] ?? '', 'database username'),
            $this->scalarString($database['password'] ?? '', 'database password'),
            $sslmode,
        );
    }

    private function metadataSchemaOption(): string
    {
        $schema = config('wake-server.lake.metadata_schema');
        if ($schema === null || $schema === '') {
            return '';
        }
        if (! is_string($schema) || preg_match('/^[a-z][a-z0-9_]*$/D', $schema) !== 1) {
            throw new RuntimeException('The DuckLake metadata schema is invalid.');
        }

        return ', METADATA_SCHEMA '.$this->literal($schema);
    }

    private function process(string $format): Process
    {
        return new Process([$this->configString('wake-server.lake.binary'), '-unsigned', $format]);
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
                $row = [];
                foreach ($decoded as $key => $value) {
                    if (! is_string($key)) {
                        throw new RuntimeException('DuckDB returned a row with a non-string key.');
                    }
                    $row[$key] = $value;
                }
                $rows[] = $row;
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

    private function configString(string $key): string
    {
        return $this->scalarString(config($key), $key);
    }

    private function scalarString(mixed $value, string $name): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new RuntimeException("{$name} must be a string or integer.");
        }

        return strval($value);
    }

    /** @param array<string, mixed> $row */
    private function rowString(array $row, string $key): string
    {
        return $this->scalarString($row[$key] ?? null, "event {$key}");
    }

    /** @param array<string, mixed> $row */
    private function rowNullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return $value === null ? null : $this->scalarString($value, "event {$key}");
    }

    private function integer(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? intval($value) : 0;
    }
}
