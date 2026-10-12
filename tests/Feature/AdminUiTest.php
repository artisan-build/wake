<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\AuthorityMode;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    config()->set('auth.guards.bfc', ['driver' => 'bfc', 'provider' => 'users']);
    DB::table('bfc_authority')->where('key', InstallationAuthority::KEY)->update([
        'mode' => AuthorityMode::Standalone->value,
        'generation' => 1,
        'updated_at' => now(),
    ]);

    $user = User::query()->create([
        'name' => 'UI member',
        'email' => 'ui-member@example.test',
        'password' => Hash::make('test-password'),
    ]);
    $user->forceFill([
        'role' => UserRole::Member->value,
        'status' => 'active',
        'email_verified_at' => now(),
    ])->save();

    $this->post('/bfc/login', [
        'email' => $user->email,
        'password' => 'test-password',
    ])->assertRedirect('/dashboard');
});

it('renders accessible list create and detail pages with the exact setup snippet', function (): void {
    $index = $this->get('/apps')->assertOk()
        ->assertSee('data-testid="apps-index"', false)
        ->assertSee('<h1', false)
        ->assertSee('for="app-id"', false)
        ->assertSee('for="app-name"', false)
        ->assertSee('name="_token"', false)
        ->assertSee('No apps have been connected yet.');

    expect($index->getContent())->not->toContain('Log viewer', 'Search logs', '<canvas');

    $created = $this->post('/apps', ['id' => 'billing-api', 'name' => 'Billing API'])
        ->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('Copy this token now.')
        ->assertSee('composer require artisan-build/wake-client')
        ->assertSee('WAKE_URL=http://localhost')
        ->assertSee('LOG_STACK=single,wake')
        ->assertSee('href="/docs/agent-redaction"', false)
        ->assertSee('overflow-x:auto', false)
        ->assertSee('aria-label="Wake client setup"', false);

    preg_match('/WAKE_TOKEN=([^<\s]+)/', $created->getContent(), $matches);
    $secret = $matches[1] ?? null;
    $credential = Credential::query()->where('subject_ref', 'billing-api')->sole();
    expect($secret)->toBeString()->not->toBe('')
        ->and(hash_equals((string) $credential->secret_hash, hash('sha256', $secret)))->toBeTrue();

    $this->get('/apps')->assertOk()->assertSee('Billing API')->assertDontSee($secret);
    $detail = $this->get('/apps/billing-api')->assertOk()
        ->assertSee('data-testid="app-detail"', false)
        ->assertSee('Billing API')
        ->assertSee($credential->id)
        ->assertSee('WAKE_TOKEN=&lt;your Wake token&gt;', false)
        ->assertSee('WAKE_URL=http://localhost')
        ->assertSee('LOG_STACK=single,wake')
        ->assertDontSee($secret);
    expect($detail->getContent())->not->toContain('Log viewer', 'Search logs', '<canvas');
});

it('returns rotation secrets directly once and shows the revoked state honestly', function (): void {
    $created = $this->post('/apps', ['id' => 'worker', 'name' => 'Worker'])->assertCreated();
    preg_match('/WAKE_TOKEN=([^<\s]+)/', $created->getContent(), $createdMatch);

    $rotated = $this->post('/apps/worker/credential/rotate')
        ->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('Credential rotated')
        ->assertSee('WAKE_TOKEN=');
    preg_match('/WAKE_TOKEN=([^<\s]+)/', $rotated->getContent(), $rotatedMatch);
    $replacement = $rotatedMatch[1] ?? null;

    expect($replacement)->toBeString()->not->toBe($createdMatch[1] ?? null);
    $this->get('/apps/worker')->assertOk()->assertDontSee($replacement);
    $this->delete('/apps/worker/credential')->assertRedirect('/apps/worker');
    $this->get('/apps/worker')->assertOk()
        ->assertSee('This app has no current ingest credential.')
        ->assertDontSee('Rotate credential')
        ->assertDontSee('Revoke credential');
});

it('shows browser validation errors and keeps admin routes on the human session stack', function (): void {
    $this->from('/apps')->followingRedirects()->post('/apps', [
        'id' => 'Invalid ID',
        'name' => '',
    ])->assertOk()
        ->assertSee('data-testid="apps-errors"', false)
        ->assertSee('Check the app details');

    foreach (['apps.index', 'apps.store', 'apps.show', 'apps.credential.rotate', 'apps.credential.revoke'] as $name) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull()
            ->and($route?->gatherMiddleware())->toContain('web', 'bfc.auth');
    }

    $paths = collect(Route::getRoutes())->map->uri()->all();
    expect($paths)->not->toContain('logs', 'logs/search', 'search');
});
