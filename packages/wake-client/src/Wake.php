<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeClient;

use ArtisanBuild\WakeClient\Contracts\Transport;
use ArtisanBuild\WakeClient\Logging\WakeHandler;
use ArtisanBuild\WakeClient\Testing\WakeFake;

final class Wake
{
    public static function fake(): WakeFake
    {
        $fake = new WakeFake;
        config()->set(['wake.url' => 'https://wake.fake', 'wake.token' => 'wake-fake']);
        app()->instance(Transport::class, $fake);
        app()->forgetInstance(WakeHandler::class);
        app('log')->forgetChannel('wake');

        return $fake;
    }
}
