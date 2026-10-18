<?php

declare(strict_types=1);

use App\Services\ConnectedAppCredentials;
use ArtisanBuild\BuiltForCloud\AuditActor;
use ArtisanBuild\BuiltForCloud\AuthorityMode;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialAuditEvent;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\InstallationAuthority;
use ArtisanBuild\BuiltForCloud\LifecycleEventType;
use ArtisanBuild\BuiltForCloud\RevokeOutcome;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\BuiltForCloud\UserRole;
use ArtisanBuild\WakeServer\Models\ConnectedApp;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('auth.guards.bfc', ['driver' => 'bfc', 'provider' => 'users']);
    DB::table('bfc_authority')->where('key', InstallationAuthority::KEY)->update([
        'mode' => AuthorityMode::Standalone->value,
        'generation' => 1,
        'updated_at' => now(),
    ]);

    $this->user = User::query()->create([
        'name' => 'Wake member',
        'email' => 'wake-member@example.test',
        'password' => Hash::make('test-password'),
    ]);
    $this->user->forceFill([
        'role' => UserRole::Member->value,
        'status' => 'active',
        'email_verified_at' => now(),
    ])->save();
});

function loginWakeMember(): void
{
    test()->post('/bfc/login', [
        'email' => 'wake-member@example.test',
        'password' => 'test-password',
    ])->assertRedirect('/dashboard');
}

it('creates rotates and revokes the exact app credential with response-only secrets', function (): void {
    loginWakeMember();

    $created = $this->postJson('/apps', ['id' => 'billing-api', 'name' => 'Billing API'])
        ->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private');
    $firstSecret = $created->json('secret');
    $first = Credential::query()->findOrFail($created->json('credential_id'));

    expect($firstSecret)->toBeString()->not->toBe('')
        ->and($first->purpose)->toBe(CredentialPurpose::Consumption)
        ->and($first->subject_type)->toBe(SubjectType::Installation)
        ->and($first->subject_ref)->toBe('billing-api')
        ->and($first->user_id)->toBeNull()
        ->and($first->abilities)->toBeNull()
        ->and(hash_equals((string) $first->secret_hash, hash('sha256', $firstSecret)))->toBeTrue()
        ->and(json_encode($first->getAttributes(), JSON_THROW_ON_ERROR))->not->toContain($firstSecret);

    $this->getJson('/apps/billing-api')->assertOk()->assertDontSee($firstSecret);

    $this->post('/bfc/logout')->assertRedirect('/bfc/login');
    foreach (['/mcp', '/mcp/destructive'] as $path) {
        $this->withHeader('Authorization', 'Bearer '.$firstSecret)->postJson($path, [
            'jsonrpc' => '2.0', 'id' => 'ingest-refusal', 'method' => 'tools/list',
        ])->assertUnauthorized();
    }
    foreach ([
        ['GET', '/apps'],
        ['POST', '/apps'],
        ['GET', '/apps/billing-api'],
        ['POST', '/apps/billing-api/credential/rotate'],
        ['DELETE', '/apps/billing-api/credential'],
    ] as [$method, $uri]) {
        $this->withHeader('Authorization', 'Bearer '.$firstSecret)->json($method, $uri)->assertUnauthorized();
    }
    $this->flushHeaders();
    loginWakeMember();

    $rotated = $this->postJson('/apps/billing-api/credential/rotate')
        ->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private');
    $replacementSecret = $rotated->json('secret');
    $replacement = Credential::query()->findOrFail($rotated->json('credential_id'));

    expect($replacementSecret)->toBeString()->not->toBe($firstSecret)
        ->and($first->refresh()->rotated_at)->not->toBeNull()
        ->and($replacement->subject_ref)->toBe('billing-api')
        ->and(hash_equals((string) $replacement->secret_hash, hash('sha256', $replacementSecret)))->toBeTrue();

    $this->getJson('/apps/billing-api')->assertOk()->assertDontSee($replacementSecret);
    $this->deleteJson('/apps/billing-api/credential')->assertNoContent();
    expect($replacement->refresh()->revoked_at)->not->toBeNull();
});

it('refuses anonymous access to every app route', function (): void {
    ConnectedApp::query()->create(['id' => 'billing-api', 'name' => 'Billing API', 'enabled' => true]);

    foreach ([
        ['GET', '/apps'],
        ['POST', '/apps'],
        ['GET', '/apps/billing-api'],
        ['POST', '/apps/billing-api/credential/rotate'],
        ['DELETE', '/apps/billing-api/credential'],
    ] as [$method, $uri]) {
        $this->json($method, $uri)->assertUnauthorized();
    }
});

