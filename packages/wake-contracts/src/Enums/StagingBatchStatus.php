<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeContracts\Enums;

enum StagingBatchStatus: string
{
    case Pending = 'pending';
    case Flushing = 'flushing';
    case Flushed = 'flushed';
    case Failed = 'failed';
}
