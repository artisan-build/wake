<?php

declare(strict_types=1);

return [
    'manifest' => array (
  'name' => 'Wake',
  'slug' => 'wake',
  'description' => 'A self-hosted central log sink for Laravel applications.',
  'icon' => 'https://scalpels.app/img/products/transparent/wake.svg',
  'product_url' => 'https://scalpels.app/products/wake',
),

    'credentials' => [
        'guard' => env('BUILT_FOR_CLOUD_CREDENTIAL_GUARD', 'bfc'),
        'declaration' => null,
        'session_guard' => null,
        'app_purposes' => [],
    ],

    'ui' => array (
  'landing_page' => false,
  'member_management' => false,
  'personal_credentials' => false,
  'installation_credentials' => false,
  'session_management' => false,
  'managed_transitions' => false,
  'credential_purposes' => 
  array (
  ),
),
];