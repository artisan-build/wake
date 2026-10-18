<?php

declare(strict_types=1);

use App\Authorization\McpAccess;
use App\Authorization\WakeCredentialAbility;
use App\Mcp\Tools\DeleteAppLogs;
use App\Mcp\Tools\RevokeCredential;
use App\Mcp\Tools\RotateCredential;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipalResolver;
use ArtisanBuild\BuiltForCloud\Console\Assertion;
use ArtisanBuild\BuiltForCloud\Console\ConsoleEntryRefusalReason;
use ArtisanBuild\BuiltForCloud\Console\ConsoleKeyring;
use ArtisanBuild\BuiltForCloud\Console\ConsoleRole;
use ArtisanBuild\BuiltForCloud\Console\DelegatedActor;
use ArtisanBuild\BuiltForCloud\Console\RequestAssertion;
use ArtisanBuild\BuiltForCloud\CredentialAuditEvent;
use ArtisanBuild\BuiltForCloud\Http\Middleware\AuthenticateMcp;
use ArtisanBuild\BuiltForCloud\LifecycleEventType;
use ArtisanBuild\BuiltForCloud\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response;
use ParagonIE\Paseto\Builder;
use ParagonIE\Paseto\Keys\Version4\AsymmetricSecretKey;
use ParagonIE\Paseto\Protocol\Version4;
use ParagonIE\Paseto\Purpose;

/** @return array{HttpRequest, DelegatedActor, ActingPrincipal} */
function publishWakeDelegatedRequest(string $subject): array
{
    $actor = DelegatedActor::query()->create([
        'identity_hash' => DelegatedActor::identityHash('wake-test', $subject),
        'issuer' => 'wake-test',
        'subject' => $subject,
        'last_handoff_display_name' => 'Wake Member',
        'last_handoff_role' => ConsoleRole::Member,
    ]);
    $request = HttpRequest::create('/mcp/delegated', 'POST');
    $principal = RequestAssertion::publish($request, $actor, Assertion::fromVerifiedClaims(
        issuer: 'wake-test',
        subject: $subject,
        displayName: 'Wake Member',
        role: ConsoleRole::Member,
        onBehalfOf: 'Wake tests',
        audience: 'wake-test',
        issuedAt: CarbonImmutable::now()->subSecond(),
        expiresAt: CarbonImmutable::now()->addMinute(),
        keyId: 'wake-key',
        id: (string) Str::uuid(),
    ));
    app()->instance('request', $request);

    return [$request, $actor, $principal];
}

function wakeDelegatedAssertion(string $issuer, string $audience, string $subject): string
{
    $secret = null;
    foreach (range(1, 16) as $ignored) {
        $candidate = AsymmetricSecretKey::generate(new Version4);
        if (strlen($candidate->raw()) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $secret = $candidate;
            break;
        }
    }
    if (! $secret instanceof AsymmetricSecretKey) {
        throw new RuntimeException('Could not generate a delegated assertion signing key.');
    }

    $keyId = 'wake-test-'.Str::lower((string) Str::ulid());
    app(ConsoleKeyring::class)->add($keyId, $secret->getPublicKey()->toHexString());
    app(ConsoleKeyring::class)->activate($keyId);
    $now = CarbonImmutable::now();

    return (new Builder)
        ->setVersion(new Version4)
        ->setPurpose(Purpose::public())
        ->setKey($secret)
        ->setClaims([
            'iss' => $issuer,
            'sub' => $subject,
            'aud' => $audience,
            'iat' => $now->toAtomString(),
            'nbf' => $now->toAtomString(),
            'exp' => $now->addSeconds(90)->toAtomString(),
            'jti' => 'wake-mint-'.Str::lower((string) Str::ulid()),
            'display_name' => 'Wake Member',
            'role' => ConsoleRole::Member->value,
            'purpose' => 'mcp',
        ])
        ->setFooterArray(['kid' => $keyId])
        ->toString();
}

