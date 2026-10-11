<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeClient\Contracts;

use ArtisanBuild\WakeContracts\LogEvent;

interface PayloadFilter
{
    public function filter(LogEvent $event): ?LogEvent;
}
