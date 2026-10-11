<?php

declare(strict_types=1);

use ArtisanBuild\WakeServer\WakeServerServiceProvider;
use Illuminate\Support\ServiceProvider;

it('provides a Laravel package provider', function (): void {
    expect(is_subclass_of(WakeServerServiceProvider::class, ServiceProvider::class))->toBeTrue();
});
