<?php

declare(strict_types=1);

namespace Tests\Support;

final class ReferenceConsumerInventoryControls
{
    /**
     * @return array<string, array{families: list<string>, files: array<string, string>}>
     */
    public static function cases(): array
    {
        return [
            'aliased authenticatable contract identity' => [
                'families' => ['app_human_identity'],
                'files' => [
                    'app/Models/Account.php' => <<<'PHP'
<?php

use Illuminate\Contracts\Auth\Authenticatable as HumanIdentity;

final class Account implements HumanIdentity {}
PHP,
                ],
            ],
            'framework auth base identity' => [
                'families' => ['app_human_identity'],
                'files' => [
                    'app/Models/Account.php' => '<?php final class Account extends \\Illuminate\\Foundation\\Auth\\User {}',
                ],
            ],
            'two factor users migration' => [
                'families' => ['auth_migrations'],
                'files' => [
                    'database/migrations/2025_08_14_170933_add_two_factor_columns_to_users_table.php' => '<?php return true;',
                ],
            ],
            'Fortify provider' => [
                'families' => ['fortify'],
                'files' => [
                    'app/Providers/FortifyServiceProvider.php' => '<?php final class FortifyServiceProvider {}',
                ],
            ],
            'auth controller' => [
                'families' => ['app_auth_surface'],
                'files' => [
                    'app/Http/Controllers/Auth/LoginController.php' => '<?php final class LoginController {}',
                ],
            ],
            'Livewire auth action' => [
                'families' => ['app_auth_surface'],
                'files' => [
                    'app/Livewire/Actions/Logout.php' => '<?php Auth::guard(\'web\')->logout();',
                ],
            ],
            'Livewire auth component' => [
                'families' => ['app_auth_surface'],
                'files' => [
                    'app/Livewire/Auth/Login.php' => '<?php final class Login {}',
                ],
            ],
            'nested Livewire auth view' => [
                'families' => ['app_auth_surface'],
                'files' => [
                    'resources/views/livewire/auth/login.blade.php' => '<form>Login</form>',
                ],
            ],
            'nested auth layout view' => [
                'families' => ['app_auth_surface'],
                'files' => [
                    'resources/views/layouts/auth/simple.blade.php' => '<main>{{ $slot }}</main>',
                ],
            ],
            'auth route file' => [
                'families' => ['app_auth_surface'],
                'files' => [
                    'routes/auth.php' => '<?php',
                ],
            ],
            'slashless login route' => [
                'families' => ['app_auth_surface'],
                'files' => [
                    'routes/web.php' => "<?php Route::get('login', fn () => 'login');",
                ],
            ],
            'slashless register route' => [
                'families' => ['app_auth_surface'],
                'files' => [
                    'routes/web.php' => "<?php Route::post('register', fn () => 'register');",
                ],
            ],
            'slashless reset route' => [
                'families' => ['app_auth_surface'],
                'files' => [
                    'routes/web.php' => "<?php Route::get('reset-password/token', fn () => 'reset');",
                ],
            ],
            'slashless password route' => [
                'families' => ['app_auth_surface'],
                'files' => [
                    'routes/web.php' => "<?php Route::put('password', fn () => 'password');",
                ],
            ],
            'foreign human provider' => [
                'families' => ['foreign_human_guards'],
                'files' => [
                    'config/auth.php' => "<?php return ['guards' => [], 'providers' => []];",
                ],
            ],
            'redirect root route' => [
                'families' => ['starter_root_collision'],
                'files' => [
                    'routes/web.php' => "<?php Route::redirect('/', '/dashboard');",
                ],
            ],
            'frozen 11ac35a predecessor shapes' => [
                'families' => ReferenceConsumerInventory::FAMILIES,
                'files' => [
                    'app/Models/User.php' => <<<'PHP'
<?php

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable {}
PHP,
                    'database/migrations/2025_08_14_170933_add_two_factor_columns_to_users_table.php' => '<?php return true;',
                    'app/Actions/Fortify/CreateNewUser.php' => '<?php final class CreateNewUser {}',
                    'app/Providers/FortifyServiceProvider.php' => '<?php final class FortifyServiceProvider {}',
                    'app/Livewire/Actions/Logout.php' => '<?php Auth::guard(\'web\')->logout();',
                    'resources/views/livewire/auth/login.blade.php' => '<form>Login</form>',
                    'resources/views/layouts/auth/simple.blade.php' => '<main>{{ $slot }}</main>',
                    'routes/settings.php' => <<<'PHP'
<?php

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::livewire('settings/security', Security::class)->middleware('password.confirm');
});
PHP,
                    'routes/web.php' => <<<'PHP'
<?php

Route::view('/', 'welcome')->name('home');
require __DIR__.'/settings.php';
PHP,
                    'resources/views/welcome.blade.php' => '<main>Welcome</main>',
                    'config/auth.php' => <<<'PHP'
<?php

use App\Models\User;

return [
    'guards' => ['web' => ['driver' => 'session', 'provider' => 'users']],
    'providers' => ['users' => ['driver' => 'eloquent', 'model' => User::class]],
];
PHP,
                ],
            ],
        ];
    }
}
