<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeClient\Logging;

use Monolog\Logger;

final readonly class WakeChannelFactory
{
    public function __construct(private WakeHandler $handler) {}

    /** @param array<mixed, mixed> $config */
    public function __invoke(array $config): Logger
    {
        return new Logger('wake', [$this->handler]);
    }
}