it('keeps a delegated numeric identifier distinct from the same local user id', function (): void {
    $user = User::query()->create(['name' => 'Local collision', 'email' => 'collision@example.test']);
    $actor = new DelegatedActor;
    $actor->forceFill([
        'id' => $user->getKey(),
        'identity_hash' => DelegatedActor::identityHash('wake-test', 'collision'),
        'issuer' => 'wake-test',
        'subject' => 'collision',
        'last_handoff_display_name' => 'Delegated collision',
        'last_handoff_role' => ConsoleRole::Member,
    ])->save();
    $request = HttpRequest::create('/mcp/delegated', 'POST');
    RequestAssertion::publish($request, $actor, Assertion::fromVerifiedClaims(
        issuer: 'wake-test',
        subject: 'collision',
        displayName: 'Delegated collision',
        role: ConsoleRole::Member,
        onBehalfOf: null,
        audience: 'wake-test',
        issuedAt: CarbonImmutable::now()->subSecond(),
        expiresAt: CarbonImmutable::now()->addMinute(),
        keyId: 'wake-key',
        id: (string) Str::uuid(),
    ));
    app()->instance('request', $request);

    $principal = app(McpAccess::class)->authorize(WakeCredentialAbility::Read);

    expect($actor->getKey())->toBe($user->getKey())
        ->and($principal->principal?->is($actor))->toBeTrue()
        ->and($principal->identifier())->toBe('bfc-console:'.$user->getKey())
        ->and(app(ActingPrincipalResolver::class)->localUser($request))->toBeNull();
});

it('reaches the canonical principal resolver in both phases of every destructive tool', function (): void {
    [$request, , $expected] = publishWakeDelegatedRequest('destructive-phases');
    $phase = '';
    $observed = [];
    $request->attributes->set(
        ActingPrincipalResolver::OBSERVER_ATTRIBUTE,
        static function (ActingPrincipal $principal) use (&$phase, &$observed): void {
            $observed[$phase][] = [$principal->identifier(), $principal->delegated, $principal->role];
        },
    );

    foreach ([
        DeleteAppLogs::class => ['app' => 'missing'],
        RevokeCredential::class => ['app' => 'missing', 'credential_id' => 'missing'],
        RotateCredential::class => ['app' => 'missing', 'credential_id' => 'missing'],
    ] as $toolClass => $arguments) {
        $tool = app($toolClass);
        foreach (['preview', 'handle'] as $method) {
            $phase = $toolClass.'::'.$method;
            expect($tool->{$method}(new McpRequest($arguments)))->toBeInstanceOf(Response::class);
        }
    }

    expect(array_keys($observed))->toBe([
        DeleteAppLogs::class.'::preview',
        DeleteAppLogs::class.'::handle',
        RevokeCredential::class.'::preview',
        RevokeCredential::class.'::handle',
        RotateCredential::class.'::preview',
        RotateCredential::class.'::handle',
    ]);
    foreach ($observed as $observations) {
        expect($observations)->toBe([[$expected->identifier(), true, ConsoleRole::Member]]);
    }
});

it('refuses a deactivated delegated subject before Wake application work', function (): void {
    $issuer = 'https://scalpels.test';
    $audience = 'https://wake.test';
    $subject = 'offboarded-wake-member';
    config([
        'built-for-cloud.console.issuer' => $issuer,
        'built-for-cloud.console.audience' => $audience,
    ]);
    $token = wakeDelegatedAssertion($issuer, $audience, $subject);
    $actor = DelegatedActor::query()->create([
        'identity_hash' => DelegatedActor::identityHash($issuer, $subject),
        'issuer' => $issuer,
        'subject' => $subject,
        'last_handoff_display_name' => 'Offboarded Wake Member',
        'last_handoff_role' => ConsoleRole::Member,
    ]);
    $actor->deactivate();
    $wakeQueries = [];
    DB::listen(static function ($query) use (&$wakeQueries): void {
        if (str_contains($query->sql, 'wake_connected_apps')) {
            $wakeQueries[] = $query->sql;
        }
    });

    $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 'offboarded',
        'method' => 'tools/call',
        'params' => ['name' => 'list_apps', 'arguments' => ['all_apps' => true]],
    ])->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');

    expect($wakeQueries)->toBe([])
        ->and(CredentialAuditEvent::query()
            ->where('event', LifecycleEventType::DeniedAction)
            ->where('note', AuthenticateMcp::AUDIT_NOTE.ConsoleEntryRefusalReason::ActorDeactivated->value)
            ->exists())->toBeTrue();
});
