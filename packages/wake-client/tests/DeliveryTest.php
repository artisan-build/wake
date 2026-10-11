<?php

declare(strict_types=1);

use ArtisanBuild\WakeClient\Contracts\PayloadFilter;
use ArtisanBuild\WakeClient\Contracts\Transport;
use ArtisanBuild\WakeClient\Filters\PassThroughPayloadFilter;
use ArtisanBuild\WakeClient\Logging\LifecycleRegistrar;
use ArtisanBuild\WakeClient\Logging\WakeHandler;
use ArtisanBuild\WakeClient\Transport\HttpTransport;
use ArtisanBuild\WakeContracts\ExceptionData;
use ArtisanBuild\WakeContracts\LogEvent;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\OutputStyle;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    config()->set([
        'wake.url' => 'https://wake.test',
        'wake.token' => 'synthetic-token',
        'wake.batch_size' => 2,
        'wake.max_buffer' => 3,
        'wake.connect_timeout' => 0.25,
        'wake.timeout' => 1.25,
    ]);
    app()->forgetInstance(WakeHandler::class);
});

it('flushes at the batch cap with auth idempotency and configured timeouts', function (): void {
    Http::fake(['*' => Http::response(receipt(2), 202)]);
    $handler = app(WakeHandler::class);

    $handler->handle(deliveryRecord('one'));
    expect($handler->state()['buffered'])->toBe(1);
    $handler->handle(deliveryRecord('two'));

    expect($handler->state())->toBe(['buffered' => 0, 'dropped' => 0]);
    Http::assertSent(function (Request $request): bool {
        $body = $request->data();

        return $request->url() === 'https://wake.test/api/v1/ingest'
            && $request->hasHeader('Authorization', 'Bearer synthetic-token')
            && $request->hasHeader('Idempotency-Key', $body['idempotency_key'])
            && $request->toPsrRequest()->getHeaderLine('Content-Type') === 'application/json'
            && $body['dropped_count'] === 0
            && count($body['rows']) === 2;
    });
    $transport = app(Transport::class);
    expect($transport)->toBeInstanceOf(HttpTransport::class)
        ->and($transport->lastOptions()['connect_timeout'])->toBe(0.25)
        ->and($transport->lastOptions()['timeout'])->toBe(1.25)
        ->and($transport->attempts())->toBe(1);
});

it('carries failed rows and filtered drops until one valid acceptance', function (): void {
    Http::fakeSequence()
        ->push(['error' => 'down'], 500)
        ->push(receipt(2), 202)
        ->push(receipt(1), 202);
    app()->instance(PayloadFilter::class, new class implements PayloadFilter
    {
        private bool $rejectNext = true;

        public function filter(LogEvent $event): ?LogEvent
        {
            if ($this->rejectNext) {
                $this->rejectNext = false;

                return null;
            }

            return $event;
        }
    });
    $handler = app(WakeHandler::class);

    $handler->handle(deliveryRecord('filtered'));
    $handler->handle(deliveryRecord('failed-1'));
    $handler->handle(deliveryRecord('failed-2'));
    expect($handler->state())->toBe(['buffered' => 0, 'dropped' => 3]);

    $handler->handle(deliveryRecord('accepted-1'));
    $handler->handle(deliveryRecord('accepted-2'));
    expect($handler->state()['dropped'])->toBe(0);

    $handler->handle(deliveryRecord('accepted-3'));
    $handler->flush();

    $drops = collect(Http::recorded())->map(fn (array $pair): int => $pair[0]->data()['dropped_count'])->all();
    expect($drops)->toBe([1, 3, 0]);
});

it('carries exact buffer overflow debt through one accepted envelope', function (): void {
    config()->set(['wake.batch_size' => 3, 'wake.max_buffer' => 2]);
    Http::fakeSequence()
        ->push(receipt(2), 202)
        ->push(receipt(1), 202);
    $handler = app(WakeHandler::class);

    $handler->handle(deliveryRecord('buffered-1'));
    $handler->handle(deliveryRecord('buffered-2'));
    $handler->handle(deliveryRecord('overflow'));
    expect($handler->state())->toBe(['buffered' => 2, 'dropped' => 1]);

    expect($handler->flush())->toBeTrue();
    $handler->handle(deliveryRecord('after-overflow'));
    expect($handler->flush())->toBeTrue();

    $requests = Http::recorded()->map(fn (array $pair): array => $pair[0]->data())->all();
    expect($requests[0]['dropped_count'])->toBe(1)
        ->and($requests[0]['rows'])->toHaveCount(2)
        ->and($requests[1]['dropped_count'])->toBe(0)
        ->and($requests[1]['rows'])->toHaveCount(1);
});

