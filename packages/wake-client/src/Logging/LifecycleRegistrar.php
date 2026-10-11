<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeClient\Logging;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

final class LifecycleRegistrar
{
    private bool $registered = false;

    public function __construct(
        private readonly Application $app,
        private readonly Dispatcher $events,
        private readonly CorrelationState $correlations,
    ) {}

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;
        $flush = fn (): null => $this->flush();

        $this->app->terminating($flush);
        $this->events->listen(JobProcessing::class, function (JobProcessing $event): void {
            $uuid = $event->job->uuid();
            $this->correlations->startQueueJob(is_string($uuid) ? $uuid : null);
        });
        $finishJob = function (): void {
            $this->correlations->finishQueueJob();
            $this->flush();
        };
        $this->events->listen(JobProcessed::class, $finishJob);
        $this->events->listen(JobExceptionOccurred::class, $finishJob);
        foreach ([
            'Illuminate\\Console\\Events\\CommandFinished',
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
