<?php

declare(strict_types=1);

use ArtisanBuild\WakeContracts\Package;

it('exposes the contracts package marker', function (): void {
    expect(Package::NAME)->toBe('wake-contracts');
});