it('authenticates anonymous app detail and mutations before resolving app identity', function (): void {
    config()->set('app.debug', false);
    ConnectedApp::query()->create(['id' => 'billing-api', 'name' => 'Billing API', 'enabled' => true]);

    $this->app->bind(PreventRequestForgery::class, static fn (Application $app): PreventRequestForgery => new class($app, $app->make(Encrypter::class)) extends PreventRequestForgery
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    });

    $csrfToken = Str::random(40);
    $this->withSession(['_token' => $csrfToken])->withHeader('X-CSRF-TOKEN', $csrfToken);

    foreach ([
        ['GET', '/apps/billing-api', '/apps/missing-app'],
        ['POST', '/apps/billing-api/credential/rotate', '/apps/missing-app/credential/rotate'],
        ['DELETE', '/apps/billing-api/credential', '/apps/missing-app/credential'],
    ] as [$method, $existingUri, $missingUri]) {
        $existing = $this->json($method, $existingUri)->assertUnauthorized();
        $missing = $this->json($method, $missingUri)->assertUnauthorized();

        expect($missing->getContent())->toBe($existing->getContent());
    }
});

it('strictly validates unique app identity and keeps failed credential mint atomic', function (): void {
    loginWakeMember();

    foreach (['UPPER', '-leading', 'trailing-', 'two words'] as $id) {
        $this->postJson('/apps', ['id' => $id, 'name' => 'Invalid '.$id])->assertUnprocessable();
    }

    $this->postJson('/apps', ['id' => 'duplicate', 'name' => 'Duplicate'])->assertCreated();
    $this->postJson('/apps', ['id' => 'duplicate', 'name' => 'Another'])->assertUnprocessable();
    $this->postJson('/apps', ['id' => 'another', 'name' => 'Duplicate'])->assertUnprocessable();

    $beforeCredentials = Credential::query()->count();
    Event::listen('eloquent.creating: '.CredentialAuditEvent::class, static function (): never {
        throw new RuntimeException('forced audit failure');
    });

    try {
        expect(fn () => resolve(ConnectedAppCredentials::class)->create(
            'atomic-app',
            'Atomic app',
            AuditActor::boundUser((string) $this->user->getAuthIdentifier()),
        ))->toThrow(RuntimeException::class, 'forced audit failure');
    } finally {
        Event::forget('eloquent.creating: '.CredentialAuditEvent::class);
    }

    expect(ConnectedApp::query()->whereKey('atomic-app')->exists())->toBeFalse()
        ->and(Credential::query()->count())->toBe($beforeCredentials);
});

it('binds lifecycle operations to the exact app', function (): void {
    loginWakeMember();
    $alpha = $this->postJson('/apps', ['id' => 'alpha', 'name' => 'Alpha'])->assertCreated();
    $this->postJson('/apps', ['id' => 'beta', 'name' => 'Beta'])->assertCreated();
    $actor = AuditActor::boundUser((string) $this->user->getAuthIdentifier());

    expect(resolve(ConnectedAppCredentials::class)->rotate('beta', $alpha->json('credential_id'), $actor))->toBeNull()
        ->and(resolve(ConnectedAppCredentials::class)->revoke('beta', $alpha->json('credential_id'), $actor))->toBe(RevokeOutcome::NotFound);
});

it('returns a safe conflict when the admin rotate target moves after selection', function (): void {
    loginWakeMember();
    $created = $this->postJson('/apps', ['id' => 'billing-api', 'name' => 'Billing API'])->assertCreated();
    $credentialId = $created->json('credential_id');
    $movedSubject = 'moved-admin-subject';
    $sourceRetrievals = 0;
    $retargeted = false;
    $credentialsBefore = Credential::query()->count();
    $auditsBefore = CredentialAuditEvent::query()->count();

    Event::listen('eloquent.retrieved: '.Credential::class, static function (Credential $credential) use ($credentialId, $movedSubject, &$sourceRetrievals, &$retargeted): void {
        if ($credential->id !== $credentialId || ++$sourceRetrievals !== 2) {
            return;
        }

        Credential::query()->whereKey($credentialId)->update(['subject_ref' => $movedSubject]);
        $retargeted = true;
    });

    try {
        $response = $this->postJson('/apps/billing-api/credential/rotate');
    } finally {
        Event::forget('eloquent.retrieved: '.Credential::class);
    }

    $response->assertConflict()->assertJsonPath('message', 'The ingest credential changed during rotation.');
    expect($retargeted)->toBeTrue()
        ->and($response->getContent())->not->toContain($credentialId)
        ->not->toContain($movedSubject)
        ->not->toContain('Billing API ingest')
        ->and(Credential::query()->count())->toBe($credentialsBefore)
        ->and(CredentialAuditEvent::query()->count())->toBe($auditsBefore)
        ->and(Credential::query()->findOrFail($credentialId)->rotated_at)->toBeNull();
});

