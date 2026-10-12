<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeClient\Tests;

use ArtisanBuild\WakeClient\Logging\DefaultChannelAttacher;
use ArtisanBuild\WakeClient\Logging\RecordIdentityRegistry;
use ArtisanBuild\WakeClient\Logging\WakeHandler;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Psr\Log\AbstractLogger;

final class AutoAttachTest extends TestCase
{
    #[DefineEnvironment('defineConfiguredStack')]
    public function test_it_attaches_to_a_stack_without_replacing_its_existing_handler(): void
    {
        $this->fakeWake();
        $logger = $this->activeMonolog();
        $before = $logger->getHandlers();
        $stderr = $this->handler($before, TestHandler::class);

        self::assertCount(2, $before);
        self::assertCount(1, $this->handlers($before, WakeHandler::class));

        $this->app->make(DefaultChannelAttacher::class)->attach();
        self::assertSame($before, $logger->getHandlers());

        $logger->info('stack record');
        $this->app->terminate();

        self::assertCount(1, $stderr->getRecords());
        self::assertSame(['stack record'], $this->wakeMessages());
        self::assertCount(1, Http::recorded());
        self::assertCount(2, $logger->getHandlers());
        self::assertTrue(in_array($stderr, $logger->getHandlers(), true));
    }

    #[DefineEnvironment('defineConfiguredSingleChannel')]
    public function test_it_attaches_to_a_non_stack_channel_without_replacing_its_existing_handler(): void
    {
        $this->fakeWake();
        $logger = $this->activeMonolog();
        $before = $logger->getHandlers();
        $host = $this->handler($before, TestHandler::class);

        self::assertCount(2, $before);
        self::assertCount(1, $this->handlers($before, WakeHandler::class));

        $logger->info('single record');
        $this->app->terminate();

        self::assertCount(1, $host->getRecords());
        self::assertSame(['single record'], $this->wakeMessages());
        self::assertCount(1, Http::recorded());
        self::assertCount(2, $logger->getHandlers());
        self::assertTrue(in_array($host, $logger->getHandlers(), true));
    }

    #[DefineEnvironment('defineStackContainingWake')]
    public function test_it_does_not_double_ship_from_an_existing_wake_stack_or_the_explicit_channel(): void
    {
        $this->fakeWake();
        $logger = $this->activeMonolog();
        $host = $this->handler($logger->getHandlers(), TestHandler::class);

        self::assertCount(1, $this->handlers($logger->getHandlers(), WakeHandler::class));

        $logger->info('default record');
        $this->app->make(LogManager::class)->channel('wake')->info('explicit record');
        $this->app->terminate();

        self::assertCount(1, $host->getRecords());
        self::assertSame(['default record', 'explicit record'], $this->wakeMessages());
        self::assertCount(1, Http::recorded());
    }

    #[DefineEnvironment('defineWrappedWakeStack')]
    public function test_it_delivers_once_when_the_existing_wake_handler_is_wrapped(): void
    {
        $this->fakeWake();
        $host = $this->channelHandler('stderr');

        $this->app->make(LogManager::class)->info('wrapped record');
        $this->app->terminate();

        self::assertCount(1, $host->getRecords());
        self::assertSame(['wrapped record'], $this->wakeMessages());
        self::assertCount(1, Http::recorded());
    }

    #[DefineEnvironment('defineNonBubblingWakeStack')]
    public function test_it_delivers_once_before_a_non_bubbling_host_handler(): void
    {
        $this->fakeWake();
        $host = $this->channelHandler('host');
        self::assertFalse($host->getBubble());

        $this->app->make(LogManager::class)->info('non-bubbling record');
        $this->app->terminate();

        self::assertCount(1, $host->getRecords());
        self::assertSame(['non-bubbling record'], $this->wakeMessages());
        self::assertCount(1, Http::recorded());
    }

    #[DefineEnvironment('defineWhitespaceWrappedWakeStack')]
    public function test_it_delivers_once_without_rewriting_an_array_stack_member_with_whitespace(): void
    {
        $this->fakeWake();
        $host = $this->channelHandler('stderr');

        self::assertSame(['stderr', ' wake'], config('logging.channels.stack.channels'));
        $this->app->make(LogManager::class)->info('whitespace record');
        $this->app->terminate();

        self::assertSame(['stderr', ' wake'], config('logging.channels.stack.channels'));
        self::assertCount(1, $host->getRecords());
        self::assertSame(['whitespace record'], $this->wakeMessages());
        self::assertCount(1, Http::recorded());
    }

