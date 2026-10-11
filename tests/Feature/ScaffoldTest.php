<?php

declare(strict_types=1);

use ArtisanBuild\WakeClient\WakeClientServiceProvider;
use ArtisanBuild\WakeContracts\IngestProtocol;
use ArtisanBuild\WakeServer\WakeServerServiceProvider;
use Illuminate\Support\Facades\DB;

it('boots the monorepo packages against PostgreSQL', function (): void {
    expect(config('database.default'))->toBe('pgsql')
        ->and(DB::selectOne('select 1 as value')?->value)->toBe(1)
        ->and(IngestProtocol::CURRENT_ENVELOPE_VERSION)->toBe(1)
        ->and(app()->getProvider(WakeClientServiceProvider::class))->not->toBeNull()
        ->and(app()->getProvider(WakeServerServiceProvider::class))->not->toBeNull();
});
