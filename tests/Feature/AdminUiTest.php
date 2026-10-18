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
        ->assertSee('[data-testid="apps-create"] input', false)
        ->assertSee('border: 1px solid var(--color-clay-edge)', false)
        ->assertSee('input:focus-visible', false)
        ->assertSee('name="_token"', false)
        ->assertSee('No apps have been connected yet.');

    expect($index->getContent())->not->toContain('Log viewer')
        ->not->toContain('Search logs')
        ->not->toContain('<canvas');

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
    expect($detail->getContent())->not->toContain('Log viewer')
        ->not->toContain('Search logs')
        ->not->toContain('<canvas');
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
    expect($paths)->not->toContain('logs')
        ->not->toContain('logs/search')
        ->not->toContain('search');
});

it('shows honest derived PostgreSQL health and escapes hostile metadata', function (): void {
    $this->post('/apps', ['id' => 'hostile-app', 'name' => 'Hostile App'])->assertCreated();
    $lastSeen = now()->startOfSecond();
    DB::table('wake_connected_apps')->where('id', 'hostile-app')->update([
        'accepted_rows' => 1250,
        'flushed_rows' => 1200,
        'rejected_batches' => 3,
        'rate_limit_hits' => 4,
        'dropped_rows' => 5,
        'last_seen_at' => $lastSeen,
        'last_flushed_at' => $lastSeen->copy()->subSeconds(90),
        'last_client_version' => '<img src=x onerror=alert(1)>',
    ]);
    DB::table('wake_hourly_counts')->insert([
        'app_id' => 'hostile-app',
        'environment' => '<script>alert(1)</script>',
        'level' => 'info',
        'hour' => $lastSeen->copy()->startOfHour(),
        'count' => 1250,
    ]);
    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = $this->get('/apps/hostile-app')->assertOk()
        ->assertSee('Ingest is lagging behind the latest accepted batch.')
        ->assertSee('1,250')
        ->assertSee('1,200')
        ->assertSee('90 seconds')
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('<img src=x onerror=alert(1)>', false);

    expect($response->getContent())->not->toContain('Log viewer')
        ->not->toContain('Search logs')
        ->not->toContain('<canvas')
        ->and(implode(' ', $queries))->not->toContain('wake_error_events')
        ->not->toContain('lake.logs');
});

it('reports never-seen pending and caught-up health without inventing data', function (): void {
    $this->post('/apps', ['id' => 'empty-app', 'name' => 'Empty App'])->assertCreated();
    $this->get('/apps/empty-app')->assertOk()
        ->assertSee('Wake has not received a batch from this app.')
        ->assertSee('Never')
        ->assertSee('None reported')
        ->assertSee('Not available')
        ->assertSee('Not reported');

    $seen = now()->startOfSecond();
    DB::table('wake_connected_apps')->where('id', 'empty-app')->update(['last_seen_at' => $seen]);
    $this->get('/apps/empty-app')->assertOk()->assertSee('Wake has received data, but no flush has completed yet.');

    DB::table('wake_connected_apps')->where('id', 'empty-app')->update(['last_flushed_at' => $seen]);
    $this->get('/apps/empty-app')->assertOk()->assertSee('Ingest is caught up with the latest accepted batch.');
});

it('serves a public landing page that routes people to the admin without viewer claims', function (): void {
    $this->post('/bfc/logout')->assertRedirect('/bfc/login');

    $response = $this->get('/')->assertOk()
        ->assertSee('Logs for your agents')
        ->assertSee('self-hosted log sink')
        ->assertSee('Logs are sent as written')
        ->assertSee('href="http://localhost/apps"', false)
        ->assertSee('data-testid="landing-admin"', false)
        ->assertSee('<link rel="icon" href="http://localhost/favicon.svg">', false)
        ->assertSee('<img src="http://localhost/favicon.svg"', false)
        ->assertDontSee('https://scalpels.app/img/products/transparent/wake.png', false);

    expect($response->getContent())->not->toContain('Log viewer')
        ->not->toContain('Search logs')
        ->not->toContain('<canvas')
        ->not->toContain('Charts')
        ->not->toContain('Alerts');
    $this->get('/apps')->assertRedirect('/bfc/login');
});
