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
        'read' => [
            // The budget is the installation-wide share left after reserving web-process headroom.
            // Reader slots are derived as floor(memory_budget / memory_limit).
            'memory_budget' => '96MB',
            'memory_limit' => '96MB',
            'threads' => 1,
            'all_apps_full_download_threshold' => 67_108_864,
            'wait_timeout_milliseconds' => 12_000,
            'poll_interval_milliseconds' => 25,
        ],
        'flush_batch_limit' => 20,
        'retention_days' => 30,
    ],
    'ingest' => [
        'batches_per_minute' => 120,
        'events_per_minute' => 50_000,
    ],
];
