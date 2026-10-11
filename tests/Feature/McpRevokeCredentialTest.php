<?php

declare(strict_types=1);

use App\Authorization\WakeCredentialAbility;
use App\Services\IngestCredentialTarget;
use ArtisanBuild\BuiltForCloud\AuditActorType;
use ArtisanBuild\BuiltForCloud\CredentialAuditEvent;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\LifecycleEventType;
use ArtisanBuild\BuiltForCloud\Mcp\TwoPhaseConfirmationRefused;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\MintedTestCredential;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(WithCredentials::class);

/** @param array<string, mixed> $arguments */
function callRevokeTool(string $path, string $bearer, array $arguments): TestResponse
{
    return test()->withHeader('Authorization', $bearer)->postJson($path, [
        'jsonrpc' => '2.0', 'id' => 'revoke', 'method' => 'tools/call',
        'params' => ['name' => 'revoke_credential', 'arguments' => $arguments],
    ]);
}

beforeEach(function (): void {
    config()->set('built-for-cloud.console.audience', 'https://wake.test');
    config()->set('built-for-cloud.mcp.two_phase.cache_store', 'database');
    DB::table('wake_connected_apps')->insert(['id' => 'alpha', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('wake_connected_apps')->insert(['id' => 'beta', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    $this->actor = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp, 'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'revoke-actor', 'abilities' => [WakeCredentialAbility::Read->value],
    ]);
});

/** @param array<string, mixed> $overrides */
function mintIngestTarget(array $overrides = []): MintedTestCredential
{
    return test()->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'alpha',
        'abilities' => null,
        ...$overrides,
    ]);
}

it('selects only a current unbound installation ingest credential for the exact app', function (): void {
    $valid = mintIngestTarget();
    $wrongPurpose = mintIngestTarget(['purpose' => CredentialPurpose::Mcp, 'abilities' => [WakeCredentialAbility::Read->value]]);
    $otherApp = mintIngestTarget(['subject_ref' => 'beta']);
    $nonInstallation = mintIngestTarget(['subject_type' => SubjectType::ExternalConsumer]);
    $revoked = mintIngestTarget(['revoked_at' => now()]);
    $expired = mintIngestTarget(['expires_at' => now()->subMinute()]);
    $user = User::query()->create(['name' => 'Bound target', 'email' => 'bound-target@example.test']);
    $accountOwned = mintIngestTarget(['user_id' => (string) $user->getKey()]);
    $selector = resolve(IngestCredentialTarget::class);

    expect($selector->find('alpha', $valid->credential->id)?->is($valid->credential))->toBeTrue()
        ->and($selector->find('alpha', $wrongPurpose->credential->id))->toBeNull()
        ->and($selector->find('alpha', $otherApp->credential->id))->toBeNull()
        ->and($selector->find('alpha', $nonInstallation->credential->id))->toBeNull()
        ->and($selector->find('alpha', $revoked->credential->id))->toBeNull()
        ->and($selector->find('alpha', $expired->credential->id))->toBeNull()
        ->and($selector->find('alpha', $accountOwned->credential->id))->toBeNull()
        ->and($selector->find('alpha', 'missing'))->toBeNull();
});

it('previews without mutation then revokes through the unified action with attributed secret-free audit', function (): void {
    $target = mintIngestTarget();
    $arguments = ['app' => 'alpha', 'credential_id' => $target->credential->id];
    $preview = callRevokeTool('/mcp/destructive', $this->actor->bearerHeader(), $arguments)->assertOk();
    $confirmation = $preview->json('result._meta.two_phase.confirmation');
    expect($confirmation)->toBeString()
        ->and($target->credential->refresh()->revoked_at)->toBeNull()
        ->and(CredentialAuditEvent::query()->where('credential_id', $target->credential->id)->count())->toBe(0)
        ->and($preview->getContent())->not->toContain($target->plaintext(), $this->actor->plaintext());

    $confirmed = callRevokeTool('/mcp/destructive', $this->actor->bearerHeader(), [...$arguments, 'confirm' => $confirmation])->assertOk();
    expect($target->credential->refresh()->revoked_at)->not->toBeNull()
        ->and($confirmed->getContent())->not->toContain($target->plaintext(), $this->actor->plaintext());
    $audit = CredentialAuditEvent::query()->where('credential_id', $target->credential->id)->sole();
    expect($audit->event)->toBe(LifecycleEventType::Revoked)
        ->and($audit->actor_type)->toBe(AuditActorType::OperatorIntegration)
        ->and($audit->actor_ref)->toBe((string) $this->actor->credential->getAuthIdentifier())
        ->and(json_encode($audit->getAttributes(), JSON_THROW_ON_ERROR))->not->toContain($target->plaintext(), $this->actor->plaintext());
});

it('fails closed for invalid targets before confirmation or mutation', function (): void {
    $targets = [
        mintIngestTarget(['purpose' => CredentialPurpose::Mcp, 'abilities' => [WakeCredentialAbility::Read->value]]),
        mintIngestTarget(['subject_ref' => 'beta']),
        mintIngestTarget(['subject_type' => SubjectType::ExternalConsumer]),
        mintIngestTarget(['revoked_at' => now()]),
    ];
    $targetIds = array_map(static fn (MintedTestCredential $target): string => $target->credential->id, $targets);
    $auditCount = CredentialAuditEvent::query()->whereIn('credential_id', $targetIds)->count();
    foreach ($targets as $target) {
        $response = callRevokeTool('/mcp/destructive', $this->actor->bearerHeader(), ['app' => 'alpha', 'credential_id' => $target->credential->id])->assertOk();
        $response->assertJsonPath('result.isError', true);
        expect($response->json('result._meta.two_phase.confirmation'))->toBeNull();
    }
    expect(CredentialAuditEvent::query()->whereIn('credential_id', $targetIds)->count())->toBe($auditCount);
});

it('preserves effect ceiling argument subject and replay protections', function (): void {
    $target = mintIngestTarget();
    $otherTarget = mintIngestTarget();
    $arguments = ['app' => 'alpha', 'credential_id' => $target->credential->id];
    callRevokeTool('/mcp', $this->actor->bearerHeader(), $arguments)->assertStatus(400)->assertJsonPath('error.message', 'effect_above_ceiling');
    $preview = callRevokeTool('/mcp/destructive', $this->actor->bearerHeader(), $arguments)->assertOk();
    $confirmation = $preview->json('result._meta.two_phase.confirmation');
    callRevokeTool('/mcp/destructive', $this->actor->bearerHeader(), ['app' => 'alpha', 'credential_id' => $otherTarget->credential->id, 'confirm' => $confirmation])
        ->assertStatus(400)->assertJsonPath('error.message', TwoPhaseConfirmationRefused::MISMATCHED);
    $otherActor = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp, 'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'other-actor', 'abilities' => [WakeCredentialAbility::Read->value],
    ]);
    callRevokeTool('/mcp/destructive', $otherActor->bearerHeader(), [...$arguments, 'confirm' => $confirmation])
        ->assertStatus(400)->assertJsonPath('error.message', TwoPhaseConfirmationRefused::MISMATCHED);
    callRevokeTool('/mcp/destructive', $this->actor->bearerHeader(), [...$arguments, 'confirm' => $confirmation])->assertOk();
    callRevokeTool('/mcp/destructive', $this->actor->bearerHeader(), [...$arguments, 'confirm' => $confirmation])
        ->assertStatus(400)->assertJsonPath('error.message', TwoPhaseConfirmationRefused::SPENT);
});
