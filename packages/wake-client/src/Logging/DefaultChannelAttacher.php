<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeClient\Logging;

use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Log\LogManager;
use Monolog\Handler\HandlerInterface;
use Monolog\Logger;

final class DefaultChannelAttacher
{
    private ?bool $monologBacked = null;

    public function __construct(
        private readonly LogManager $logs,
        private readonly WakeHandler $wake,
        private readonly RecordIdentityRegistry $identities,
    ) {}

    public function attach(): void
    {
        $monolog = $this->defaultMonolog();
        $this->monologBacked = $monolog !== null;
        if ($monolog === null) {
            return;
        }

        $this->appendIdentityProcessor($monolog);

        $handlers = $monolog->getHandlers();
        if (($handlers[0] ?? null) === $this->wake) {
            return;
        }

        $handlers = array_values(array_filter(
            $handlers,
            fn (HandlerInterface $handler): bool => $handler !== $this->wake,
        ));
        $monolog->setHandlers($handlers);
        $monolog->pushHandler($this->wake);
    }

    public function isMonologBacked(): bool
    {
        return $this->monologBacked ??= $this->defaultMonolog() !== null;
    }

    private function appendIdentityProcessor(Logger $monolog): void
    {
        $processors = $monolog->getProcessors();
        if (in_array($this->identities, $processors, true)) {
            return;
        }

        foreach ($processors as $_) {
            $monolog->popProcessor();
        }
        $monolog->pushProcessor($this->identities);
        foreach (array_reverse($processors) as $processor) {
            $monolog->pushProcessor($processor);
        }
    }

    private function defaultMonolog(): ?Logger
    {
        $logger = $this->logs->driver();
        if (! $logger instanceof IlluminateLogger) {
            return null;
        }

        $inner = $logger->getLogger();

        return $inner instanceof Logger ? $inner : null;
    }
}
