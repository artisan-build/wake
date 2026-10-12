<?php

declare(strict_types=1);

use ArtisanBuild\WakeContracts\IngestProtocol;

return [
    'url' => env('WAKE_URL'),
    'token' => env('WAKE_TOKEN'),
    'auto_attach' => env('WAKE_AUTO_ATTACH', true),
    'batch_size' => (int) env('WAKE_BATCH_SIZE', 100),
    'max_buffer' => (int) env('WAKE_MAX_BUFFER', IngestProtocol::MAX_ROWS),
    'connect_timeout' => (float) env('WAKE_CONNECT_TIMEOUT', 0.5),
    'timeout' => (float) env('WAKE_TIMEOUT', 2.0),
];
