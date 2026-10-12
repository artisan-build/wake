<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeClient\Tests;

use ArtisanBuild\WakeClient\Logging\WakeHandler;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Logger as IlluminateLogger;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Orchestra\Testbench\Attributes\DefineEnvironment;

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
