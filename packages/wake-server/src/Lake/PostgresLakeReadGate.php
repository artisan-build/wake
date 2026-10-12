<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Lake;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PostgresLakeReadGate
{
    public const int KEY_NAMESPACE = 1_463_899_973; // WAKE

    public const int KEY_OPERATION_BASE = 1_380_269_124; // READ

    /** @template TResult
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function execute(Closure $operation): mixed
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Lake reader gating requires PostgreSQL.');
        }

        $slot = $this->waitForSlot($connection);

        try {
            return $operation();
        } finally {
            $connection->selectOne('SELECT pg_advisory_unlock(?, ?)', [self::KEY_NAMESPACE, self::KEY_OPERATION_BASE + $slot]);
        }
    }

    public function slotCount(): int
    {
        $budget = $this->bytes(config('wake-server.lake.read.memory_budget'), 'reader memory budget');
        $limit = $this->bytes(config('wake-server.lake.read.memory_limit'), 'reader memory limit');
        $slots = intdiv($budget, $limit);

        if ($slots < 1 || $slots > 64) {
            throw new RuntimeException('Lake reader settings must provide between 1 and 64 slots.');
        }

        return $slots;
    }

    private function waitForSlot(ConnectionInterface $connection): int
    {
        $waitMilliseconds = $this->positiveInteger('wake-server.lake.read.wait_timeout_milliseconds', 60_000);
        $pollMilliseconds = $this->positiveInteger('wake-server.lake.read.poll_interval_milliseconds', 1_000);
        $deadline = hrtime(true) + ($waitMilliseconds * 1_000_000);

        do {
            foreach (range(0, $this->slotCount() - 1) as $slot) {
                $result = $connection->selectOne('SELECT pg_try_advisory_lock(?, ?) AS acquired', [self::KEY_NAMESPACE, self::KEY_OPERATION_BASE + $slot]);
                $acquired = is_object($result) ? ($result->acquired ?? false) : false;
                if ($acquired === true || $acquired === 1 || $acquired === '1' || $acquired === 't') {
                    return $slot;
                }
            }

            if (hrtime(true) >= $deadline) {
                break;
            }

            usleep(min($pollMilliseconds * 1_000, max(1, intdiv($deadline - hrtime(true), 1_000))));
        } while (hrtime(true) < $deadline);

        throw new LakeBusyException;
    }

    private function bytes(mixed $value, string $name): int
    {
        if (! is_string($value) || preg_match('/^(\d+)(KB|MB|GB)$/D', $value, $matches) !== 1) {
            throw new RuntimeException("The {$name} must use KB, MB, or GB.");
        }

        $bytes = intval($matches[1]) * match ($matches[2]) {
            'KB' => 1_024,
            'MB' => 1_048_576,
            'GB' => 1_073_741_824,
        };
        if ($bytes <= 0) {
            throw new RuntimeException("The {$name} must be positive.");
        }

        return $bytes;
    }

    private function positiveInteger(string $key, int $maximum): int
    {
        $value = config($key);
        if (! is_int($value) || $value < 1 || $value > $maximum) {
            throw new RuntimeException("{$key} must be a positive integer no greater than {$maximum}.");
        }

        return $value;
    }
}