it('warns the admin when the target moves after the replacement commits', function (): void {
    loginWakeMember();
    $created = $this->postJson('/apps', ['id' => 'billing-api', 'name' => 'Billing API'])->assertCreated();
    $credentialId = $created->json('credential_id');
    $firstSecret = $created->json('secret');
    $movedSubject = 'moved-after-admin-commit';
    $retargeted = false;
    $credentialsBefore = Credential::query()->count();
    $auditsBefore = CredentialAuditEvent::query()->count();

    Event::listen('eloquent.created: '.CredentialAuditEvent::class, static function (CredentialAuditEvent $event) use ($credentialId, $movedSubject, &$retargeted): void {
        if ($event->event !== LifecycleEventType::Issued || $retargeted) {
            return;
        }

        Credential::query()->whereKey($credentialId)->update(['subject_ref' => $movedSubject]);
        $retargeted = true;
    });

    try {
        $response = $this->postJson('/apps/billing-api/credential/rotate');
    } finally {
        Event::forget('eloquent.created: '.CredentialAuditEvent::class);
    }

    $rotation = CredentialAuditEvent::query()
        ->where('credential_id', $credentialId)
        ->where('event', LifecycleEventType::Rotated)
        ->sole();
    $replacementId = $rotation->superseded_by_credential_id;
    $source = Credential::query()->findOrFail($credentialId);

    $response->assertConflict()->assertJsonPath(
        'message',
        'Rotation partially completed. The previous credential may remain active; review and revoke it before retrying.',
    );
    expect($retargeted)->toBeTrue()
        ->and($replacementId)->toBeString()
        ->and($response->getContent())->not->toContain($credentialId)
        ->not->toContain($replacementId)
        ->not->toContain($movedSubject)
        ->not->toContain('Billing API ingest')
        ->not->toContain($firstSecret)
        ->and(Credential::query()->count())->toBe($credentialsBefore + 1)
        ->and(CredentialAuditEvent::query()->count())->toBe($auditsBefore + 2)
        ->and($source->rotated_at)->not->toBeNull()
        ->and($source->revoked_at)->toBeNull()
        ->and($source->expires_at)->toBeNull()
        ->and(Credential::query()->whereKey($replacementId)->exists())->toBeTrue();
});

it('preserves admin failure semantics for an unrelated retirement failure', function (): void {
    config()->set('app.debug', false);
    loginWakeMember();
    $created = $this->postJson('/apps', ['id' => 'billing-api', 'name' => 'Billing API'])->assertCreated();
    $credentialId = $created->json('credential_id');
    $firstSecret = $created->json('secret');
    $retirementFailed = false;
    $credentialsBefore = Credential::query()->count();
    $auditsBefore = CredentialAuditEvent::query()->count();

    DB::connection()->beforeExecuting(static function (string $query, array $bindings) use ($credentialId, &$retirementFailed): void {
        if ($retirementFailed
            || ! str_contains($query, 'update "credentials"')
            || ! str_contains($query, '"expires_at"')
            || ! in_array($credentialId, $bindings, true)) {
            return;
        }

        $retirementFailed = true;

        throw new RuntimeException('forced retirement failure');
    });

    $response = $this->postJson('/apps/billing-api/credential/rotate');
    $rotation = CredentialAuditEvent::query()
        ->where('credential_id', $credentialId)
        ->where('event', LifecycleEventType::Rotated)
        ->sole();
    $replacementId = $rotation->superseded_by_credential_id;
    $source = Credential::query()->findOrFail($credentialId);

    $response->assertStatus(500)->assertJsonPath('message', 'Server Error');
    expect($retirementFailed)->toBeTrue()
        ->and($replacementId)->toBeString()
        ->and($response->getContent())->not->toContain('The ingest credential changed during rotation.')
        ->not->toContain('Rotation partially completed.')
        ->not->toContain($credentialId)
        ->not->toContain($replacementId)
        ->not->toContain('Billing API ingest')
        ->not->toContain($firstSecret)
        ->and(Credential::query()->count())->toBe($credentialsBefore + 1)
        ->and(CredentialAuditEvent::query()->count())->toBe($auditsBefore + 2)
        ->and($source->rotated_at)->not->toBeNull()
        ->and($source->revoked_at)->toBeNull()
        ->and($source->expires_at)->toBeNull()
        ->and(Credential::query()->whereKey($replacementId)->exists())->toBeTrue();
});
