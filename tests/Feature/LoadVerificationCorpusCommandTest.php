<?php

declare(strict_types=1);

use App\Console\Commands\LoadVerificationCorpus;
use App\Verification\ScaleCorpusLoader;

it('refuses a missing or wrong confirmation before resolving the loader', function (?string $confirmation): void {
    app()->bind(ScaleCorpusLoader::class, function (): never {
        throw new RuntimeException('loader was resolved');
    });
    $arguments = ['--rows' => '1', '--apps' => '1', '--days' => '1', '--chunk' => '1'];
    if ($confirmation !== null) {
        $arguments['--confirm'] = $confirmation;
    }

    $this->artisan('wake:verification-load', $arguments)
        ->expectsOutputToContain('Refusing writes')
        ->doesntExpectOutputToContain('loader was resolved')
        ->assertFailed();
})->with(['missing' => null, 'wrong' => 'yes']);

it('prints the default plan without resolving the loader or writing', function (): void {
    app()->bind(ScaleCorpusLoader::class, function (): never {
        throw new RuntimeException('loader was resolved');
    });

    $this->artisan('wake:verification-load', ['--dry-run' => true])
        ->expectsOutputToContain('run_key=pr7-cloud rows=50000000 apps=40 days=30 chunk=100000 chunks=500')
        ->doesntExpectOutputToContain('loader was resolved')
        ->assertSuccessful();
});

it('rejects invalid command options before resolving the loader', function (string $option, string $value): void {
    app()->bind(ScaleCorpusLoader::class, function (): never {
        throw new RuntimeException('loader was resolved');
    });

    $this->artisan('wake:verification-load', ["--{$option}" => $value, '--confirm' => LoadVerificationCorpus::CONFIRMATION])
        ->doesntExpectOutputToContain('loader was resolved')
        ->assertExitCode(2);
})->with([
    ['rows', '0'], ['apps', '-1'], ['days', 'no'], ['chunk', '100001'], ['run', 'NOT SAFE'],
]);
