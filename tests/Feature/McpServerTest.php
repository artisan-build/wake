<?php

declare(strict_types=1);

use App\Authorization\WakeCredentialAbility;
use App\Mcp\WakeMcpServer;
use ArtisanBuild\BuiltForCloud\Console\Assertion;
use ArtisanBuild\BuiltForCloud\Console\ConsoleRole;
use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use ArtisanBuild\BuiltForCloud\Console\RequestAssertion;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\Mcp\RequestEffectCeiling;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\CredentialAbilityAssertions;
use ArtisanBuild\BuiltForCloud\Testing\McpDelegatedTools;
use ArtisanBuild\BuiltForCloud\Testing\McpProductAdmission;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Transport\FakeTransporter;

uses(WithCredentials::class);

it('registers the exact effect-scoped routes and Wake ability', function (): void {
    $read = Mcp::getWebServer('mcp');
    $destructive = Mcp::getWebServer('mcp/destructive');

    expect($read)->not->toBeNull()
        ->and($destructive)->not->toBeNull()
        ->and($read?->gatherMiddleware())->toContain('bfc.mcp:product,read')
        ->and($destructive?->gatherMiddleware())->toContain('bfc.mcp:product,destructive')
        ->and($read?->gatherMiddleware())->not->toContain('bfc.ability:mcp:read')
        ->not->toContain('bfc.ability:mcp:admin')
        ->and($destructive?->gatherMiddleware())->not->toContain('bfc.ability:mcp:read')
        ->not->toContain('bfc.ability:mcp:admin')
        ->and(config('built-for-cloud.mcp.path'))->toBe('/mcp')
        ->and(config('built-for-cloud.mcp.write_path'))->toBeNull()
        ->and(config('built-for-cloud.mcp.destructive_path'))->toBe('/mcp/destructive')
        ->and(config('built-for-cloud.mcp.delegated'))->toBeTrue()
        ->and(Mcp::getLocalServer('wake'))->toBeNull();

    CredentialAbilityAssertions::assertRegistered(app(), WakeCredentialAbility::Read->value);
});

it('conforms and lists exactly the ten classified tools', function (): void {
    $credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'wake-mcp-conformance',
        'abilities' => [WakeCredentialAbility::Read->value],
    ]);

    $response = $this->withHeader('Authorization', $credential->bearerHeader())->postJson('/mcp/destructive', [
        'jsonrpc' => '2.0',
        'id' => 'tools',
        'method' => 'tools/list',
    ])->assertOk();

    $tools = collect($response->json('result.tools'))->keyBy('name');
    $expected = [
        'aggregate_logs' => ['metadata', 'read'],
        'delete_app_logs' => ['content', 'destructive'],
        'get_context' => ['content', 'read'],
        'ingest_health' => ['metadata', 'read'],
        'list_apps' => ['metadata', 'read'],
        'recent_errors' => ['content', 'read'],
        'revoke_credential' => ['metadata', 'destructive'],
        'rotate_credential' => ['metadata', 'destructive'],
        'search_logs' => ['content', 'read'],
        'tail_logs' => ['content', 'read'],
    ];

    expect($tools->keys()->sort()->values()->all())->toBe(array_keys($expected));

    foreach ($expected as $name => [$classification, $effect]) {
        expect($tools[$name]['_meta']['classification'])->toBe($classification)
            ->and($tools[$name]['_meta']['effect'])->toBe($effect)
            ->and($tools[$name]['inputSchema']['additionalProperties'])->toBeFalse()
            ->and($tools[$name]['annotations'])->not->toBe([]);
    }

    foreach (['delete_app_logs', 'revoke_credential', 'rotate_credential'] as $name) {
        expect($tools[$name]['_meta']['two_phase'])->toMatchArray([
            'confirmationArgument' => 'confirm',
            'protocolVersion' => 1,
        ]);
    }

    $readTools = $this->withHeader('Authorization', $credential->bearerHeader())->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 'read-tools',
        'method' => 'tools/list',
    ])->assertOk()->json('result.tools.*.name');

    expect($readTools)->toHaveCount(7)
        ->not->toContain('delete_app_logs')
        ->not->toContain('revoke_credential')
        ->not->toContain('rotate_credential');

    $this->withHeader('Authorization', $credential->bearerHeader())->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 'read-refusal',
        'method' => 'tools/call',
        'params' => ['name' => 'delete_app_logs', 'arguments' => ['app' => 'example']],
    ])->assertStatus(400)->assertJsonPath('error.message', 'effect_above_ceiling');

    McpDelegatedTools::assertConforms(WakeMcpServer::class);
});

it('requires the exact Wake ability and rejects ingest credentials on both doors', function (): void {
    $allowed = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'wake-reader',
        'abilities' => [WakeCredentialAbility::Read->value],
    ]);
    $missing = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'wake-no-ability',
        'abilities' => [],
    ]);
    $ingest = $this->mintCredential([
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => 'wake-ingest',
        'abilities' => null,
    ]);

    foreach (['/mcp', '/mcp/destructive'] as $path) {
        $this->withHeader('Authorization', $allowed->bearerHeader())->postJson($path, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
        ])->assertOk();
        $this->withHeader('Authorization', $missing->bearerHeader())->postJson($path, [
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list',
        ])->assertOk()->assertJsonPath('result.tools', []);
        $this->withHeader('Authorization', $ingest->bearerHeader())->postJson($path, [
            'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list',
        ])->assertUnauthorized();
    }
});

it('grants all ten tools to a verified delegated person', function (): void {
    $actor = DelegatedActor::query()->create([
        'identity_hash' => DelegatedActor::identityHash('wake-test', 'member'),
        'issuer' => 'wake-test', 'subject' => 'member', 'last_handoff_display_name' => 'Wake Member',
        'last_handoff_role' => ConsoleRole::Member,
    ]);
    $request = Request::create('/mcp/delegated');
    RequestAssertion::publish($request, $actor, Assertion::fromVerifiedClaims(
        issuer: 'wake-test', subject: 'member', displayName: 'Wake Member', role: ConsoleRole::Member,
        onBehalfOf: 'Wake tests', audience: 'wake-test', issuedAt: CarbonImmutable::now()->subSecond(),
        expiresAt: CarbonImmutable::now()->addMinute(), keyId: 'wake-key', id: (string) Str::uuid(),
    ));
    app()->instance('request', $request);
    RequestEffectCeiling::publish($request, 'destructive');

    $server = app()->make(WakeMcpServer::class, ['transport' => new FakeTransporter]);
    $server->start();

    expect($server->createContext()->tools())->toHaveCount(10);
});

it('passes Built for Cloud product admission conformance', function (): void {
    McpProductAdmission::assert();
});
