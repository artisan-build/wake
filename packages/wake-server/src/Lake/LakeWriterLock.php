<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Lake;

use Closure;

interface LakeWriterLock
{
    /** Execute the operation only when the shared lake-writer lock is acquired. */
    public function execute(Closure $operation): bool;
}
