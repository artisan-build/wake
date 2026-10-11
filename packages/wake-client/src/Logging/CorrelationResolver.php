<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeClient\Logging;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Context;

final readonly class CorrelationResolver
{
    private const array KEYS = ['correlation_id', 'request_id', 'trace_id'];

    public function __construct(private Container $container) {}

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $extra
     */
    public function resolve(array $context, array $extra): ?string
    {
        foreach (self::KEYS as $key) {
            $value = $context[$key] ?? $extra[$key] ?? Context::get($key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        if (! $this->container->bound('request')) {
            return null;
        }

        $request = $this->container->make('request');
        if (! is_object($request) || ! method_exists($request, 'header')) {
            return null;
        }

        foreach (['X-Correlation-ID', 'X-Request-ID'] as $header) {
            $value = $request->header($header);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
