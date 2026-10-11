<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeClient\Logging;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;

final class LifecycleRegistrar
{
    private bool $registered = false;

    public function __construct(
        private readonly Application $app,
        private readonly Dispatcher $events,
    ) {}

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;
        $flush = fn (): null => $this->flush();

        $this->app->terminating($flush);
        foreach ([
            'Illuminate\\Console\\Events\\CommandFinished',
            'Illuminate\\Queue\\Events\\JobProcessed',
            'Illuminate\\Queue\\Events\\JobExceptionOccurred',
            'Laravel\\Octane\\Events\\RequestTerminated',
            'Laravel\\Octane\\Events\\TaskTerminated',
        ] as $event) {
            $this->events->listen($event, $flush);
        }
    }

    private function flush(): null
    {
        $this->app->make(WakeHandler::class)->flush();

        return null;
    }
}
