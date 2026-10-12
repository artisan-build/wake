<?php

declare(strict_types=1);

use App\Services\ConnectedAppCredentials;
use App\Services\IngestCredentialTarget;
use App\Services\WakeQueries;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\OperatorAbility;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\MintedTestCredential;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use ArtisanBuild\WakeContracts\IngestProtocol;
use ArtisanBuild\WakeServer\Models\ConnectedApp;
use ArtisanBuild\WakeServer\Models\StagingBatch;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(WithCredentials::class);

function universalConnectOperator(): MintedTestCredential
{
    return test()->mintCredential([
        'subject_type' => SubjectType::Operator,
        'purpose' => CredentialPurpose::OperatorManagement,
        'subject_ref' => 'universal-connect-operator',
        'abilities' => [OperatorAbility::CredentialMint->value],
    ]);
}

/** @return array<string, mixed> */
function universalConnectEnvelope(string $key): array
{
    return [
        'envelope_version' => 1,
        'client_version' => '1.2.3',
        'dropped_count' => 0,
        'idempotency_key' => $key,
        'rows' => [[
            'event_id' => (string) Str::ulid(),
            'occurred_at' => '2026-10-12T12:34:56.123456+00:00',
            'level' => 'info',
            'channel' => 'production',
            'message' => 'Universal connection probe',
            'context' => [],
            'extra' => [],
            'exception' => null,
            'environment' => 'production',
            'hostname' => 'web-1',
            'correlation_id' => null,
        ]],
    ];
}

it('provisions an app through the real credential endpoint and ingests without a manual app step', function (): void {
    $operator = universalConnectOperator();
    $subjectRef = 'Acme Billing Service (production) [env-a2f52d4e-a4e6-4a6a-96b4-a04ac506e881]';

    $minted = $this->postJson('/bfc/credentials', [
        'subject_type' => SubjectType::Installation->value,
        'subject_ref' => $subjectRef,
        'purpose' => CredentialPurpose::Consumption->value,
        'name' => 'Wake ingest',
    ], ['Authorization' => $operator->bearerHeader()])->assertCreated();

    $secret = $minted->json('delivery.secret');
    $credential = Credential::query()->findOrFail($minted->json('credential.id'));
    $app = ConnectedApp::query()->where('subject_ref', $subjectRef)->sole();
    $beforeIngest = resolve(WakeQueries::class)->ingestHealth(['app' => $app->id]);

    expect($secret)->toBeString()->not->toBe('')
        ->and($credential->subject_ref)->toBe($subjectRef)
        ->and($app->id)->toMatch('/^app-[0-7][0-9a-hjkmnp-tv-z]{25}$/')
        ->and($app->id)->not->toBe($subjectRef)
        ->and(Str::length((string) $app->name))->toBeLessThanOrEqual(100)
        ->and($app->name)->toEndWith(" [{$app->id}]")
        ->and(resolve(ConnectedAppCredentials::class)->current($app->id)?->is($credential))->toBeTrue()
        ->and(resolve(IngestCredentialTarget::class)->findForRotate($app->id, $credential->id)?->is($credential))->toBeTrue()
        ->and($beforeIngest['rows'])->toHaveCount(1)
        ->and($beforeIngest['rows'][0]['subject_ref'])->toBe($subjectRef)
        ->and($beforeIngest['rows'][0]['last_seen_at'])->toBeNull();

    $key = 'universal-connect-batch';
    $this->postJson(IngestProtocol::PATH, universalConnectEnvelope($key), [
        'Authorization' => 'Bearer '.$secret,
        IngestProtocol::IDEMPOTENCY_HEADER => $key,
    ])->assertAccepted()->assertJson([
        'accepted_count' => 1,
        'duplicate' => false,
    ]);

    $health = resolve(WakeQueries::class)->ingestHealth(['app' => $app->id]);

    expect(StagingBatch::query()->sole()->app_id)->toBe($app->id)
        ->and($health['rows'])->toHaveCount(1)
        ->and($health['rows'][0]['accepted_rows'])->toBe(1)
        ->and($health['rows'][0]['last_seen_at'])->not->toBeNull();
});

it('rolls back the credential when its connected app cannot be provisioned', function (): void {
    $operator = universalConnectOperator();
    $subjectRef = 'Atomic App (production) [env-failure]';
    $before = Credential::query()->count();

    Event::listen('eloquent.creating: '.ConnectedApp::class, static function (ConnectedApp $app) use ($subjectRef): void {
        if ($app->subject_ref === $subjectRef) {
            throw new RuntimeException('forced connected app failure');
        }
    });

    $this->withoutExceptionHandling();

    try {
        expect(fn () => $this->postJson('/bfc/credentials', [
            'subject_type' => SubjectType::Installation->value,
            'subject_ref' => $subjectRef,
            'purpose' => CredentialPurpose::Consumption->value,
        ], ['Authorization' => $operator->bearerHeader()]))
            ->toThrow(RuntimeException::class, 'forced connected app failure');
    } finally {
        Event::forget('eloquent.creating: '.ConnectedApp::class);
    }

    expect(Credential::query()->count())->toBe($before)
        ->and(Credential::query()->where('subject_ref', $subjectRef)->exists())->toBeFalse()
        ->and(ConnectedApp::query()->where('subject_ref', $subjectRef)->exists())->toBeFalse();
});
