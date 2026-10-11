<?php

declare(strict_types=1);

return [
    'lake' => [
        'cloud' => (bool) env('LARAVEL_CLOUD', false),
        'binary' => base_path('bin/duckdb'),
        'extension_directory' => base_path('var/duckdb-extensions'),
        'data_prefix' => 'ducklake/logs/',
        'postgres_catalog' => false,
        'metadata_schema' => null,
        'data_path' => null,
        'threads' => 1,
        'memory_limit' => '384MB',
        'flush_batch_limit' => 20,
        'retention_days' => 30,
    ],
    'ingest' => [
        'batches_per_minute' => 120,
        'events_per_minute' => 50_000,
    ],
];
