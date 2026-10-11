<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeClient\Logging;

final class CorrelationState
{
    private ?string $queueJobUuid = null;

    public function startQueueJob(?string $uuid): void
    {
        $this->queueJobUuid = $uuid !== '' ? $uuid : null;
    }

    public function finishQueueJob(): void
    {
        $this->queueJobUuid = null;
    }

    public function queueJobUuid(): ?string
    {
        return $this->queueJobUuid;
    }
}
