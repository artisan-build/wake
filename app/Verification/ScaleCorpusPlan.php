<?php

declare(strict_types=1);

namespace App\Verification;

use ArtisanBuild\WakeServer\Lake\DuckDb;
use InvalidArgumentException;

final readonly class ScaleCorpusPlan
{
    private const string ULID_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const int DEFAULT_ROWS = 50_000_000;

    public const int DEFAULT_APPS = 40;

    public const int DEFAULT_DAYS = 30;

    public const int DEFAULT_CHUNK = 100_000;

    public const string START_UTC = '2026-09-12 00:00:00+00';

    public string $eventIdPrefix;

    public string $batchIdPrefix;

    public function __construct(
        public string $runKey,
        public int $rows,
        public int $apps,
        public int $days,
        public int $chunk,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,23}$/D', $runKey) !== 1) {
            throw new InvalidArgumentException('Run key must be 1-24 lowercase letters, digits, or hyphens.');
        }
        foreach (['rows' => $rows, 'apps' => $apps, 'days' => $days, 'chunk' => $chunk] as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException("{$name} must be a positive integer.");
            }
        }
        if ($chunk > DuckDb::MAX_INSERT_ROWS) {
            throw new InvalidArgumentException('chunk cannot exceed 100000 rows.');
        }
        foreach (['rows' => [$rows, self::DEFAULT_ROWS], 'apps' => [$apps, self::DEFAULT_APPS], 'days' => [$days, self::DEFAULT_DAYS]] as $name => [$value, $maximum]) {
            if ($value > $maximum) {
                throw new InvalidArgumentException("{$name} cannot exceed {$maximum}.");
            }
        }

        $this->eventIdPrefix = self::ulidPrefix($runKey, 'event');
        $this->batchIdPrefix = self::ulidPrefix($runKey, 'batch');
    }

    /** @param array{run: mixed, rows: mixed, apps: mixed, days: mixed, chunk: mixed} $options */
    public static function fromOptions(array $options): self
    {
        return new self(
            self::string($options['run'], 'run'),
            self::integer($options['rows'], 'rows'),
            self::integer($options['apps'], 'apps'),
            self::integer($options['days'], 'days'),
            self::integer($options['chunk'], 'chunk'),
        );
    }

    public function chunks(): int
    {
        return (int) ceil($this->rows / $this->chunk);
    }

    public function batchId(int $offset): string
    {
        return $this->batchIdPrefix.self::ulidOrdinal($offset);
    }

    public function eventId(int $ordinal): string
    {
        return $this->eventIdPrefix.self::ulidOrdinal($ordinal);
    }

    public function occurredAt(int $ordinal): string
    {
        $seconds = intdiv($ordinal * $this->days * 86_400, $this->rows);

        return gmdate('Y-m-d\TH:i:s.000000\Z', strtotime(self::START_UTC) + $seconds);
    }

    /** @return list<string> */
    public function eventDays(int $offset, int $count): array
    {
        $first = strtotime(substr($this->occurredAt($offset), 0, 10).' 00:00:00 UTC');
        $last = strtotime(substr($this->occurredAt($offset + $count - 1), 0, 10).' 00:00:00 UTC');
        $days = [];
        for ($day = $first; $day <= $last; $day += 86_400) {
            $days[] = gmdate('Y-m-d', $day);
        }

        return $days;
    }

    public function clientVersion(): string
    {
        return 'wake-verification/'.$this->runKey;
    }

    private static function ulidPrefix(string $runKey, string $purpose): string
    {
        $hash = hash('sha256', "{$purpose}:{$runKey}");
        $prefix = self::ULID_ALPHABET[hexdec($hash[0]) % 8];
        for ($index = 1; $index < 19; $index++) {
            $prefix .= self::ULID_ALPHABET[hexdec($hash[$index])];
        }

        return $prefix;
    }

    private static function ulidOrdinal(int $ordinal): string
    {
        if ($ordinal < 0 || $ordinal >= 34_359_738_368) {
            throw new InvalidArgumentException('Identifier ordinal is outside the supported range.');
        }
        $suffix = '';
        for ($position = 0; $position < 7; $position++) {
            $suffix = self::ULID_ALPHABET[$ordinal % 32].$suffix;
            $ordinal = intdiv($ordinal, 32);
        }

        return $suffix;
    }

    private static function integer(mixed $value, string $name): int
    {
        if (! is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new InvalidArgumentException("{$name} must be a positive integer.");
        }

        return (int) $value;
    }

    private static function string(mixed $value, string $name): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("{$name} must be a string.");
        }

        return $value;
    }
}
