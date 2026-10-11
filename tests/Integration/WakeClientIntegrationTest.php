<?php

declare(strict_types=1);

use ArtisanBuild\WakeClient\Contracts\Transport;
use ArtisanBuild\WakeClient\Logging\WakeHandler;
use ArtisanBuild\WakeClient\WakeClientServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Monolog\Logger;
use Symfony\Component\Process\Process;

it('discovers the path package and registers an inert wake channel', function (): void {
    expect(app()->getProvider(WakeClientServiceProvider::class))->not->toBeNull()
        ->and(resolve('log')->channel('wake')->getLogger())->toBeInstanceOf(Logger::class);

    Http::fake();
    foreach ([[null, null], ['https://wake.test', null], [null, 'synthetic-token']] as [$url, $token]) {
        config()->set(['wake.url' => $url, 'wake.token' => $token]);
        app()->forgetInstance(Transport::class);
        app()->forgetInstance(WakeHandler::class);
        resolve('log')->forgetChannel('wake');

        resolve('log')->channel('wake')->info('inert');
        resolve(WakeHandler::class)->flush();
    }

    Http::assertNothingSent();
});

it('boots the automatic wake channel from an isolated generated config cache', function (): void {
    $cachePath = storage_path('framework/cache/wake-client-'.Str::uuid().'.php');
    $environment = ['APP_CONFIG_CACHE' => $cachePath];
    $generate = new Process([PHP_BINARY, 'artisan', 'config:cache'], base_path(), $environment);

    try {
        $generate->mustRun();
        expect($cachePath)->toBeFile();

        $probe = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$channel = $app->make('log')->channel('wake')->getLogger();
echo ($app->configurationIsCached() && $channel instanceof Monolog\Logger) ? 'cached:wake' : 'failed';
PHP;
        $boot = new Process([PHP_BINARY, '-r', $probe], base_path(), $environment);
        $boot->mustRun();

        expect(trim($boot->getOutput()))->toBe('cached:wake');
    } finally {
        if (is_file($cachePath)) {
            unlink($cachePath);
        }
    }

    expect($cachePath)->not->toBeFile();
});
