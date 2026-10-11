<?php

declare(strict_types=1);

use ArtisanBuild\WakeContracts\IngestProtocol;

return [
    'url' => env('WAKE_URL'),
    'token' => env('WAKE_TOKEN'),
    'batch_size' => 100,
    'max_buffer' => IngestProtocol::MAX_ROWS,
    'connect_timeout' => 0.5,
    'timeout' => 2.0,
];
