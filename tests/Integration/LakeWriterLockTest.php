<?php

declare(strict_types=1);

use ArtisanBuild\WakeServer\Lake\PostgresLakeWriterLock;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

it('skips the whole operation while another PostgreSQL session holds the lake lock', function (): void {
    $peer = independentLakeLockConnection();
    acquireLakeLock($peer);
    $called = false;

    try {
        $acquired = (new PostgresLakeWriterLock)->execute(function () use (&$called): void {
            $called = true;
        });
    } finally {
        releaseLakeLock($peer);
        DB::purge('pgsql_lake_lock_peer');
    }

    expect($acquired)->toBeFalse()->and($called)->toBeFalse();
});

it('always releases the PostgreSQL lake lock after success and failure', function (): void {
    $lock = new PostgresLakeWriterLock;
    expect($lock->execute(static function (): void {}))->toBeTrue();
    assertLakeLockAvailableFromPeer();

    expect(fn () => $lock->execute(static function (): void {
        throw new RuntimeException('injected');
    }))->toThrow(RuntimeException::class, 'injected');
    assertLakeLockAvailableFromPeer();
});

function independentLakeLockConnection(): ConnectionInterface
{
    config()->set('database.connections.pgsql_lake_lock_peer', config('database.connections.pgsql'));
    DB::purge('pgsql_lake_lock_peer');

    return DB::connection('pgsql_lake_lock_peer');
}

function acquireLakeLock(ConnectionInterface $connection): void
{
    $result = $connection->selectOne('SELECT pg_try_advisory_lock(?, ?) AS acquired', [PostgresLakeWriterLock::KEY_NAMESPACE, PostgresLakeWriterLock::KEY_OPERATION]);
    if (! is_object($result) || $result->acquired !== true) {
        throw new RuntimeException('Could not acquire fixture lake lock.');
    }
}

function releaseLakeLock(ConnectionInterface $connection): void
{
    $connection->selectOne('SELECT pg_advisory_unlock(?, ?)', [PostgresLakeWriterLock::KEY_NAMESPACE, PostgresLakeWriterLock::KEY_OPERATION]);
}

function assertLakeLockAvailableFromPeer(): void
{
    $peer = independentLakeLockConnection();
    try {
        acquireLakeLock($peer);
    } finally {
        releaseLakeLock($peer);
        DB::purge('pgsql_lake_lock_peer');
    }
}
