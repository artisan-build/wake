<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\AuthorityMode;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

it('renders the complete app navigation once in the package sidebar with the current destination', function (): void {
    config()->set('auth.guards.bfc', ['driver' => 'bfc', 'provider' => 'users']);
    DB::table('bfc_authority')->where('key', InstallationAuthority::KEY)->update([
        'mode' => AuthorityMode::Standalone->value,
        'generation' => 1,
        'updated_at' => now(),
    ]);

    $user = User::query()->create([
        'name' => 'Sidebar member',
        'email' => 'sidebar-member@example.test',
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

    $destinations = [
        route('docs.show', 'setup') => 'Setup',
        route('docs.show', 'agent-redaction') => 'Redaction',
        route('docs.show', 'retention-cost') => 'Retention and cost',
        route('docs.show', 'ingest-contract') => 'Ingest contract',
        route('apps.index') => 'Admin',
    ];

    $created = $this->post('/apps', ['id' => 'sidebar-app', 'name' => 'Sidebar App'])->assertCreated();

    $this->get('/')->assertRedirect('/dashboard');

    $pages = [
        [$this->get('/docs/setup')->assertOk(), 'documentation-content', route('docs.show', 'setup')],
        [$this->get('/docs/agent-redaction')->assertOk(), 'documentation-content', route('docs.show', 'agent-redaction')],
        [$this->get('/docs/retention-cost')->assertOk(), 'documentation-content', route('docs.show', 'retention-cost')],
        [$this->get('/docs/ingest-contract')->assertOk(), 'documentation-content', route('docs.show', 'ingest-contract')],
        [$this->get('/apps')->assertOk(), 'apps-index', route('apps.index')],
        [$created, 'app-secret', route('apps.index')],
        [$this->get('/apps/sidebar-app')->assertOk(), 'app-detail', route('apps.index')],
    ];

    foreach ($pages as [$response, $mainMarker, $currentDestination]) {
        $document = new DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new DOMXPath($document);

        expect($xpath->query('//*[@data-testid="bfc-sidebar"]')->length)->toBe(1)
            ->and($xpath->query('//*[@data-testid="bfc-sidebar-slot"]')->length)->toBe(1)
            ->and($xpath->query('//*[@data-testid="bfc-sidebar-fallback"]')->length)->toBe(0)
            ->and($xpath->query('//*[@data-testid="app-navigation"]')->length)->toBe(1)
            ->and($xpath->query('//nav[@aria-label="Documentation"]')->length)->toBe(0)
            ->and($xpath->query('//*[@data-testid="bfc-main"]//*[@data-testid="'.$mainMarker.'"]')->length)->toBe(1);

        $links = $xpath->query('//*[@data-testid="app-navigation"]/a');
        expect($links->length)->toBe(count($destinations));

        foreach ($links as $index => $link) {
            expect($link->getAttribute('href'))->toBe(array_keys($destinations)[$index])
                ->and(trim($link->textContent))->toBe(array_values($destinations)[$index]);
        }

        $currentLinks = $xpath->query('//*[@data-testid="app-navigation"]/a[@aria-current="page"]');

        if ($currentDestination === null) {
            expect($currentLinks->length)->toBe(0);
        } else {
            expect($currentLinks->length)->toBe(1)
                ->and($currentLinks->item(0)?->getAttribute('href'))->toBe($currentDestination);
        }
    }
});