it('removes a rebound-filtered secret from the raw request body', function (): void {
    $secret = 'wake-test-secret-7f1d3';
    $marker = '[filtered]';
    app()->instance(PayloadFilter::class, new class($marker) implements PayloadFilter
    {
        public function __construct(private readonly string $marker) {}

        public function filter(LogEvent $event): LogEvent
        {
            return new LogEvent(
                eventId: $event->eventId,
                occurredAt: $event->occurredAt,
                level: $event->level,
                channel: $event->channel,
                message: $this->marker,
                context: ['token' => $this->marker, 'nested' => ['secret' => $this->marker]],
                extra: ['credential' => $this->marker],
                exception: new ExceptionData(
                    class: RuntimeException::class,
                    message: $this->marker,
                    file: __FILE__,
                    line: __LINE__,
                    trace: $this->marker,
                ),
                environment: $event->environment,
                hostname: $event->hostname,
                correlationId: $event->correlationId,
            );
        }
    });
    app()->forgetInstance(WakeHandler::class);
    Http::fake(['*' => Http::response(receipt(1), 202)]);
    $handler = app(WakeHandler::class);
    $handler->handle(new LogRecord(
        new DateTimeImmutable,
        'app',
        Level::Error,
        $secret,
        ['token' => $secret, 'nested' => ['secret' => $secret], 'exception' => new RuntimeException($secret)],
        ['credential' => $secret],
    ));
    $handler->flush();

    $request = Http::recorded()->first()[0];
    expect($request->body())->toContain($marker)
        ->not->toContain($secret);
});

it('swallows transport failures and prevents recursive enqueueing', function (): void {
    $handler = app(WakeHandler::class);
    Http::fake(function () use ($handler) {
        $handler->handle(deliveryRecord('recursive'));

        throw new RuntimeException('transport exploded');
    });

    $handler->handle(deliveryRecord('one'));
    $handler->handle(deliveryRecord('two'));

    expect($handler->state())->toBe(['buffered' => 0, 'dropped' => 2]);
    expect(app(Transport::class)->attempts())->toBe(1);
});

it('guards normalization and filtering re-entry while flushing the outer batch', function (): void {
    config()->set('wake.batch_size', 1);
    $filter = new class implements PayloadFilter
    {
        public int $calls = 0;

        public function filter(LogEvent $event): LogEvent
        {
            $this->calls++;
            app('log')->channel('wake')->warning('nested-filter');

            return $event;
        }
    };
    app()->instance(PayloadFilter::class, $filter);
    app()->forgetInstance(WakeHandler::class);
    app('log')->forgetChannel('wake');
    Http::fake(['*' => Http::response(receipt(1), 202, ['Content-Type' => 'application/json'])]);
    $normalizerCallback = new class implements Stringable
    {
        public function __toString(): string
        {
            app('log')->channel('wake')->warning('nested-normalizer');

            return 'normalized';
        }
    };

    $handler = app(WakeHandler::class);
    $handler->handle(new LogRecord(new DateTimeImmutable, 'app', Level::Info, 'outer', ['callback' => $normalizerCallback]));

    expect($filter->calls)->toBe(1)
        ->and($handler->state())->toBe(['buffered' => 0, 'dropped' => 0]);
    Http::assertSentCount(1);
    $body = Http::recorded()->first()[0]->data();
    expect($body['rows'])->toHaveCount(1)
        ->and($body['rows'][0]['message'])->toBe('outer')
        ->and($body['rows'][0]['context']['callback'])->toBe('normalized');
});

it('accepts a JSON 202 content type with parameters', function (): void {
    Http::fake(['*' => Http::response(receipt(1), 202, ['Content-Type' => 'application/json; charset=utf-8'])]);
    $handler = app(WakeHandler::class);
    $handler->handle(deliveryRecord('json-response'));

    expect($handler->flush())->toBeTrue()
        ->and($handler->state()['dropped'])->toBe(0);
});

