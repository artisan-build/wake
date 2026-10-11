<?php

declare(strict_types=1);

use ArtisanBuild\WakeClient\Contracts\Transport;
use ArtisanBuild\WakeClient\Logging\WakeHandler;
use ArtisanBuild\WakeClient\Wake;
use Illuminate\Support\Facades\Http;

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

function doctorReceipt(): array
{
    return ['batch_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAW', 'accepted_count' => 1, 'duplicate' => false];
}
