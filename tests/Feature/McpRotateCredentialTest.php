<?php

declare(strict_types=1);

use App\Authorization\WakeCredentialAbility;
use App\Mcp\Tools\RotateCredential as RotateCredentialTool;
use App\Services\IngestCredentialTarget;
use ArtisanBuild\BuiltForCloud\AuditActorType;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialAuditEvent;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\LifecycleEventType;
use ArtisanBuild\BuiltForCloud\Mcp\TwoPhaseConfirmationRefused;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Request as McpRequest;

uses(WithCredentials::class);

/** @param array<string, mixed> $arguments */
function callRotateTool(string $bearer, array $arguments, string $path = '/mcp/destructive'): TestResponse
{
    return test()->withHeader('Authorization', $bearer)->postJson($path, [
        'jsonrpc' => '2.0', 'id' => 'rotate', 'method' => 'tools/call',
        'params' => ['name' => 'rotate_credential', 'arguments' => $arguments],
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-12T00:00:00Z');
    config()->set('built-for-cloud.console.audience', 'https://wake.test');
    config()->set('built-for-cloud.mcp.two_phase.cache_store', 'database');
    DB::table('wake_connected_apps')->insert(['id' => 'alpha', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    $this->actor = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp, 'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'rotate-actor', 'abilities' => [WakeCredentialAbility::Read->value],
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('previews secret-free then rotates with preserved binding and response-only bearer delivery', function (): void {
    $expiresAt = now()->addHours(2);
    $source = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'alpha',
        'name' => 'Alpha ingest',
        'abilities' => [WakeCredentialAbility::Read->value],
        'expires_at' => $expiresAt,
    ]);
    $arguments = ['app' => 'alpha', 'credential_id' => $source->credential->id];
    $auditBefore = CredentialAuditEvent::query()->where('credential_id', $source->credential->id)->count();
    $preview = callRotateTool($this->actor->bearerHeader(), $arguments)->assertOk();
    $confirmation = $preview->json('result._meta.two_phase.confirmation');
    expect($confirmation)->toBeString()
        ->and($source->credential->refresh()->rotated_at)->toBeNull()
        ->and(CredentialAuditEvent::query()->where('credential_id', $source->credential->id)->count())->toBe($auditBefore)
        ->and($preview->getContent())->not->toContain($source->plaintext())
        ->not->toContain($this->actor->plaintext());

    $confirmed = callRotateTool($this->actor->bearerHeader(), [...$arguments, 'confirm' => $confirmation])->assertOk();
    $payload = json_decode($confirmed->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
    $replacement = Credential::query()->findOrFail($payload['replacement_id']);
    $newSecret = $payload['bearer_secret'];

    expect($newSecret)->toBeString()->not->toBe('')
        ->and(substr_count($confirmed->getContent(), $newSecret))->toBe(1)
        ->and($confirmed->getContent())->not->toContain($source->plaintext())
        ->not->toContain($this->actor->plaintext())
        ->and(hash_equals((string) $replacement->secret_hash, hash('sha256', $newSecret)))->toBeTrue()
        ->and($replacement->purpose)->toBe(CredentialPurpose::Consumption)
        ->and($replacement->abilities)->toBe([WakeCredentialAbility::Read->value])
        ->and($replacement->subject_type)->toBe(SubjectType::Installation)
        ->and($replacement->subject_ref)->toBe('alpha')
        ->and($replacement->user_id)->toBeNull()
        ->and($replacement->name)->toBe('Alpha ingest')
        ->and($replacement->expires_at?->equalTo($expiresAt))->toBeTrue();

    $old = $source->credential->refresh();
    expect($old->rotated_at)->not->toBeNull()
        ->and($old->expires_at?->equalTo(now()->addHour()))->toBeTrue()
        ->and(resolve(IngestCredentialTarget::class)->findForRotate('alpha', $old->id))->toBeNull()
        ->and(resolve(IngestCredentialTarget::class)->findForRotate('alpha', $replacement->id)?->is($replacement))->toBeTrue();
    $events = CredentialAuditEvent::query()->whereIn('credential_id', [$old->id, $replacement->id])->get();
    expect($events->pluck('event')->all())->toContain(LifecycleEventType::Issued, LifecycleEventType::Rotated)
        ->and($events->where('actor_type', AuditActorType::OperatorIntegration)->pluck('actor_ref')->unique()->all())->toBe([(string) $this->actor->credential->getAuthIdentifier()])
        ->and($events->firstWhere('event', LifecycleEventType::Rotated)?->superseded_by_credential_id)->toBe($replacement->id)
        ->and(json_encode($events->map->getAttributes()->all(), JSON_THROW_ON_ERROR))->not->toContain($newSecret)
        ->not->toContain($source->plaintext())
        ->not->toContain($this->actor->plaintext())
        ->and(json_encode($replacement->getAttributes(), JSON_THROW_ON_ERROR))->not->toContain($newSecret);
});

it('refuses every ineligible rotate target before confirmation mutation audit or secret delivery', function (): void {
    DB::table('wake_connected_apps')->insert(['id' => 'beta', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    $user = User::query()->create(['name' => 'Bound rotate target', 'email' => 'bound-rotate@example.test']);
    $targets = [
        $this->mintCredential(['kind' => CredentialKind::Basic, 'purpose' => CredentialPurpose::Consumption, 'subject_type' => SubjectType::Installation, 'subject_ref' => 'alpha']),
        $this->mintCredential(['purpose' => CredentialPurpose::Mcp, 'subject_type' => SubjectType::Installation, 'subject_ref' => 'alpha', 'abilities' => [WakeCredentialAbility::Read->value]]),
        $this->mintCredential(['purpose' => CredentialPurpose::Consumption, 'subject_type' => SubjectType::Installation, 'subject_ref' => 'beta']),
        $this->mintCredential(['purpose' => CredentialPurpose::Consumption, 'subject_type' => SubjectType::ExternalConsumer, 'subject_ref' => 'alpha']),
        $this->mintCredential(['purpose' => CredentialPurpose::Consumption, 'subject_type' => SubjectType::Installation, 'subject_ref' => 'alpha', 'user_id' => (string) $user->getKey()]),
        $this->mintCredential(['purpose' => CredentialPurpose::Consumption, 'subject_type' => SubjectType::Installation, 'subject_ref' => 'alpha', 'revoked_at' => now()]),
        $this->mintCredential(['purpose' => CredentialPurpose::Consumption, 'subject_type' => SubjectType::Installation, 'subject_ref' => 'alpha', 'expires_at' => now()->subMinute()]),
        $this->mintCredential(['purpose' => CredentialPurpose::Consumption, 'subject_type' => SubjectType::Installation, 'subject_ref' => 'alpha']),
    ];
    Credential::query()->whereKey($targets[7]->credential->id)->update(['rotated_at' => now()]);
    $targetIds = array_map(static fn ($target): string => $target->credential->id, $targets);
    $auditBefore = CredentialAuditEvent::query()->whereIn('credential_id', $targetIds)->count();

    foreach ($targets as $target) {
        $response = callRotateTool($this->actor->bearerHeader(), ['app' => 'alpha', 'credential_id' => $target->credential->id])->assertOk();
        $response->assertJsonPath('result.isError', true);
        expect($response->json('result._meta.two_phase.confirmation'))->toBeNull()
            ->and($response->getContent())->not->toContain($target->plaintext())
            ->not->toContain($this->actor->plaintext());
    }
    foreach ([
        ['app' => 'missing', 'credential_id' => $targets[0]->credential->id],
        ['app' => 'alpha', 'credential_id' => 'malformed'],
        ['app' => 'alpha', 'credential_id' => (string) Str::uuid()],
    ] as $arguments) {
        $response = callRotateTool($this->actor->bearerHeader(), $arguments)->assertOk();
        $response->assertJsonPath('result.isError', true);
        expect($response->json('result._meta.two_phase.confirmation'))->toBeNull();
    }
    expect(CredentialAuditEvent::query()->whereIn('credential_id', $targetIds)->count())->toBe($auditBefore)
        ->and(Credential::query()->whereIn('id', $targetIds)->count())->toBe(count($targetIds));
});

it('enforces rotate authorization strict arguments effect binding and replay', function (): void {
    $target = $this->mintCredential(['purpose' => CredentialPurpose::Consumption, 'subject_type' => SubjectType::Installation, 'subject_ref' => 'alpha']);
    $other = $this->mintCredential(['purpose' => CredentialPurpose::Consumption, 'subject_type' => SubjectType::Installation, 'subject_ref' => 'alpha']);
    $arguments = ['app' => 'alpha', 'credential_id' => $target->credential->id];
    callRotateTool($this->actor->bearerHeader(), $arguments, '/mcp')->assertStatus(400)->assertJsonPath('error.message', 'effect_above_ceiling');
    callRotateTool($this->actor->bearerHeader(), [...$arguments, 'unknown' => 'value'])->assertOk()->assertJsonPath('result.isError', true);
    app()->instance('request', HttpRequest::create('/mcp', 'POST'));
    expect(fn () => (new RotateCredentialTool)->handle(new McpRequest(['unknown' => 'value'])))->toThrow(AuthorizationException::class);

    $preview = callRotateTool($this->actor->bearerHeader(), $arguments)->assertOk();
    $confirmation = $preview->json('result._meta.two_phase.confirmation');
    callRotateTool($this->actor->bearerHeader(), ['app' => 'alpha', 'credential_id' => $other->credential->id, 'confirm' => $confirmation])
        ->assertStatus(400)->assertJsonPath('error.message', TwoPhaseConfirmationRefused::MISMATCHED);
    $otherActor = $this->mintCredential(['purpose' => CredentialPurpose::Mcp, 'subject_type' => SubjectType::ExternalConsumer, 'subject_ref' => 'other-rotate', 'abilities' => [WakeCredentialAbility::Read->value]]);
    callRotateTool($otherActor->bearerHeader(), [...$arguments, 'confirm' => $confirmation])
        ->assertStatus(400)->assertJsonPath('error.message', TwoPhaseConfirmationRefused::MISMATCHED);
    callRotateTool($this->actor->bearerHeader(), [...$arguments, 'confirm' => $confirmation])->assertOk();
    callRotateTool($this->actor->bearerHeader(), [...$arguments, 'confirm' => $confirmation])
        ->assertStatus(400)->assertJsonPath('error.message', TwoPhaseConfirmationRefused::SPENT);
});

it('returns a safe MCP error when the rotate target moves after selection', function (): void {
    $source = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'alpha',
        'name' => 'Sensitive MCP ingest',
    ]);
    $arguments = ['app' => 'alpha', 'credential_id' => $source->credential->id];
    $preview = callRotateTool($this->actor->bearerHeader(), $arguments)->assertOk();
    $confirmation = $preview->json('result._meta.two_phase.confirmation');
    $movedSubject = 'moved-mcp-subject';
    $retargeted = false;
    $credentialsBefore = Credential::query()->count();
    $auditsBefore = CredentialAuditEvent::query()->count();

    Event::listen('eloquent.retrieved: '.Credential::class, static function (Credential $credential) use ($source, $movedSubject, &$retargeted): void {
        if ($credential->id !== $source->credential->id || $retargeted) {
            return;
        }

        Credential::query()->whereKey($credential->id)->update(['subject_ref' => $movedSubject]);
        $retargeted = true;
    });

    try {
        $response = callRotateTool($this->actor->bearerHeader(), [...$arguments, 'confirm' => $confirmation])->assertOk();
    } finally {
        Event::forget('eloquent.retrieved: '.Credential::class);
    }

    $response->assertJsonPath('result.isError', true);
    expect($retargeted)->toBeTrue()
        ->and($response->getContent())->toContain('The ingest credential changed during rotation.')
        ->not->toContain($source->credential->id)
        ->not->toContain($movedSubject)
        ->not->toContain('Sensitive MCP ingest')
        ->not->toContain($source->plaintext())
        ->and(Credential::query()->count())->toBe($credentialsBefore)
        ->and(CredentialAuditEvent::query()->count())->toBe($auditsBefore)
        ->and(Credential::query()->findOrFail($source->credential->id)->rotated_at)->toBeNull();
});

it('warns MCP when the target moves after the replacement commits', function (): void {
    $source = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'alpha',
        'name' => 'Sensitive MCP ingest',
    ]);
    $arguments = ['app' => 'alpha', 'credential_id' => $source->credential->id];
    $preview = callRotateTool($this->actor->bearerHeader(), $arguments)->assertOk();
    $confirmation = $preview->json('result._meta.two_phase.confirmation');
    $movedSubject = 'moved-after-mcp-commit';
    $retargeted = false;
    $credentialsBefore = Credential::query()->count();
    $auditsBefore = CredentialAuditEvent::query()->count();

    Event::listen('eloquent.created: '.CredentialAuditEvent::class, static function (CredentialAuditEvent $event) use ($source, $movedSubject, &$retargeted): void {
        if ($event->event !== LifecycleEventType::Issued || $retargeted) {
            return;
        }

        Credential::query()->whereKey($source->credential->id)->update(['subject_ref' => $movedSubject]);
        $retargeted = true;
    });

    try {
        $response = callRotateTool($this->actor->bearerHeader(), [...$arguments, 'confirm' => $confirmation])->assertOk();
    } finally {
        Event::forget('eloquent.created: '.CredentialAuditEvent::class);
    }

    $rotation = CredentialAuditEvent::query()
        ->where('credential_id', $source->credential->id)
        ->where('event', LifecycleEventType::Rotated)
        ->sole();
    $replacementId = $rotation->superseded_by_credential_id;
    $old = Credential::query()->findOrFail($source->credential->id);

    $response->assertJsonPath('result.isError', true);
    expect($retargeted)->toBeTrue()
        ->and($replacementId)->toBeString()
        ->and($response->getContent())->toContain('Rotation partially completed. The previous credential may remain active; review and revoke it before retrying.')
        ->not->toContain($source->credential->id)
        ->not->toContain($replacementId)
        ->not->toContain($movedSubject)
        ->not->toContain('Sensitive MCP ingest')
        ->not->toContain($source->plaintext())
        ->not->toContain($this->actor->plaintext())
        ->and(Credential::query()->count())->toBe($credentialsBefore + 1)
        ->and(CredentialAuditEvent::query()->count())->toBe($auditsBefore + 2)
        ->and($old->rotated_at)->not->toBeNull()
        ->and($old->revoked_at)->toBeNull()
        ->and($old->expires_at)->toBeNull()
        ->and(Credential::query()->whereKey($replacementId)->exists())->toBeTrue();
});

it('preserves MCP failure semantics for an unrelated retirement failure', function (): void {
    config()->set('app.debug', false);
    $source = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'alpha',
        'name' => 'Sensitive MCP ingest',
    ]);
    $arguments = ['app' => 'alpha', 'credential_id' => $source->credential->id];
    $preview = callRotateTool($this->actor->bearerHeader(), $arguments)->assertOk();
    $confirmation = $preview->json('result._meta.two_phase.confirmation');
    $retirementFailed = false;
    $credentialsBefore = Credential::query()->count();
    $auditsBefore = CredentialAuditEvent::query()->count();

    DB::connection()->beforeExecuting(static function (string $query, array $bindings) use ($source, &$retirementFailed): void {
        if ($retirementFailed
            || ! str_contains($query, 'update "credentials"')
            || ! str_contains($query, '"expires_at"')
            || ! in_array($source->credential->id, $bindings, true)) {
            return;
        }

        $retirementFailed = true;

        throw new RuntimeException('forced retirement failure');
    });

    $response = callRotateTool($this->actor->bearerHeader(), [...$arguments, 'confirm' => $confirmation])->assertOk();
    $rotation = CredentialAuditEvent::query()
        ->where('credential_id', $source->credential->id)
        ->where('event', LifecycleEventType::Rotated)
        ->sole();
    $replacementId = $rotation->superseded_by_credential_id;
    $old = Credential::query()->findOrFail($source->credential->id);

    $response->assertJsonPath('result.isError', true);
    expect($retirementFailed)->toBeTrue()
        ->and($replacementId)->toBeString()
        ->and($response->getContent())->toContain('An internal server error occurred.')
        ->not->toContain('The ingest credential changed during rotation.')
        ->not->toContain('Rotation partially completed.')
        ->not->toContain($source->credential->id)
        ->not->toContain($replacementId)
        ->not->toContain('Sensitive MCP ingest')
        ->not->toContain($source->plaintext())
        ->not->toContain($this->actor->plaintext())
        ->and(Credential::query()->count())->toBe($credentialsBefore + 1)
        ->and(CredentialAuditEvent::query()->count())->toBe($auditsBefore + 2)
        ->and($old->rotated_at)->not->toBeNull()
        ->and($old->revoked_at)->toBeNull()
        ->and($old->expires_at)->toBeNull()
        ->and(Credential::query()->whereKey($replacementId)->exists())->toBeTrue();
});
