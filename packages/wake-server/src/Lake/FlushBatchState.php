<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Lake;

enum FlushBatchState: string
{
    case Claimed = 'claimed';
    case LakeCommitted = 'lake_committed';
    case Done = 'done';
}
