<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeClient\Logging;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Log\LogManager;
use Monolog\Handler\HandlerInterface;
use Monolog\Logger;

final class DefaultChannelAttacher
{
    private ?bool $monologBacked = null;

    public function __construct(
        private readonly Repository $config,
        private readonly LogManager $logs,
        private readonly WakeHandler $wake,
    ) {}

    public function attach(): void
    {
        $default = $this->logs->getDefaultDriver();
        if (is_string($default)) {
            $this->removeWakeFromStack($default);
        }

        $monolog = $this->defaultMonolog();
        $this->monologBacked = $monolog !== null;
        if ($monolog === null) {
            return;
        }

        $handlers = array_values(array_filter(
            $monolog->getHandlers(),
            static fn (HandlerInterface $handler): bool => ! $handler instanceof WakeHandler,
        ));
        $monolog->setHandlers($handlers);
        $monolog->pushHandler($this->wake);
    }

    public function isMonologBacked(): bool
    {
        return $this->monologBacked ??= $this->defaultMonolog() !== null;
    }

    /** @param array<string, true> $seen */
    private function removeWakeFromStack(string $channel, array $seen = []): bool
    {
        if (isset($seen[$channel])) {
            return false;
        }

        $seen[$channel] = true;
        $key = "logging.channels.{$channel}";
        $channelConfig = $this->config->get($key);
        if (! is_array($channelConfig) || ($channelConfig['driver'] ?? null) !== 'stack') {
            return false;
        }

        $channels = $channelConfig['channels'] ?? [];
        if (is_string($channels)) {
            $channels = array_map('trim', explode(',', $channels));
        }
        if (! is_array($channels)) {
            return false;
        }

        $changed = false;
        $filtered = [];
        foreach ($channels as $stackedChannel) {
            if ($stackedChannel === 'wake') {
                $changed = true;

                continue;
            }

            if (is_string($stackedChannel) && $this->removeWakeFromStack($stackedChannel, $seen)) {
                $changed = true;
            }
            $filtered[] = $stackedChannel;
        }

        if ($changed) {
            $channelConfig['channels'] = $filtered;
            $this->config->set($key, $channelConfig);
            $this->logs->forgetChannel($channel);
        }

        return $changed;
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
