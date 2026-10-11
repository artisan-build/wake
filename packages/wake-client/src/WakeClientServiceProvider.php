<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeClient;

use ArtisanBuild\WakeClient\Contracts\PayloadFilter;
use ArtisanBuild\WakeClient\Filters\PassThroughPayloadFilter;
use ArtisanBuild\WakeClient\Logging\RecordNormalizer;
use ArtisanBuild\WakeClient\Logging\WakeChannelFactory;
use ArtisanBuild\WakeClient\Logging\WakeHandler;
use ArtisanBuild\WakeContracts\IngestProtocol;
use Illuminate\Http\Client\Factory;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;

final class WakeClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/wake.php', 'wake');

        $channels = $this->app['config']->get('logging.channels', []);
        if (! array_key_exists('wake', $channels)) {
            $this->app['config']->set('logging.channels.wake', ['driver' => 'wake']);
        }

        $this->app->singleton(PayloadFilter::class, PassThroughPayloadFilter::class);
        $this->app->singleton(Logging\LifecycleRegistrar::class);
        $this->app->singleton(WakeChannelFactory::class);
        $this->app->singleton(RecordNormalizer::class, fn ($app): RecordNormalizer => new RecordNormalizer(
            correlations: $app->make(Logging\CorrelationResolver::class),
            environment: (string) $app['config']->get('app.env', 'production'),
            hostname: gethostname() ?: 'unknown',
        ));
        $this->app->singleton(WakeHandler::class, fn ($app): WakeHandler => new WakeHandler(
            normalizer: $app->make(RecordNormalizer::class),
            filter: $app->make(PayloadFilter::class),
            http: $app->make(Factory::class),
            url: $app['config']->get('wake.url'),
            token: $app['config']->get('wake.token'),
            batchSize: max(1, min(IngestProtocol::MAX_ROWS, (int) $app['config']->get('wake.batch_size', 100))),
            maxBuffer: max(1, min(IngestProtocol::MAX_ROWS, (int) $app['config']->get('wake.max_buffer', IngestProtocol::MAX_ROWS))),
            connectTimeout: max(0.01, (float) $app['config']->get('wake.connect_timeout', 0.5)),
            timeout: max(0.01, (float) $app['config']->get('wake.timeout', 2.0)),
        ));

        $this->app->afterResolving('log', static function (LogManager $manager): void {
            $manager->extend('wake', static fn ($app, array $config) => $app->make(WakeChannelFactory::class)($config));
        });
    }

    public function boot(): void
    {
        $this->app->make(Logging\LifecycleRegistrar::class)->register();
    }
}
