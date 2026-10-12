<?php

declare(strict_types=1);

use App\Authorization\WakeCredentialAbility;
use ArtisanBuild\BuiltForCloud\AuditActorType;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialAuditEvent;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\LifecycleEventType;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(WithCredentials::class);

/** @param array<string, mixed> $arguments */
function callRotateTool(string $bearer, array $arguments): TestResponse
{
    return test()->withHeader('Authorization', $bearer)->postJson('/mcp/destructive', [
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
        ->and($preview->getContent())->not->toContain($source->plaintext(), $this->actor->plaintext());

    $confirmed = callRotateTool($this->actor->bearerHeader(), [...$arguments, 'confirm' => $confirmation])->assertOk();
    $payload = json_decode($confirmed->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
    $replacement = Credential::query()->findOrFail($payload['replacement_id']);
    $newSecret = $payload['bearer_secret'];

    expect($newSecret)->toBeString()->not->toBe('')
        ->and(substr_count($confirmed->getContent(), $newSecret))->toBe(1)
        ->and($confirmed->getContent())->not->toContain($source->plaintext(), $this->actor->plaintext())
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
        ->and($old->expires_at?->equalTo(now()->addHour()))->toBeTrue();
    $events = CredentialAuditEvent::query()->whereIn('credential_id', [$old->id, $replacement->id])->get();
    expect($events->pluck('event')->all())->toContain(LifecycleEventType::Issued, LifecycleEventType::Rotated)
        ->and($events->where('actor_type', AuditActorType::OperatorIntegration)->pluck('actor_ref')->unique()->all())->toBe([(string) $this->actor->credential->getAuthIdentifier()])
        ->and($events->firstWhere('event', LifecycleEventType::Rotated)?->superseded_by_credential_id)->toBe($replacement->id)
        ->and(json_encode($events->map->getAttributes()->all(), JSON_THROW_ON_ERROR))->not->toContain($newSecret, $source->plaintext(), $this->actor->plaintext())
        ->and(json_encode($replacement->getAttributes(), JSON_THROW_ON_ERROR))->not->toContain($newSecret);
});
