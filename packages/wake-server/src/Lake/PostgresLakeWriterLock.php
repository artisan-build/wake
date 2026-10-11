<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Lake;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PostgresLakeWriterLock implements LakeWriterLock
{
    public const int KEY_NAMESPACE = 1_463_899_973; // WAKE

    public const int KEY_OPERATION = 1_279_348_549; // LAKE

    public function execute(Closure $operation): bool
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Lake writer locking requires PostgreSQL.');
        }

        if (! $this->acquire($connection)) {
            return false;
        }

        try {
            $operation();
        } finally {
            $connection->selectOne('SELECT pg_advisory_unlock(?, ?)', [self::KEY_NAMESPACE, self::KEY_OPERATION]);
        }

        return true;
    }

    private function acquire(ConnectionInterface $connection): bool
    {
        $result = $connection->selectOne('SELECT pg_try_advisory_lock(?, ?) AS acquired', [self::KEY_NAMESPACE, self::KEY_OPERATION]);
        $acquired = is_object($result) ? ($result->acquired ?? false) : false;

        return $acquired === true || $acquired === 1 || $acquired === '1' || $acquired === 't';
    }
}
