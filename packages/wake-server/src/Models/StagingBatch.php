<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Models;

use ArtisanBuild\WakeContracts\Enums\StagingBatchStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $batch_id
 * @property string $app_id
 * @property string $idempotency_key
 * @property int $envelope_version
 * @property string $client_version
 * @property int $dropped_count
 * @property array<string, mixed> $payload
 * @property int $row_count
 * @property StagingBatchStatus $status
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property CarbonInterface|null $claimed_at
 * @property CarbonInterface|null $flushed_at
 */
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