    #[DefineEnvironment('defineNestedWakeStacks')]
    public function test_it_preserves_nested_stack_configuration_and_explicit_inner_logging(): void
    {
        $this->fakeWake();
        $host = $this->channelHandler('host');

        self::assertSame(['inner'], config('logging.channels.outer.channels'));
        self::assertSame(['host', 'wake'], config('logging.channels.inner.channels'));

        $this->app->make(LogManager::class)->info('outer record');
        $this->app->make(LogManager::class)->channel('inner')->info('inner record');
        $this->app->terminate();

        self::assertSame(['inner'], config('logging.channels.outer.channels'));
        self::assertSame(['host', 'wake'], config('logging.channels.inner.channels'));
        self::assertCount(2, $host->getRecords());
        self::assertSame(['outer record', 'inner record'], $this->wakeMessages());
        self::assertCount(1, Http::recorded());
    }

    #[DefineEnvironment('definePreResolvedDefault')]
    public function test_it_preserves_a_resolved_default_and_handler_added_by_another_provider(): void
    {
        $this->fakeWake();
        $state = $this->app->make(PreBootLoggerState::class);
        $active = $this->activeMonolog();
        $host = $this->channelHandler('stderr');

        self::assertSame($state->logger, $active);
        self::assertTrue(in_array($state->handler, $active->getHandlers(), true));

        $this->app->make(LogManager::class)->info('pre-resolved record');
        $this->app->terminate();

        self::assertSame($state->logger, $this->activeMonolog());
        self::assertTrue(in_array($state->handler, $active->getHandlers(), true));
        self::assertCount(1, $state->handler->getRecords());
        self::assertCount(1, $host->getRecords());
        self::assertSame(['pre-resolved record'], $this->wakeMessages());
        self::assertCount(1, Http::recorded());
    }

    #[DefineEnvironment('defineWrappedWakeStack')]
    public function test_cloned_handler_records_share_one_identity_while_identical_logs_remain_distinct(): void
    {
        $this->fakeWake();
        $top = new TestHandler;
        $active = $this->activeMonolog();
        $active->pushHandler($top);
        $this->app->make(DefaultChannelAttacher::class)->attach();
        $nested = $this->channelHandler('stderr');

        $this->app->make(LogManager::class)->info('identical record');
        $this->app->make(LogManager::class)->info('identical record');
        $this->app->terminate();

        $topRecords = $top->getRecords();
        $nestedRecords = $nested->getRecords();
        self::assertCount(2, $topRecords);
        self::assertCount(2, $nestedRecords);
        self::assertNotSame($topRecords[0], $nestedRecords[0]);
        self::assertSame($topRecords[0]->datetime, $nestedRecords[0]->datetime);

        $identities = $this->app->make(RecordIdentityRegistry::class);
        self::assertSame($identities->identity($topRecords[0]), $identities->identity($nestedRecords[0]));
        self::assertNotSame($identities->identity($topRecords[0]), $identities->identity($topRecords[1]));
        self::assertSame(['identical record', 'identical record'], $this->wakeMessages());
        self::assertCount(1, Http::recorded());
    }

    #[DefineEnvironment('defineNonMonologCustomChannel')]
    public function test_doctor_reports_an_incompatible_custom_default_without_probing_ingest(): void
    {
        Http::fake(['*' => Http::response([
            'batch_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAW',
            'accepted_count' => 1,
            'duplicate' => false,
        ], 202, ['Content-Type' => 'application/json'])]);
        $host = $this->app->make(RecordingPsrLogger::class);
        $active = $this->app->make(LogManager::class)->driver();
        self::assertInstanceOf(IlluminateLogger::class, $active);
        self::assertSame($host, $active->getLogger());

        $this->app->make(LogManager::class)->info('custom host record');
        $this->artisan('wake:doctor')
            ->assertFailed()
            ->expectsOutputToContain('not attached: default channel is not Monolog-backed')
            ->expectsOutputToContain('built-in Laravel log driver')
            ->expectsOutputToContain('driver=monolog');

        self::assertSame(['custom host record'], $host->messages());
        self::assertSame($host, $this->app->make(LogManager::class)->driver()->getLogger());
        Http::assertNothingSent();
    }

