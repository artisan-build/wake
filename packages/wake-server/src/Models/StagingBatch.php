<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Models;

use ArtisanBuild\WakeContracts\Enums\StagingBatchStatus;
use Illuminate\Database\Eloquent\Model;

final class StagingBatch extends Model
{
    protected $table = 'wake_staging_batches';

    protected $primaryKey = 'batch_id';

    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'envelope_version' => 'integer',
            'dropped_count' => 'integer',
            'payload' => 'array',
            'row_count' => 'integer',
            'status' => StagingBatchStatus::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'flushed_at' => 'immutable_datetime',
        ];
    }
}
