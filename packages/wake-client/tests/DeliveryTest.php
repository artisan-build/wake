<?php

declare(strict_types=1);

use ArtisanBuild\WakeClient\Contracts\PayloadFilter;
use ArtisanBuild\WakeClient\Logging\LifecycleRegistrar;
use ArtisanBuild\WakeClient\Logging\WakeHandler;
use ArtisanBuild\WakeContracts\LogEvent;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\OutputStyle;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

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

it('swallows transport failures and prevents recursive enqueueing', function (): void {
    $handler = app(WakeHandler::class);
    Http::fake(function () use ($handler) {
        $handler->handle(deliveryRecord('recursive'));

        throw new RuntimeException('transport exploded');
    });

    $handler->handle(deliveryRecord('one'));
    $handler->handle(deliveryRecord('two'));

    expect($handler->state())->toBe(['buffered' => 0, 'dropped' => 2]);
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

function deliveryRecord(string $message): LogRecord
{
    return new LogRecord(new DateTimeImmutable, 'app', Level::Info, $message);
}

function receipt(int $count): array
{
    return ['batch_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAW', 'accepted_count' => $count, 'duplicate' => false];
}