    #[DefineEnvironment('defineOptedOutNonMonologCustomChannel')]
    public function test_doctor_keeps_its_explicit_probe_for_an_opted_out_custom_default(): void
    {
        $this->fakeWake();

        $this->artisan('wake:doctor')->assertSuccessful();

        self::assertCount(1, Http::recorded());
    }

    #[DefineEnvironment('defineAutoAttachDisabled')]
    public function test_it_leaves_the_host_untouched_when_auto_attach_is_disabled(): void
    {
        $this->assertInertConfiguration();
    }

    #[DefineEnvironment('defineMissingUrl')]
    public function test_it_leaves_the_host_untouched_when_the_url_is_missing(): void
    {
        $this->assertInertConfiguration();
    }

    #[DefineEnvironment('defineMissingToken')]
    public function test_it_leaves_the_host_untouched_when_the_token_is_missing(): void
    {
        $this->assertInertConfiguration();
    }

    protected function defineConfiguredStack(Application $app): void
    {
        $this->configure($app, 'stack', ['stderr'], true, 'https://wake.test', 'synthetic-token');
    }

    protected function defineConfiguredSingleChannel(Application $app): void
    {
        $this->configure($app, 'host', [], true, 'https://wake.test', 'synthetic-token');
    }

    protected function defineStackContainingWake(Application $app): void
    {
        $this->configure($app, 'stack', ['stderr', 'wake'], true, 'https://wake.test', 'synthetic-token');
    }

    protected function defineWrappedWakeStack(Application $app): void
    {
        $this->configure($app, 'stack', ['stderr', 'wake'], true, 'https://wake.test', 'synthetic-token');
        $app->make(Repository::class)->set('logging.channels.stack.ignore_exceptions', true);
    }

    protected function defineNonBubblingWakeStack(Application $app): void
    {
        $this->configure($app, 'stack', ['host', 'wake'], true, 'https://wake.test', 'synthetic-token');
        $app->make(Repository::class)->set('logging.channels.host.handler_with', ['bubble' => false]);
    }

    protected function defineWhitespaceWrappedWakeStack(Application $app): void
    {
        $this->configure($app, 'stack', ['stderr', ' wake'], true, 'https://wake.test', 'synthetic-token');
        $app->make(Repository::class)->set('logging.channels.stack.ignore_exceptions', true);
    }

    protected function defineNestedWakeStacks(Application $app): void
    {
        $this->configure($app, 'outer', [], true, 'https://wake.test', 'synthetic-token');
        $app->make(Repository::class)->set([
            'logging.channels.outer' => ['driver' => 'stack', 'channels' => ['inner']],
            'logging.channels.inner' => [
                'driver' => 'stack',
                'channels' => ['host', 'wake'],
                'ignore_exceptions' => true,
            ],
        ]);
    }

    protected function definePreResolvedDefault(Application $app): void
    {
        $this->configure($app, 'stack', ['stderr'], true, 'https://wake.test', 'synthetic-token');
        $app->instance(PreBootLoggerState::class, new PreBootLoggerState);
        $app->register(ResolveDefaultBeforeBootedProvider::class);
    }

    protected function defineNonMonologCustomChannel(Application $app): void
    {
        $this->configureCustomChannel($app, true);
    }

    protected function defineOptedOutNonMonologCustomChannel(Application $app): void
    {
        $this->configureCustomChannel($app, false);
    }

    protected function defineAutoAttachDisabled(Application $app): void
    {
        $this->configure($app, 'host', [], false, 'https://wake.test', 'synthetic-token');
    }

    protected function defineMissingUrl(Application $app): void
    {
        $this->configure($app, 'host', [], true, null, 'synthetic-token');
    }

    protected function defineMissingToken(Application $app): void
    {
        $this->configure($app, 'host', [], true, 'https://wake.test', null);
    }

    /** @param list<string> $stack */
    private function configure(
        Application $app,
        string $default,
        array $stack,
        bool $autoAttach,
        ?string $url,
        ?string $token,
    ): void {
        $config = $app->make(Repository::class);
        $config->set([
            'logging.default' => $default,
            'logging.channels.host' => ['driver' => 'monolog', 'handler' => TestHandler::class],
            'logging.channels.stderr' => ['driver' => 'monolog', 'handler' => TestHandler::class],
            'logging.channels.stack' => ['driver' => 'stack', 'channels' => $stack],
            'wake.auto_attach' => $autoAttach,
            'wake.url' => $url,
            'wake.token' => $token,
        ]);
    }

