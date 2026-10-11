<?php

declare(strict_types=1);

use ArtisanBuild\WakeServer\WakeServerServiceProvider;
use Illuminate\Support\ServiceProvider;

it('provides a Laravel package provider', function (): void {
    expect(is_subclass_of(WakeServerServiceProvider::class, ServiceProvider::class))->toBeTrue();
});

it('ships safe ingest cap defaults without required environment configuration', function (): void {
    /** @var array{ingest: array{batches_per_minute: int, events_per_minute: int}} $config */
    $config = require __DIR__.'/../config/wake-server.php';

    expect($config['ingest'])
        ->toBe([
            'batches_per_minute' => 120,
            'events_per_minute' => 50_000,
        ]);
});
