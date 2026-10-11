<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeClient\Logging;

use Illuminate\Contracts\Container\Container;
use Monolog\Logger;

final readonly class WakeChannelFactory
{
    public function __construct(private Container $container) {}

    /** @param array<mixed, mixed> $config */
    public function __invoke(array $config): Logger
    {
        $handler = $this->container->make(WakeHandler::class);

        return new Logger('wake', [$handler]);
    }
}
