<?php

declare(strict_types=1);

return [
    'lake' => [
        'cloud' => (bool) env('LARAVEL_CLOUD', false),
        'binary' => base_path('bin/duckdb'),
        'extension_directory' => base_path('var/duckdb-extensions'),
        'data_prefix' => 'ducklake/logs/',
        'threads' => 1,
        'memory_limit' => '384MB',
    ],
    'ingest' => [
        'batches_per_minute' => 120,
        'events_per_minute' => 50_000,
    ],
];
