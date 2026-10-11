<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Models;

use Illuminate\Database\Eloquent\Model;

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
            'last_seen_at' => 'immutable_datetime',
        ];
    }
}
