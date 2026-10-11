<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeClient\Tests;

use ArtisanBuild\WakeClient\WakeClientServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [WakeClientServiceProvider::class];
    }
}
