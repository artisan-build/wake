<?php

declare(strict_types=1);

use ArtisanBuild\WakeClient\Contracts\Transport;
use ArtisanBuild\WakeClient\Logging\WakeHandler;
use ArtisanBuild\WakeClient\WakeClientServiceProvider;
use Illuminate\Support\Facades\Http;
use Monolog\Logger;

it('discovers the path package and registers an inert wake channel', function (): void {
    expect(app()->getProvider(WakeClientServiceProvider::class))->not->toBeNull()
        ->and(app('log')->channel('wake')->getLogger())->toBeInstanceOf(Logger::class);

    Http::fake();
    foreach ([[null, null], ['https://wake.test', null], [null, 'synthetic-token']] as [$url, $token]) {
        config()->set(['wake.url' => $url, 'wake.token' => $token]);
        app()->forgetInstance(Transport::class);
        app()->forgetInstance(WakeHandler::class);
        app('log')->forgetChannel('wake');

        app('log')->channel('wake')->info('inert');
        app(WakeHandler::class)->flush();
    }

    Http::assertNothingSent();
});
