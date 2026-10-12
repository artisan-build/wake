<?php

declare(strict_types=1);

use App\Services\DeleteAppLogs;
use ArtisanBuild\WakeServer\Lake\LakeAppDeletion;
use ArtisanBuild\WakeServer\Lake\LakeWriterLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ImmediateDeletionLock implements LakeWriterLock
{
    public int $calls = 0;

    public function execute(Closure $operation): bool
    {
        $this->calls++;
        $operation();

        return true;
    }
}

final class MemoryAppDeletionLake implements LakeAppDeletion
{
    /** @var array<string, int> */
    public array $rows = [];

    public int $expired = 0;

    public int $cleaned = 0;

    public function countAppRows(string $appId): int
    {
        return $this->rows[$appId] ?? 0;
    }

    public function deleteAppRows(string $appId): int
    {
        $deleted = $this->rows[$appId] ?? 0;
        $this->rows[$appId] = 0;

        return $deleted;
    }

    public function expireSnapshots(DateTimeInterface $through): void
    {
        $this->expired++;
    }

    public function cleanupFiles(): void
    {
        $this->cleaned++;
    }
}

function seedDeletionApp(string $app): void
{
    DB::table('wake_connected_apps')->insert([
        'id' => $app, 'enabled' => true, 'accepted_rows' => 0, 'dropped_rows' => 0,
        'rejected_batches' => 0, 'rate_limit_hits' => 0, 'flushed_rows' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function seedDeletionDerived(string $app, string $event, int $count = 3): void
{
    DB::table('wake_event_locators')->insert(['event_id' => $event, 'app_id' => $app, 'event_date' => '2026-10-01', 'occurred_at' => '2026-10-01 00:00:00+00']);
    DB::table('wake_hourly_counts')->insert(['app_id' => $app, 'environment' => 'production', 'level' => 'error', 'hour' => '2026-10-01 00:00:00+00', 'count' => $count]);
    DB::table('wake_error_events')->insert([
        'event_id' => $event, 'app_id' => $app, 'event_date' => '2026-10-01', 'occurred_at' => '2026-10-01 00:00:00+00',
        'level' => 'error', 'channel' => 'app', 'message' => 'failed', 'context' => '{}', 'extra' => '{}',
        'exception' => null, 'environment' => 'production', 'hostname' => 'host', 'correlation_id' => null,
    ]);
}

function seedDeletionStaging(string $app, string $batch, string $day): void
{
    DB::table('wake_staging_batches')->insert([
        'batch_id' => $batch, 'app_id' => $app, 'idempotency_key' => 'key-'.$batch,
        'envelope_version' => 1, 'client_version' => '1.0', 'dropped_count' => 0,
        'payload' => json_encode(['rows' => [['occurred_at' => $day.'T12:00:00Z']]], JSON_THROW_ON_ERROR),
        'row_count' => 1, 'status' => 'flushing', 'created_at' => now(), 'updated_at' => now(), 'claimed_at' => now(),
    ]);
}

beforeEach(function (): void {
    $this->lake = new MemoryAppDeletionLake;
    $this->lock = new ImmediateDeletionLock;
    seedDeletionApp('target');
    seedDeletionApp('other');
});

it('previews exact scoped counts under the writer lock without mutation', function (): void {
    $batch = (string) Str::ulid();
    seedDeletionDerived('target', (string) Str::ulid(), 7);
    seedDeletionStaging('target', $batch, '2026-10-01');
    $this->lake->rows = ['target' => 9, 'other' => 4];

    $preview = (new DeleteAppLogs($this->lake, $this->lock))->preview('target');

    expect($preview)->toBe([
        'lake_rows' => 9, 'locators' => 1, 'hourly_groups' => 1, 'hourly_events' => 7,
        'errors' => 1, 'staging_batches' => 1, 'staging_rows' => 1,
    ])->and($this->lake->rows['target'])->toBe(9)
        ->and(DB::table('wake_staging_batches')->where('app_id', 'target')->count())->toBe(1)
        ->and($this->lake->expired)->toBe(0)
        ->and($this->lake->cleaned)->toBe(0)
        ->and($this->lock->calls)->toBe(1);
});

it('reconciles mixed unfinished manifests and converges after a post-lake crash without cross-app loss', function (): void {
    $targetBatch = (string) Str::ulid();
    $otherBatch = (string) Str::ulid();
    $manifest = (string) Str::ulid();
    seedDeletionStaging('target', $targetBatch, '2026-10-01');
    seedDeletionStaging('other', $otherBatch, '2026-10-02');
    seedDeletionDerived('target', (string) Str::ulid());
    seedDeletionDerived('other', (string) Str::ulid(), 5);
    DB::table('wake_flush_batches')->insert([
        'batch_id' => $manifest, 'staging_batch_ids' => json_encode([$targetBatch, $otherBatch], JSON_THROW_ON_ERROR),
        'event_days' => json_encode(['2026-10-01', '2026-10-02'], JSON_THROW_ON_ERROR), 'state' => 'claimed',
        'claimed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->lake->rows = ['target' => 3, 'other' => 8];
    $crashing = new DeleteAppLogs($this->lake, $this->lock, static function (string $point): void {
        if ($point === 'after_lake_delete') {
            throw new RuntimeException('crash after lake delete');
        }
    });

    expect(fn () => $crashing->run('target'))->toThrow(RuntimeException::class, 'crash after lake delete');
    $coverage = DB::table('wake_flush_batches')->where('batch_id', $manifest)->first();
    expect(json_decode((string) $coverage->staging_batch_ids, true))->toBe([$otherBatch])
        ->and(json_decode((string) $coverage->event_days, true))->toBe(['2026-10-02'])
        ->and(DB::table('wake_staging_batches')->where('batch_id', $targetBatch)->exists())->toBeFalse()
        ->and(DB::table('wake_staging_batches')->where('batch_id', $otherBatch)->exists())->toBeTrue()
        ->and(DB::table('wake_event_locators')->where('app_id', 'target')->count())->toBe(1)
        ->and($this->lake->rows)->toBe(['target' => 0, 'other' => 8]);

    $result = (new DeleteAppLogs($this->lake, $this->lock))->run('target');
    expect($result['lake_rows'])->toBe(0)
        ->and(DB::table('wake_event_locators')->where('app_id', 'target')->count())->toBe(0)
        ->and(DB::table('wake_hourly_counts')->where('app_id', 'target')->count())->toBe(0)
        ->and(DB::table('wake_error_events')->where('app_id', 'target')->count())->toBe(0)
        ->and(DB::table('wake_event_locators')->where('app_id', 'other')->count())->toBe(1)
        ->and((int) DB::table('wake_hourly_counts')->where('app_id', 'other')->sum('count'))->toBe(5)
        ->and($this->lake->rows['other'])->toBe(8)
        ->and($this->lake->expired)->toBe(1)
        ->and($this->lake->cleaned)->toBe(1);
});

it('rolls back the derived boundary on a pre-commit crash and retries repeat-safely', function (): void {
    seedDeletionDerived('target', (string) Str::ulid(), 4);
    $this->lake->rows = ['target' => 2];
    $crashing = new DeleteAppLogs($this->lake, $this->lock, static function (string $point): void {
        if ($point === 'before_postgres_commit') {
            throw new RuntimeException('crash before postgres commit');
        }
    });

    expect(fn () => $crashing->run('target'))->toThrow(RuntimeException::class, 'crash before postgres commit');
    expect($this->lake->rows['target'])->toBe(0)
        ->and(DB::table('wake_event_locators')->where('app_id', 'target')->count())->toBe(1)
        ->and(DB::table('wake_hourly_counts')->where('app_id', 'target')->count())->toBe(1)
        ->and(DB::table('wake_error_events')->where('app_id', 'target')->count())->toBe(1)
        ->and($this->lake->expired)->toBe(0);

    $firstRetry = (new DeleteAppLogs($this->lake, $this->lock))->run('target');
    $secondRetry = (new DeleteAppLogs($this->lake, $this->lock))->run('target');
    expect($firstRetry)->toMatchArray(['lake_rows' => 0, 'locators' => 1, 'hourly_groups' => 1, 'hourly_events' => 4, 'errors' => 1])
        ->and($secondRetry)->toMatchArray(['lake_rows' => 0, 'locators' => 0, 'hourly_groups' => 0, 'hourly_events' => 0, 'errors' => 0])
        ->and(DB::table('wake_event_locators')->where('app_id', 'target')->count())->toBe(0)
        ->and($this->lake->expired)->toBe(2)
        ->and($this->lake->cleaned)->toBe(2);
});
