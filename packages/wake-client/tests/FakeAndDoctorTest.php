<?php

declare(strict_types=1);

use ArtisanBuild\WakeClient\Contracts\PayloadFilter;
use ArtisanBuild\WakeClient\Contracts\Transport;
use ArtisanBuild\WakeClient\Logging\WakeHandler;
use ArtisanBuild\WakeClient\Wake;
use ArtisanBuild\WakeContracts\LogEvent;
use Illuminate\Support\Facades\Http;
use Monolog\Level;
use Monolog\LogRecord;

it('provides a public fake that captures the normal envelope path without HTTP', function (): void {
    config()->set(['wake.url' => null, 'wake.token' => null]);
    Http::preventStrayRequests();

    $fake = Wake::fake();
    app('log')->channel('wake')->info('captured', ['correlation_id' => 'fake-1']);
    app(WakeHandler::class)->flush();

    $fake->assertSentCount(1);
    expect($fake->events()[0]->message)->toBe('captured')
        ->and($fake->events()[0]->correlationId)->toBe('fake-1')
        ->and($fake->batches())->toHaveCount(1);
    Http::assertNothingSent();
});

it('rebuilds a previously resolved wake channel around the fake handler', function (): void {
    config()->set([
        'wake.url' => 'https://wake.test',
        'wake.token' => 'synthetic-token',
        'wake.batch_size' => 1,
    ]);
    app()->forgetInstance(Transport::class);
    app()->forgetInstance(WakeHandler::class);
    $logs = app('log');
    $logs->forgetChannel('wake');
    $logs->channel('wake');
    Http::preventStrayRequests();

    $fake = Wake::fake();
    $logs->channel('wake')->info('captured-after-fake');
    app(WakeHandler::class)->flush();

    Http::assertNothingSent();
    $fake->assertSentCount(1);
});

it('doctor succeeds only for a valid accepted round trip without printing the token', function (): void {
    config()->set(['wake.url' => 'https://wake.test', 'wake.token' => 'doctor-secret']);
    app()->forgetInstance(WakeHandler::class);
    Http::fake(['*' => Http::response(doctorReceipt(), 202)]);

    $this->artisan('wake:doctor')
        ->assertSuccessful()
        ->expectsOutputToContain('healthy')
        ->doesntExpectOutputToContain('doctor-secret');
});

it('doctor fails for partial configuration and malformed acceptance', function (): void {
    config()->set(['wake.url' => 'https://wake.test', 'wake.token' => null]);
    app()->forgetInstance(WakeHandler::class);
    $this->artisan('wake:doctor')->assertFailed();

    config()->set(['wake.token' => 'doctor-secret']);
    app()->forgetInstance(WakeHandler::class);
    app()->forgetInstance(Transport::class);
    Http::fake(['*' => Http::response(['accepted_count' => 1], 202)]);
    $this->artisan('wake:doctor')
        ->assertFailed()
        ->doesntExpectOutputToContain('doctor-secret');
});

it('doctor cannot reuse a stale prior success when its record is rejected', function (): void {
    config()->set(['wake.url' => 'https://wake.test', 'wake.token' => 'doctor-secret']);
    $filter = new class implements PayloadFilter
    {
        public bool $reject = false;

        public function filter(LogEvent $event): ?LogEvent
        {
            return $this->reject ? null : $event;
        }
    };
    app()->instance(PayloadFilter::class, $filter);
    app()->forgetInstance(WakeHandler::class);
    Http::fake(['*' => Http::response(doctorReceipt(), 202)]);
    $handler = app(WakeHandler::class);
    expect($handler->probe(new LogRecord(new DateTimeImmutable, 'doctor', Level::Info, 'prior success')))->toBeTrue();

    $filter->reject = true;
    $this->artisan('wake:doctor')->assertFailed();
});

function doctorReceipt(): array
{
    return ['batch_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAW', 'accepted_count' => 1, 'duplicate' => false];
}
