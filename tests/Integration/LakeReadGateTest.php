<?php

declare(strict_types=1);

use ArtisanBuild\WakeServer\Lake\LakeBusyException;
use ArtisanBuild\WakeServer\Lake\PostgresLakeReadGate;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

it('derives the installation reader count from the configured budget and per-reader limit', function (): void {
    config()->set('wake-server.lake.read.memory_budget', '96MB');
    config()->set('wake-server.lake.read.memory_limit', '96MB');

    expect((new PostgresLakeReadGate)->slotCount())->toBe(1);

    config()->set('wake-server.lake.read.memory_budget', '384MB');
    expect((new PostgresLakeReadGate)->slotCount())->toBe(4);
});

it('waits then returns a retryable busy error when every reader slot is occupied', function (): void {
    config()->set('wake-server.lake.read.memory_budget', '96MB');
    config()->set('wake-server.lake.read.memory_limit', '96MB');
    config()->set('wake-server.lake.read.wait_timeout_milliseconds', 75);
    config()->set('wake-server.lake.read.poll_interval_milliseconds', 10);
    $peers = [];

    try {
        foreach (range(0, 0) as $slot) {
            $peer = independentLakeReaderConnection($slot);
            acquireLakeReaderSlot($peer, $slot);
            $peers[$slot] = $peer;
        }

        $started = hrtime(true);
        $error = null;
        try {
            (new PostgresLakeReadGate)->execute(static fn (): array => []);
        } catch (LakeBusyException $exception) {
            $error = $exception;
        }
        $elapsedMilliseconds = (hrtime(true) - $started) / 1_000_000;

        expect($error)->toBeInstanceOf(LakeBusyException::class)
            ->and($error?->getMessage())->toBe('The log lake is busy. Retry this request shortly.')
            ->and($elapsedMilliseconds)->toBeGreaterThanOrEqual(65.0);
    } finally {
        foreach ($peers as $slot => $peer) {
            releaseLakeReaderSlot($peer, $slot);
            DB::purge('pgsql_lake_reader_'.$slot);
        }
    }
});

it('releases its reader slot when the lake operation fails', function (): void {
    config()->set('wake-server.lake.read.memory_budget', '96MB');
    config()->set('wake-server.lake.read.memory_limit', '96MB');

    expect(fn () => (new PostgresLakeReadGate)->execute(static function (): never {
        throw new RuntimeException('injected reader failure');
    }))->toThrow(RuntimeException::class, 'injected reader failure');

    $peer = independentLakeReaderConnection(0);
    try {
        acquireLakeReaderSlot($peer, 0);
        expect(true)->toBeTrue();
    } finally {
        releaseLakeReaderSlot($peer, 0);
        DB::purge('pgsql_lake_reader_0');
    }
});

function independentLakeReaderConnection(int $slot): ConnectionInterface
{
    $name = 'pgsql_lake_reader_'.$slot;
    config()->set('database.connections.'.$name, config('database.connections.pgsql'));
    DB::purge($name);

    return DB::connection($name);
}

function acquireLakeReaderSlot(ConnectionInterface $connection, int $slot): void
{
    $result = $connection->selectOne('SELECT pg_try_advisory_lock(?, ?) AS acquired', [PostgresLakeReadGate::KEY_NAMESPACE, PostgresLakeReadGate::KEY_OPERATION_BASE + $slot]);
    if (! is_object($result) || $result->acquired !== true) {
        throw new RuntimeException("Could not acquire fixture reader slot {$slot}.");
    }
}

function releaseLakeReaderSlot(ConnectionInterface $connection, int $slot): void
{
    $connection->selectOne('SELECT pg_advisory_unlock(?, ?)', [PostgresLakeReadGate::KEY_NAMESPACE, PostgresLakeReadGate::KEY_OPERATION_BASE + $slot]);
}
