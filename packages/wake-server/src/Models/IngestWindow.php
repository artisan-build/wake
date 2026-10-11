<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Models;

use Illuminate\Database\Eloquent\Model;

final class IngestWindow extends Model
{
    protected $table = 'wake_ingest_windows';

    protected $guarded = [];

    public $incrementing = false;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'window_start' => 'immutable_datetime',
            'batch_count' => 'integer',
            'event_count' => 'integer',
        ];
    }
}
