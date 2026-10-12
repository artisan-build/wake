<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeServer\Lake;

use RuntimeException;

final class LakeBusyException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The log lake is busy. Retry this request shortly.');
    }
}
