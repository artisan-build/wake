<?php

use ArtisanBuild\BuiltForCloud\BuiltForCloudServiceProvider;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

it('owns only the D-UI-3 application configuration overlay', function (): void {
    /** @var array<string, mixed> $appConfig */
    $appConfig = require config_path('built-for-cloud.php');

    expect($appConfig)->toBe([
        'manifest' => [
            'name' => 'Wake',
            'slug' => 'wake',
            'description' => 'A self-hosted central log sink for Laravel applications.',
            'icon' => 'https://scalpels.app/img/products/transparent/wake.svg',
            'product_url' => 'https://scalpels.app/products/wake',
        ],
        'credentials' => [
            'guard' => 'bfc',
            'declaration' => null,
            'session_guard' => null,
            'app_purposes' => [],
        ],
        'ui' => [
            'landing_page' => false,
            'member_management' => false,
            'personal_credentials' => false,
            'installation_credentials' => false,
            'session_management' => false,
            'managed_transitions' => false,
            'credential_purposes' => [],
        ],
    ]);
});

it('merges package defaults and auto-discovers the released provider', function (): void {
    expect(config('built-for-cloud.manifest'))->toBe([
        'name' => 'Wake',
        'slug' => 'wake',
        'description' => 'A self-hosted central log sink for Laravel applications.',
        'icon' => 'https://scalpels.app/img/products/transparent/wake.svg',
        'product_url' => 'https://scalpels.app/products/wake',
    ])->and(config('built-for-cloud.ui'))->toBe([
        'landing_page' => false,
        'member_management' => false,
        'personal_credentials' => false,
        'installation_credentials' => false,
        'session_management' => false,
        'managed_transitions' => false,
        'credential_purposes' => [],
    ])->and(config('built-for-cloud.product'))->toBe(config('app.name'))
        ->and(config('built-for-cloud.credentials.guard'))->toBe('bfc')
        ->and(config('auth.defaults.guard'))->toBe('web')
        ->and(config('auth.providers.users.model'))->toBe(User::class)
        ->and(app()->getLoadedProviders())->toHaveKey(BuiltForCloudServiceProvider::class, true);
});

it('runs fresh package-owned migrations on PostgreSQL', function (): void {
    expect(config('database.default'))->toBe('pgsql')
        ->and(Artisan::call('migrate:fresh', [
            '--force' => true,
        ]))->toBe(0)
        ->and(Schema::hasTable('users'))->toBeTrue()
        ->and(Schema::hasTable('bfc_authority'))->toBeTrue()
        ->and(Schema::hasTable('credentials'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'normalized_email'))->toBeTrue()
        ->and(glob(database_path('migrations/*users*')) ?: [])->toBe([])
        ->and(class_exists('App\\Models\\User'))->toBeFalse()
        ->and(file_exists(base_path('wake_test')))->toBeFalse();
});
