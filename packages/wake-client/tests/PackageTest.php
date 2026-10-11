<?php

declare(strict_types=1);

use ArtisanBuild\WakeClient\WakeClientServiceProvider;
use Illuminate\Support\ServiceProvider;

it('provides a Laravel package provider', function (): void {
    expect(is_subclass_of(WakeClientServiceProvider::class, ServiceProvider::class))->toBeTrue();
});
