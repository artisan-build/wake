<?php

declare(strict_types=1);

use App\Authorization\WakeCredentialAbility;
use App\Mcp\Tools\RevokeCredential as RevokeCredentialTool;
use App\Services\IngestCredentialTarget;
use ArtisanBuild\BuiltForCloud\Actions\RevokeCredential as RevokeCredentialAction;
use ArtisanBuild\BuiltForCloud\Actions\RotateCredential as RotateCredentialAction;
use ArtisanBuild\BuiltForCloud\AuditActorType;
use ArtisanBuild\BuiltForCloud\Auth\CredentialResolver;
use ArtisanBuild\BuiltForCloud\CredentialAuditEvent;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\LifecycleEventType;
use ArtisanBuild\BuiltForCloud\Mcp\TwoPhaseConfirmationRefused;
use ArtisanBuild\BuiltForCloud\RotateOptions;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\MintedTestCredential;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use ArtisanBuild\BuiltForCloud\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Request as McpRequest;

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

    expect($selector->findForRevoke('alpha', $valid->credential->id)?->is($valid->credential))->toBeTrue()
        ->and($selector->findForRevoke('alpha', $wrongPurpose->credential->id))->toBeNull()
        ->and($selector->findForRevoke('alpha', $otherApp->credential->id))->toBeNull()
        ->and($selector->findForRevoke('alpha', $nonInstallation->credential->id))->toBeNull()
        ->and($selector->findForRevoke('alpha', $revoked->credential->id))->toBeNull()
        ->and($selector->findForRevoke('alpha', $expired->credential->id))->toBeNull()
        ->and($selector->findForRevoke('alpha', $accountOwned->credential->id))->toBeNull()
        ->and($selector->findForRevoke('alpha', 'missing'))->toBeNull();
});

it('previews without mutation then revokes through the unified action with attributed secret-free audit', function (): void {
    $target = mintIngestTarget();
    $arguments = ['app' => 'alpha', 'credential_id' => $target->credential->id];
    $preview = callRevokeTool('/mcp/destructive', $this->actor->bearerHeader(), $arguments)->assertOk();
    $confirmation = $preview->json('result._meta.two_phase.confirmation');
    expect($confirmation)->toBeString()
        ->and($target->credential->refresh()->revoked_at)->toBeNull()
        ->and(CredentialAuditEvent::query()->where('credential_id', $target->credential->id)->count())->toBe(0)
        ->and($preview->getContent())->not->toContain($target->plaintext())
        ->not->toContain($this->actor->plaintext());

    $confirmed = callRevokeTool('/mcp/destructive', $this->actor->bearerHeader(), [...$arguments, 'confirm' => $confirmation])->assertOk();
    expect($target->credential->refresh()->revoked_at)->not->toBeNull()
        ->and($confirmed->getContent())->not->toContain($target->plaintext())
        ->not->toContain($this->actor->plaintext());
    $audit = CredentialAuditEvent::query()->where('credential_id', $target->credential->id)->sole();
    expect($audit->event)->toBe(LifecycleEventType::Revoked)
        ->and($audit->actor_type)->toBe(AuditActorType::OperatorIntegration)
        ->and($audit->actor_ref)->toBe((string) $this->actor->credential->getAuthIdentifier())
        ->and(json_encode($audit->getAttributes(), JSON_THROW_ON_ERROR))->not->toContain($target->plaintext())
        ->not->toContain($this->actor->plaintext());
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

it('revokes a superseded bearer that still authenticates during rotation grace', function (): void {
    $source = mintIngestTarget();
    $rotation = resolve(RotateCredentialAction::class)(
        $source->credential->id,
        new RotateOptions,
    );
    expect($rotation)->not->toBeNull()
        ->and($source->credential->refresh()->rotated_at)->not->toBeNull()
        ->and(resolve(IngestCredentialTarget::class)->findForRotate('alpha', $source->credential->id))->toBeNull()
        ->and(resolve(IngestCredentialTarget::class)->findForRevoke('alpha', $source->credential->id)?->is($source->credential))->toBeTrue()
        ->and(resolve(CredentialResolver::class)->resolve(CredentialKind::Bearer, $source->plaintext())?->is($source->credential))->toBeTrue();

    $arguments = ['app' => 'alpha', 'credential_id' => $source->credential->id];
    $preview = callRevokeTool('/mcp/destructive', $this->actor->bearerHeader(), $arguments)->assertOk();
    $confirmation = $preview->json('result._meta.two_phase.confirmation');
    callRevokeTool('/mcp/destructive', $this->actor->bearerHeader(), [...$arguments, 'confirm' => $confirmation])
        ->assertOk()->assertDontSee($source->plaintext());

    expect($source->credential->refresh()->revoked_at)->not->toBeNull()
        ->and(resolve(CredentialResolver::class)->resolve(CredentialKind::Bearer, $source->plaintext()))->toBeNull()
        ->and(CredentialAuditEvent::query()->where('credential_id', $source->credential->id)->where('event', LifecycleEventType::Revoked)->count())->toBe(1);
});

it('authorizes revoke before validation or selector and action resolution', function (): void {
    app()->instance('request', HttpRequest::create('/mcp', 'POST'));
    app()->bind(IngestCredentialTarget::class, static fn (): never => throw new LogicException('Selector resolved before authorization.'));
    app()->bind(RevokeCredentialAction::class, static fn (): never => throw new LogicException('Action resolved before authorization.'));

    expect(fn () => (new RevokeCredentialTool)->handle(new McpRequest(['unknown' => 'value'])))
        ->toThrow(AuthorizationException::class);
});