it('rejects a non-JSON 202 and carries its row as debt', function (): void {
    Http::fakeSequence()
        ->push(json_encode(receipt(1), JSON_THROW_ON_ERROR), 202, ['Content-Type' => 'text/plain'])
        ->push(json_encode(receipt(1), JSON_THROW_ON_ERROR), 202, ['Content-Type' => 'application/json']);
    $handler = app(WakeHandler::class);
    $handler->handle(deliveryRecord('wrong-media'));
    expect($handler->flush())->toBeFalse()
        ->and($handler->state()['dropped'])->toBe(1);

    $handler->handle(deliveryRecord('valid-media'));
    expect($handler->flush())->toBeTrue()
        ->and($handler->state()['dropped'])->toBe(0);
    $drops = Http::recorded()->map(fn (array $pair): int => $pair[0]->data()['dropped_count'])->all();
    expect($drops)->toBe([0, 1]);
});

it('registers lifecycle listeners once and flushes console completion', function (): void {
    Http::fake(['*' => Http::response(receipt(1), 202)]);
    $registrar = app(LifecycleRegistrar::class);
    $before = app('events')->getRawListeners();
    $registrar->register();
    $registrar->register();
    $listeners = app('events')->getRawListeners();
    foreach ([
        CommandFinished::class,
        'Illuminate\\Queue\\Events\\JobProcessed',
        'Illuminate\\Queue\\Events\\JobExceptionOccurred',
        'Laravel\\Octane\\Events\\RequestTerminated',
        'Laravel\\Octane\\Events\\TaskTerminated',
    ] as $event) {
        expect($listeners[$event])->not->toBeEmpty()
            ->and($listeners[$event])->toHaveCount(count($before[$event]));
    }
    app(WakeHandler::class)->handle(deliveryRecord('console'));

    event(new CommandFinished('demo', new ArrayInput([]), new OutputStyle(new ArrayInput([]), new BufferedOutput), 0));

    foreach ([
        'Illuminate\\Queue\\Events\\JobProcessed',
        'Illuminate\\Queue\\Events\\JobExceptionOccurred',
        'Laravel\\Octane\\Events\\RequestTerminated',
        'Laravel\\Octane\\Events\\TaskTerminated',
    ] as $event) {
        app(WakeHandler::class)->handle(deliveryRecord($event));
        app('events')->dispatch($event);
    }

    Http::assertSentCount(5);
});

it('flushes at ordinary application termination', function (): void {
    Http::fake(['*' => Http::response(receipt(1), 202)]);
    app(WakeHandler::class)->handle(deliveryRecord('terminating'));

    app()->terminate();

    Http::assertSentCount(1);
});

it('swallows filter and normalization exceptions into drop debt', function (): void {
    app()->instance(PayloadFilter::class, new class implements PayloadFilter
    {
        public function filter(LogEvent $event): ?LogEvent
        {
            throw new RuntimeException('filter failed');
        }
    });
    app()->forgetInstance(WakeHandler::class);
    app(WakeHandler::class)->handle(deliveryRecord('filtered'));
    expect(app(WakeHandler::class)->state()['dropped'])->toBe(1);

    app()->instance(PayloadFilter::class, new PassThroughPayloadFilter);
    app()->forgetInstance(WakeHandler::class);
    $resource = fopen('php://memory', 'r');
    app(WakeHandler::class)->handle(new LogRecord(new DateTimeImmutable, 'app', Level::Info, 'unsafe', ['bad' => $resource]));
    fclose($resource);

    expect(app(WakeHandler::class)->state()['dropped'])->toBe(1);
});

it('times out against a real slow HTTP server without throwing', function (): void {
    $process = new Process([PHP_BINARY, __DIR__.'/Fixtures/slow-server.php']);
    $process->start();
    for ($attempt = 0; $attempt < 50 && trim($process->getOutput()) === ''; $attempt++) {
        usleep(10_000);
    }

    try {
        config()->set([
            'wake.url' => 'http://'.trim($process->getOutput()),
            'wake.token' => 'synthetic-token',
            'wake.connect_timeout' => 0.1,
            'wake.timeout' => 0.1,
        ]);
        app()->forgetInstance(Transport::class);
        app()->forgetInstance(WakeHandler::class);
        $handler = app(WakeHandler::class);
        $handler->handle(deliveryRecord('slow'));

        $started = microtime(true);
        expect($handler->flush())->toBeFalse()
            ->and(microtime(true) - $started)->toBeLessThan(0.6)
            ->and($handler->state()['dropped'])->toBe(1);
    } finally {
        $process->stop();
    }
});

function deliveryRecord(string $message): LogRecord
{
    return new LogRecord(new DateTimeImmutable, 'app', Level::Info, $message);
}

function receipt(int $count): array
{
    return ['batch_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAW', 'accepted_count' => $count, 'duplicate' => false];
}
