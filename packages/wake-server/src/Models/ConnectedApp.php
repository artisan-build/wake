<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property bool $enabled
 * @property int|null $batches_per_minute
 * @property int|null $events_per_minute
 * @property int $accepted_rows
 * @property int $dropped_rows
 * @property int $rejected_batches
 * @property int $rate_limit_hits
 * @property int $flushed_rows
 * @property CarbonInterface|null $last_seen_at
 * @property CarbonInterface|null $last_flushed_at
 * @property string|null $last_client_version
 */
final class ConnectedApp extends Model
{
    protected $table = 'wake_connected_apps';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'batches_per_minute' => 'integer',
            'events_per_minute' => 'integer',
            'accepted_rows' => 'integer',
            'dropped_rows' => 'integer',
            'rejected_batches' => 'integer',
            'rate_limit_hits' => 'integer',
            'flushed_rows' => 'integer',
            'last_seen_at' => 'immutable_datetime',
            'last_flushed_at' => 'immutable_datetime',
        ];
    }
}
