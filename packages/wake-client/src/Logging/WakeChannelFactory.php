<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeClient\Logging;

use Monolog\Handler\NullHandler;
use Monolog\Logger;

final class WakeChannelFactory
{
    /** @param array<string, mixed> $config */
    public function __invoke(array $config): Logger
    {
        return new Logger('wake', [new NullHandler]);
    }
}
