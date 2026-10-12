<?php

declare(strict_types=1);

namespace App\Services;

use ArtisanBuild\WakeServer\Lake\LakeReader;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final readonly class WakeQueries
{
    public const int MAX_ROWS = 500;

    public function __construct(private LakeReader $lake) {}

    /** @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>}
     */
    public function search(array $filters): array
    {
        [$app, $allApps] = $this->scope($filters);
        [$from, $to] = $this->window($filters, 7);
        $text = $this->optionalString($filters, 'text', 500);
        $regex = $this->optionalString($filters, 'regex', 500);
        if (($text === null) === ($regex === null)) {
            throw new InvalidArgumentException('Search requires exactly one of text or regex.');
        }

        $where = $this->lakeWhere($from, $to, $app, $allApps, $filters);
        $where[] = $text !== null
            ? 'contains(lower(message), lower('.$this->lake->literal($text).'))'
            : 'regexp_matches(message, '.$this->lake->literal($regex).", 'i')";

        return ['rows' => $this->lake->query($this->lakeSelect($where, $this->limit($filters)))];
    }

    /** @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function tail(array $filters): array
    {
        [$app, $allApps] = $this->scope($filters);
        [$from, $to] = $this->window($filters, 30);
        $where = $this->lakeWhere($from, $to, $app, $allApps, $filters);
        $cursor = $filters['cursor'] ?? null;
        if ($cursor !== null) {
            [$occurredAt, $eventId] = $this->decodeCursor($cursor);
            $time = $this->lake->literal($occurredAt);
            $where[] = '(occurred_at < CAST('.$time.' AS TIMESTAMPTZ) OR (occurred_at = CAST('.$time.' AS TIMESTAMPTZ) AND event_id < '.$this->lake->literal($eventId).'))';
        }

        $rows = $this->lake->query($this->lakeSelect($where, $this->limit($filters)));
        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return ['rows' => $rows, 'next_cursor' => is_array($last) ? $this->encodeCursor($last) : null];
    }

    /** @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>}
     */
    public function aggregate(array $filters): array
    {
        [$app] = $this->scope($filters);
        [$from, $to] = $this->window($filters, 90);
        $groups = $filters['group_by'] ?? ['hour'];
        if (! is_array($groups) || array_is_list($groups) === false || $groups === [] || array_diff($groups, ['hour', 'app', 'environment', 'level']) !== []) {
            throw new InvalidArgumentException('Invalid aggregate grouping.');
        }
        $groups = array_values(array_unique($groups));
        $columns = array_map(static fn (string $group): string => match ($group) {
            'app' => 'app_id', default => $group,
        }, $groups);
        $query = DB::table('wake_hourly_counts')->whereBetween('hour', [$from, $to]);
        $this->postgresFilters($query, $app, $filters);
        $query->select([...$columns, DB::raw('sum(count) AS count')])->groupBy($columns);
        in_array('hour', $columns, true) ? $query->orderByDesc('hour') : $query->orderBy($columns[0]);
        $rows = $query->limit(self::MAX_ROWS)->get();

        return ['rows' => $this->rows($rows->all())];
    }

    /** @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>}
     */
    public function recentErrors(array $filters): array
    {
        [$app] = $this->scope($filters);
        [$from, $to] = $this->window($filters, 365);
        $query = DB::table('wake_error_events')->whereBetween('occurred_at', [$from, $to]);
        $this->postgresFilters($query, $app, $filters);
        $rows = $query->latest('occurred_at')->orderByDesc('event_id')->limit($this->limit($filters))->get();

        return ['rows' => $this->rows($rows->all())];
    }

    /** @return array{rows: list<array<string, mixed>>} */
    public function context(string $app, string $eventId, int $before = 5, int $after = 5): array
    {
        $app = $this->requiredString($app, 'app', 255);
        $eventId = $this->requiredString($eventId, 'event_id', 255);
        if ($before < 0 || $before > 50 || $after < 0 || $after > 50) {
            throw new InvalidArgumentException('Context bounds must be between 0 and 50.');
        }
        $locator = DB::table('wake_event_locators')->where('event_id', $eventId)->where('app_id', $app)->first();
        if ($locator === null) {
            return ['rows' => []];
        }
        $day = $this->lake->literal((string) $locator->event_date);
        $at = $this->lake->literal(CarbonImmutable::parse((string) $locator->occurred_at)->utc()->toIso8601String());
        $appLiteral = $this->lake->literal($app);
        $event = $this->lake->literal($eventId);
        $columns = 'event_id, app_id, occurred_at, level, channel, message, context, extra, exception, environment, hostname, correlation_id';
        $sql = "SELECT * FROM ((SELECT {$columns} FROM lake.logs WHERE event_date = CAST({$day} AS DATE) AND app_id = {$appLiteral} AND (occurred_at < CAST({$at} AS TIMESTAMPTZ) OR (occurred_at = CAST({$at} AS TIMESTAMPTZ) AND event_id <= {$event})) ORDER BY occurred_at DESC, event_id DESC LIMIT ".($before + 1).") UNION ALL (SELECT {$columns} FROM lake.logs WHERE event_date = CAST({$day} AS DATE) AND app_id = {$appLiteral} AND (occurred_at > CAST({$at} AS TIMESTAMPTZ) OR (occurred_at = CAST({$at} AS TIMESTAMPTZ) AND event_id > {$event})) ORDER BY occurred_at ASC, event_id ASC LIMIT {$after})) context_rows ORDER BY occurred_at ASC, event_id ASC;";

        return ['rows' => $this->lake->query($sql)];
    }

    /** @param array<string, mixed> $scope
     * @return array{rows: list<array<string, mixed>>}
     */
    public function listApps(array $scope): array
    {
        [$app] = $this->scope($scope);
        $query = DB::table('wake_connected_apps')->when($app !== null, fn (Builder $query) => $query->where('id', $app))->orderBy('id')->limit(self::MAX_ROWS);
        $rows = $this->rows($query->get()->all());
        foreach ($rows as &$row) {
            $row['environments'] = DB::table('wake_hourly_counts')->where('app_id', $row['id'])->distinct()->orderBy('environment')->pluck('environment')->all();
        }

        return ['rows' => $rows];
    }

    /** @param array<string, mixed> $scope
     * @return array{rows: list<array<string, mixed>>}
     */
    public function ingestHealth(array $scope): array
    {
        [$app] = $this->scope($scope);
        $rows = DB::table('wake_connected_apps')->when($app !== null, fn (Builder $query) => $query->where('id', $app))->orderBy('id')->limit(self::MAX_ROWS)->get();

        return ['rows' => array_map(function (object $row): array {
            $data = (array) $row;
            $data['pending_batches'] = DB::table('wake_staging_batches')->where('app_id', $data['id'])->whereIn('status', ['pending', 'flushing'])->count();
            $data['lag_seconds'] = $data['last_seen_at'] === null || $data['last_flushed_at'] === null
                ? null
                : max(0, CarbonImmutable::parse((string) $data['last_flushed_at'])->diffInSeconds(CarbonImmutable::parse((string) $data['last_seen_at']), false));

            return $data;
        }, $rows->all())];
    }

    /** @param array<string, mixed> $filters
     * @return array{?string, bool}
     */
    private function scope(array $filters): array
    {
        $app = isset($filters['app']) ? $this->requiredString($filters['app'], 'app', 255) : null;
        $allApps = ($filters['all_apps'] ?? false) === true;
        if (($app === null) === ! $allApps) {
            throw new InvalidArgumentException('Specify exactly one of app or all_apps=true.');
        }

        return [$app, $allApps];
    }

    /** @param array<string, mixed> $filters
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function window(array $filters, int $maxDays): array
    {
        try {
            $from = CarbonImmutable::parse($this->requiredString($filters['from'] ?? null, 'from', 64))->utc();
            $to = CarbonImmutable::parse($this->requiredString($filters['to'] ?? null, 'to', 64))->utc();
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('The time window is invalid.', previous: $exception);
        }
        if ($to->lessThan($from) || $from->diffInSeconds($to) > $maxDays * 86400) {
            throw new InvalidArgumentException("The time window must be ordered and no longer than {$maxDays} days.");
        }

        return [$from, $to];
    }

    /** @param array<string, mixed> $filters
     * @return list<string>
     */
    private function lakeWhere(CarbonImmutable $from, CarbonImmutable $to, ?string $app, bool $allApps, array $filters): array
    {
        $fromLiteral = $this->lake->literal($from->toIso8601String());
        $toLiteral = $this->lake->literal($to->toIso8601String());
        $where = ["event_date BETWEEN CAST({$fromLiteral} AS TIMESTAMPTZ)::DATE AND CAST({$toLiteral} AS TIMESTAMPTZ)::DATE", "occurred_at >= CAST({$fromLiteral} AS TIMESTAMPTZ)", "occurred_at <= CAST({$toLiteral} AS TIMESTAMPTZ)"];
        if (! $allApps && $app !== null) {
            $where[] = 'app_id = '.$this->lake->literal($app);
        }
        foreach (['environment', 'level'] as $field) {
            $value = $this->optionalString($filters, $field, $field === 'level' ? 20 : 255);
            if ($value !== null) {
                $where[] = $field.' = '.$this->lake->literal($field === 'level' ? strtolower($value) : $value);
            }
        }

        return $where;
    }

    /** @param list<string> $where */
    private function lakeSelect(array $where, int $limit): string
    {
        return 'SELECT event_id, app_id, occurred_at, level, channel, message, context, extra, exception, environment, hostname, correlation_id FROM lake.logs WHERE '.implode(' AND ', $where)." ORDER BY occurred_at DESC, event_id DESC LIMIT {$limit};";
    }

    /** @param array<string, mixed> $filters */
    private function limit(array $filters): int
    {
        $limit = $filters['limit'] ?? 100;
        if (! is_int($limit) || $limit < 1 || $limit > self::MAX_ROWS) {
            throw new InvalidArgumentException('The row limit must be between 1 and 500.');
        }

        return $limit;
    }

    /** @param array<string, mixed> $filters */
    private function postgresFilters(Builder $query, ?string $app, array $filters): void
    {
        if ($app !== null) {
            $query->where('app_id', $app);
        }
        foreach (['environment', 'level'] as $field) {
            $value = $this->optionalString($filters, $field, $field === 'level' ? 20 : 255);
            if ($value !== null) {
                $query->where($field, $field === 'level' ? strtolower($value) : $value);
            }
        }
    }

    /** @param array<string, mixed> $values */
    private function optionalString(array $values, string $key, int $max): ?string
    {
        return array_key_exists($key, $values) && $values[$key] !== null ? $this->requiredString($values[$key], $key, $max) : null;
    }

    private function requiredString(mixed $value, string $field, int $max): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $max) {
            throw new InvalidArgumentException("{$field} must be a non-blank string of at most {$max} characters.");
        }

        return $value;
    }

    /** @return array{string, string} */
    private function decodeCursor(mixed $cursor): array
    {
        if (! is_string($cursor) || $cursor === '') {
            throw new InvalidArgumentException('The cursor is invalid.');
        }
        $padding = str_repeat('=', (4 - strlen($cursor) % 4) % 4);
        $decoded = base64_decode(strtr($cursor, '-_', '+/').$padding, true);
        $data = is_string($decoded) ? json_decode($decoded, true) : null;
        if (! is_array($data) || array_keys($data) !== ['occurred_at', 'event_id']) {
            throw new InvalidArgumentException('The cursor is invalid.');
        }

        return [$this->requiredString($data['occurred_at'], 'cursor occurred_at', 64), $this->requiredString($data['event_id'], 'cursor event_id', 255)];
    }

    /** @param array<string, mixed> $row */
    private function encodeCursor(array $row): string
    {
        $json = json_encode(['occurred_at' => $this->requiredString($row['occurred_at'] ?? null, 'occurred_at', 64), 'event_id' => $this->requiredString($row['event_id'] ?? null, 'event_id', 255)], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /** @param list<object> $rows
     * @return list<array<string, mixed>>
     */
    private function rows(array $rows): array
    {
        return array_map(static function (object $row): array {
            $values = (array) $row;
            if (isset($values['event_id']) && is_string($values['event_id'])) {
                $values['event_id'] = rtrim($values['event_id']);
            }

            return $values;
        }, $rows);
    }
}