    private function assertInertConfiguration(): void
    {
        $this->fakeWake();
        $logger = $this->activeMonolog();
        $before = $logger->getHandlers();
        $host = $this->handler($before, TestHandler::class);

        self::assertCount(1, $before);
        self::assertCount(0, $this->handlers($before, WakeHandler::class));

        $logger->info('host only');
        $this->app->terminate();

        $after = $logger->getHandlers();
        self::assertCount(1, $host->getRecords());
        self::assertCount(count($before), $after);
        self::assertSame($before, $after);
        self::assertSame([], $this->wakeMessages());
        self::assertCount(0, Http::recorded());
    }

    private function configureCustomChannel(Application $app, bool $autoAttach): void
    {
        $host = new RecordingPsrLogger;
        $app->instance(RecordingPsrLogger::class, $host);
        $this->configure($app, 'custom', [], $autoAttach, 'https://wake.test', 'synthetic-token');
        $app->make(Repository::class)->set('logging.channels.custom', [
            'driver' => 'custom',
            'via' => static fn (array $config): RecordingPsrLogger => $host,
        ]);
    }

    private function fakeWake(): void
    {
        Http::fake(static function (Request $request) {
            $rows = $request->data()['rows'] ?? [];

            return Http::response([
                'batch_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAW',
                'accepted_count' => is_array($rows) ? count($rows) : 0,
                'duplicate' => false,
            ], 202, ['Content-Type' => 'application/json']);
        });
    }

    private function activeMonolog(): Logger
    {
        $logger = $this->app->make(LogManager::class)->driver();
        self::assertInstanceOf(IlluminateLogger::class, $logger);
        $monolog = $logger->getLogger();
        self::assertInstanceOf(Logger::class, $monolog);

        return $monolog;
    }

    private function channelHandler(string $channel): TestHandler
    {
        $logger = $this->app->make(LogManager::class)->channel($channel);
        self::assertInstanceOf(IlluminateLogger::class, $logger);
        $monolog = $logger->getLogger();
        self::assertInstanceOf(Logger::class, $monolog);

        return $this->handler($monolog->getHandlers(), TestHandler::class);
    }

    /**
     * @template T of object
     *
     * @param  list<object>  $handlers
     * @param  class-string<T>  $class
     * @return T
     */
    private function handler(array $handlers, string $class): object
    {
        $matches = $this->handlers($handlers, $class);
        self::assertCount(1, $matches);

        return $matches[0];
    }

    /**
     * @template T of object
     *
     * @param  list<object>  $handlers
     * @param  class-string<T>  $class
     * @return list<T>
     */
    private function handlers(array $handlers, string $class): array
    {
        return array_values(array_filter($handlers, static fn (object $handler): bool => $handler instanceof $class));
    }

    /** @return list<string> */
    private function wakeMessages(): array
    {
        $messages = [];
        foreach (Http::recorded() as [$request]) {
            $rows = $request->data()['rows'] ?? [];
            if (! is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (is_array($row) && is_string($row['message'] ?? null)) {
                    $messages[] = $row['message'];
                }
            }
        }

        return $messages;
    }
}

final class RecordingPsrLogger extends AbstractLogger
{
    /** @var list<string> */
    private array $messages = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }

    /** @return list<string> */
    public function messages(): array
    {
        return $this->messages;
    }
}

final class PreBootLoggerState
{
    public ?Logger $logger = null;

    public readonly TestHandler $handler;

    public function __construct()
    {
        $this->handler = new TestHandler;
    }
}

final class ResolveDefaultBeforeBootedProvider extends ServiceProvider
{
    public function boot(): void
    {
        $active = $this->app->make(LogManager::class)->driver();
        if (! $active instanceof IlluminateLogger || ! $active->getLogger() instanceof Logger) {
            throw new \LogicException('Test default logger is not Monolog-backed.');
        }

        $state = $this->app->make(PreBootLoggerState::class);
        $state->logger = $active->getLogger();
        $state->logger->pushHandler($state->handler);
    }
}
