<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeClient\Filters;

use ArtisanBuild\WakeClient\Contracts\PayloadFilter;
use ArtisanBuild\WakeContracts\LogEvent;

final class PassThroughPayloadFilter implements PayloadFilter
{
    public function filter(LogEvent $event): LogEvent
    {
        return $event;
    }
}
