<?php

declare(strict_types=1);

use ArtisanBuild\WakeServer\WakeServerServiceProvider;
use Orchestra\Testbench\TestCase;

uses(TestCase::class)->in(__DIR__);

uses()->beforeEach(function (): void {
    $this->app->register(WakeServerServiceProvider::class);
})->in(__DIR__);
