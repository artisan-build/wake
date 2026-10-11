<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeClient;

use ArtisanBuild\WakeClient\Contracts\PayloadFilter;
use ArtisanBuild\WakeClient\Filters\PassThroughPayloadFilter;
use ArtisanBuild\WakeClient\Logging\RecordNormalizer;
use ArtisanBuild\WakeClient\Logging\WakeChannelFactory;
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
        $this->app->singleton(WakeChannelFactory::class);
        $this->app->singleton(RecordNormalizer::class, fn ($app): RecordNormalizer => new RecordNormalizer(
            correlations: $app->make(Logging\CorrelationResolver::class),
            environment: (string) $app['config']->get('app.env', 'production'),
            hostname: gethostname() ?: 'unknown',
        ));

        $this->app->afterResolving('log', static function (LogManager $manager): void {
            $manager->extend('wake', static fn ($app, array $config) => $app->make(WakeChannelFactory::class)($config));
        });
    }
}
