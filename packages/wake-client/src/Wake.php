<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeClient;

use ArtisanBuild\WakeClient\Contracts\Transport;
use ArtisanBuild\WakeClient\Logging\WakeHandler;
use ArtisanBuild\WakeClient\Testing\WakeFake;
use Illuminate\Log\LogManager;
use LogicException;

final class Wake
{
    public static function fake(): WakeFake
    {
        $fake = new WakeFake;
        config()->set(['wake.url' => 'https://wake.fake', 'wake.token' => 'wake-fake']);
        app()->instance(Transport::class, $fake);
        app()->forgetInstance(WakeHandler::class);
        $logs = app('log');
        if (! $logs instanceof LogManager) {
            throw new LogicException('Laravel log manager is unavailable.');
        }
        $logs->forgetChannel('wake');

        return $fake;
    }
}
