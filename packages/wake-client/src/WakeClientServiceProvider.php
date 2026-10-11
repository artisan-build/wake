<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeClient;

use ArtisanBuild\WakeClient\Console\WakeDoctorCommand;
use ArtisanBuild\WakeClient\Contracts\PayloadFilter;
use ArtisanBuild\WakeClient\Contracts\Transport;
use ArtisanBuild\WakeClient\Filters\PassThroughPayloadFilter;
use ArtisanBuild\WakeClient\Logging\RecordNormalizer;
use ArtisanBuild\WakeClient\Logging\WakeChannelFactory;
use ArtisanBuild\WakeClient\Logging\WakeHandler;
use ArtisanBuild\WakeClient\Transport\HttpTransport;
use ArtisanBuild\WakeContracts\IngestProtocol;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Monolog\Logger;

final class WakeClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/wake.php', 'wake');

        $config = self::resolve($this->app, Repository::class);
        $channels = $config->get('logging.channels', []);
        $channels = is_array($channels) ? $channels : [];
        if (! array_key_exists('wake', $channels)) {
            $config->set('logging.channels.wake', ['driver' => 'wake']);
        }

        $this->app->singleton(PayloadFilter::class, PassThroughPayloadFilter::class);
        $this->app->singleton(Logging\CorrelationState::class);
        $this->app->singleton(Transport::class, fn (Container $app): Transport => new HttpTransport(
            http: self::resolve($app, Factory::class),
            url: self::stringConfig($app, 'wake.url'),
            token: self::stringConfig($app, 'wake.token'),
            connectTimeout: max(0.01, self::floatConfig($app, 'wake.connect_timeout', 0.5)),
            timeout: max(0.01, self::floatConfig($app, 'wake.timeout', 2.0)),
        ));
        $this->app->singleton(Logging\LifecycleRegistrar::class);
        $this->app->singleton(WakeChannelFactory::class);
        $this->app->singleton(RecordNormalizer::class, fn (Container $app): RecordNormalizer => new RecordNormalizer(
            correlations: self::resolve($app, Logging\CorrelationResolver::class),
            environment: self::stringConfig($app, 'app.env', 'production'),
            hostname: gethostname() ?: 'unknown',
        ));
        $this->app->singleton(WakeHandler::class, fn (Container $app): WakeHandler => new WakeHandler(
            normalizer: self::resolve($app, RecordNormalizer::class),
            filter: self::resolve($app, PayloadFilter::class),
            transport: self::resolve($app, Transport::class),
            url: self::nullableStringConfig($app, 'wake.url'),
            token: self::nullableStringConfig($app, 'wake.token'),
            batchSize: max(1, min(IngestProtocol::MAX_ROWS, self::intConfig($app, 'wake.batch_size', 100))),
            maxBuffer: max(1, min(IngestProtocol::MAX_ROWS, self::intConfig($app, 'wake.max_buffer', IngestProtocol::MAX_ROWS))),
        ));

        $this->app->afterResolving('log', static function (LogManager $manager): void {
            $factory = self::resolve(app(), WakeChannelFactory::class);
            $manager->extend('wake', function ($app, array $config) use ($factory): Logger {
                return $factory($config);
            });
        });
    }

    public function boot(): void
    {
        self::resolve($this->app, Logging\LifecycleRegistrar::class)->register();
        if ($this->app->runningInConsole()) {
            $this->commands([WakeDoctorCommand::class]);
        }
    }

    /**
     * Resolve and verify a typed container entry.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private static function resolve(Container $app, string $class): object
    {
        $resolved = $app->make($class);
        if (! $resolved instanceof $class) {
            throw new LogicException("Container did not resolve {$class}.");
        }

        return $resolved;
    }

    private static function config(Container $app): Repository
    {
        return self::resolve($app, Repository::class);
    }

    private static function stringConfig(Container $app, string $key, string $default = ''): string
    {
        return self::nullableStringConfig($app, $key) ?? $default;
    }

    private static function nullableStringConfig(Container $app, string $key): ?string
    {
        $value = self::config($app)->get($key);

        return is_string($value) ? $value : null;
    }

    private static function intConfig(Container $app, string $key, int $default): int
    {
        $value = self::config($app)->get($key);

        return is_int($value) ? $value : $default;
    }

    private static function floatConfig(Container $app, string $key, float $default): float
    {
        $value = self::config($app)->get($key);

        return is_float($value) || is_int($value) ? (float) $value : $default;
    }
}
