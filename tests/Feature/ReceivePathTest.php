<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\WakeContracts\IngestProtocol;
use ArtisanBuild\WakeContracts\IngestEnvelope;
use ArtisanBuild\WakeServer\Models\ConnectedApp;
use ArtisanBuild\WakeServer\Models\StagingBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function wakeConnectedApp(string $id, array $attributes = []): ConnectedApp
{
    return ConnectedApp::query()->create($attributes + ['id' => $id, 'enabled' => true]);
}

function wakeCredential(string $appId, string $secret, array $attributes = []): Credential
{
    return Credential::query()->create($attributes + [
        'kind' => CredentialKind::Bearer,
        'purpose' => CredentialPurpose::Consumption,
        'subject_type' => SubjectType::Installation,
        'subject_ref' => $appId,
        'name' => 'Wake ingest test credential',
        'status' => CredentialStatus::Active,
        'secret_hash' => hash('sha256', $secret),
    ]);
}

/** @return array<string, mixed> */
function wakeEnvelope(string $key, int $rows = 1, int $dropped = 0, string $clientVersion = '1.2.3'): array
{
    $events = [];

    for ($index = 0; $index < $rows; $index++) {
        $events[] = [
            'event_id' => (string) Str::ulid(),
            'occurred_at' => '2026-10-11T12:34:56.123456+00:00',
            'level' => 'error',
            'channel' => 'production',
            'message' => "Failure {$index}",
            'context' => ['order_id' => $index],
            'extra' => ['attempt' => 1],
            'exception' => null,
            'environment' => 'production',
            'hostname' => 'web-1',
            'correlation_id' => "req-{$index}",
        ];
    }

    return [
        'envelope_version' => 1,
        'client_version' => $clientVersion,
        'dropped_count' => $dropped,
        'idempotency_key' => $key,
        'rows' => $events,
    ];
}

/** @param array<string, mixed> $body */
function wakePost(object $test, string $secret, string $key, array $body): Illuminate\Testing\TestResponse
{
    return $test->postJson(IngestProtocol::PATH, $body, [
        'Authorization' => "Bearer {$secret}",
        IngestProtocol::IDEMPOTENCY_HEADER => $key,
    ]);
}

it('durably stages one-row and 500-row envelopes with credential-derived metadata', function (): void {
    $app = wakeConnectedApp('app-primary');
    wakeCredential($app->id, 'valid-ingest-secret');

    foreach ([1, 500] as $count) {
        $key = "batch-{$count}";
        $envelope = wakeEnvelope($key, $count, 7);

        $response = wakePost($this, 'valid-ingest-secret', $key, $envelope)
            ->assertAccepted()
            ->assertJson([
                'accepted_count' => $count,
                'duplicate' => false,
            ]);

        $batch = StagingBatch::query()->where('idempotency_key', $key)->sole();

        expect((string) $response->json('batch_id'))->toMatch('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/')
            ->and($batch->batch_id)->toBe($response->json('batch_id'))
            ->and($batch->app_id)->toBe($app->id)
            ->and($batch->envelope_version)->toBe(1)
            ->and($batch->client_version)->toBe('1.2.3')
            ->and($batch->dropped_count)->toBe(7)
            ->and($batch->row_count)->toBe($count)
            ->and($batch->payload)->toEqual(IngestEnvelope::fromArray($envelope)->toArray())
            ->and($batch->status->value)->toBe('pending');
    }
});

it('returns the original receipt for a changed replay without consuming counters twice', function (): void {
    $app = wakeConnectedApp('app-replay');
    wakeCredential($app->id, 'replay-secret');
    $key = 'same-key';

    $first = wakePost($this, 'replay-secret', $key, wakeEnvelope($key, 2, 4))
        ->assertAccepted()
        ->assertJson(['accepted_count' => 2, 'duplicate' => false]);

    $changed = wakeEnvelope($key, 1, 999, '9.9.9');
    $changed['rows'][0]['message'] = 'A different valid body';

    wakePost($this, 'replay-secret', $key, $changed)
        ->assertAccepted()
        ->assertJson([
            'batch_id' => $first->json('batch_id'),
            'accepted_count' => 2,
            'duplicate' => true,
        ]);

    $app->refresh();
    expect(StagingBatch::query()->where('app_id', $app->id)->count())->toBe(1)
        ->and(DB::table('wake_ingest_windows')->where('app_id', $app->id)->value('batch_count'))->toBe(1)
        ->and(DB::table('wake_ingest_windows')->where('app_id', $app->id)->value('event_count'))->toBe(2)
        ->and($app->accepted_rows)->toBe(2)
        ->and($app->dropped_rows)->toBe(4)
        ->and($app->rejected_batches)->toBe(0);
});

it('returns 401 for missing unknown revoked and concurrently revoked credentials', function (): void {
    $app = wakeConnectedApp('app-unauthorized');
    wakeCredential($app->id, 'revoked-secret', ['revoked_at' => now()]);
    $key = 'unauthorized-key';
    $body = wakeEnvelope($key);

    $this->postJson(IngestProtocol::PATH, $body, [IngestProtocol::IDEMPOTENCY_HEADER => $key])
        ->assertUnauthorized()->assertJsonPath('error', 'unauthorized');
    wakePost($this, 'unknown-secret', $key, $body)
        ->assertUnauthorized()->assertJsonPath('error', 'unauthorized');
    wakePost($this, 'revoked-secret', $key, $body)
        ->assertUnauthorized()->assertJsonPath('error', 'unauthorized');

    $credential = wakeCredential($app->id, 'racing-secret');
    $armed = true;
    DB::listen(function ($query) use (&$armed, $credential): void {
        if ($armed && str_contains(strtolower($query->sql), 'from "credentials"')) {
            $armed = false;
            DB::table('credentials')->where('id', $credential->id)->update(['revoked_at' => now()]);
        }
    });

    wakePost($this, 'racing-secret', $key, $body)
        ->assertUnauthorized()->assertJsonPath('error', 'unauthorized');

    expect(StagingBatch::query()->count())->toBe(0);
});

it('returns 403 for valid credentials with disallowed purpose ownership subject or app state', function (): void {
    $body = wakeEnvelope('forbidden-key');

    $cases = [
        ['wrong-purpose', ['purpose' => CredentialPurpose::Mcp], true, true],
        ['account-bound', ['user_id' => 'user-1'], true, true],
        ['wrong-subject', ['subject_type' => SubjectType::ExternalConsumer], true, true],
        ['missing-app', [], false, true],
        ['disabled-app', [], true, false],
    ];

    foreach ($cases as $index => [$name, $attributes, $createApp, $enabled]) {
        $appId = "forbidden-{$index}";
        if ($createApp) {
            wakeConnectedApp($appId, ['enabled' => $enabled]);
        }
        wakeCredential($appId, "secret-{$index}", $attributes);

        wakePost($this, "secret-{$index}", 'forbidden-key', $body)
            ->assertForbidden()
            ->assertJsonPath('error', 'forbidden');
    }

    expect(StagingBatch::query()->count())->toBe(0);
});

it('rejects forged app fields and only stages rows for the credential app', function (): void {
    $first = wakeConnectedApp('credential-app');
    $second = wakeConnectedApp('forged-app');
    wakeCredential($first->id, 'identity-secret');

    foreach (['app', 'app_id', 'application_id'] as $field) {
        $key = "forged-{$field}";
        $body = wakeEnvelope($key);
        $body[$field] = $second->id;

        wakePost($this, 'identity-secret', $key, $body)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'validation_failed');
    }

    wakePost($this, 'identity-secret', 'valid-identity', wakeEnvelope('valid-identity'))
        ->assertAccepted();

    expect(StagingBatch::query()->count())->toBe(1)
        ->and(StagingBatch::query()->sole()->app_id)->toBe($first->id)
        ->and(StagingBatch::query()->where('app_id', $second->id)->exists())->toBeFalse();
});

it('enforces body json idempotency contract and envelope version boundaries', function (): void {
    $app = wakeConnectedApp('app-validation');
    wakeCredential($app->id, 'validation-secret');
    $server = [
        'HTTP_AUTHORIZATION' => 'Bearer validation-secret',
        'HTTP_IDEMPOTENCY_KEY' => 'validation-key',
        'CONTENT_TYPE' => 'application/json',
    ];

    $this->call('POST', IngestProtocol::PATH, [], [], [], $server, str_repeat('x', IngestProtocol::MAX_REQUEST_BYTES + 1))
        ->assertStatus(413)->assertJsonPath('error', 'payload_too_large');
    $this->call('POST', IngestProtocol::PATH, [], [], [], $server, '{broken')
        ->assertUnprocessable()->assertJsonPath('error', 'validation_failed');

    wakePost($this, 'validation-secret', 'header-key', wakeEnvelope('body-key'))
        ->assertUnprocessable()->assertJsonPath('details.path', IngestProtocol::IDEMPOTENCY_HEADER);

    $invalid = wakeEnvelope('validation-key');
    $invalid['rows'][0]['level'] = 'verbose';
    wakePost($this, 'validation-secret', 'validation-key', $invalid)
        ->assertUnprocessable()->assertJsonPath('error', 'validation_failed');

    $newer = wakeEnvelope('newer-key');
    $newer['envelope_version'] = 2;
    wakePost($this, 'validation-secret', 'newer-key', $newer)
        ->assertUnprocessable()
        ->assertJsonPath('error', 'unsupported_envelope_version')
        ->assertJsonPath('message', 'envelope_version: received envelope version 2; this server supports versions through 1');

    wakePost($this, 'validation-secret', 'supported-key', wakeEnvelope('supported-key'))
        ->assertAccepted();
});

it('applies independent per-app batch and event caps and lets accepted replays bypass them', function (): void {
    $batchApp = wakeConnectedApp('batch-capped', ['batches_per_minute' => 1, 'events_per_minute' => 10]);
    $eventApp = wakeConnectedApp('event-capped', ['batches_per_minute' => 10, 'events_per_minute' => 2]);
    wakeCredential($batchApp->id, 'batch-cap-secret');
    wakeCredential($eventApp->id, 'event-cap-secret');

    $accepted = wakePost($this, 'batch-cap-secret', 'batch-first', wakeEnvelope('batch-first'))
        ->assertAccepted();

    $limited = wakePost($this, 'batch-cap-secret', 'batch-second', wakeEnvelope('batch-second'))
        ->assertStatus(429)
        ->assertJsonPath('error', 'rate_limited');
    expect((int) $limited->headers->get('Retry-After'))->toBeGreaterThan(0)
        ->and((int) $limited->headers->get('Retry-After'))->toBe($limited->json('retry_after'));

    wakePost($this, 'batch-cap-secret', 'batch-first', wakeEnvelope('batch-first', 2, 99))
        ->assertAccepted()
        ->assertJson([
            'batch_id' => $accepted->json('batch_id'),
            'accepted_count' => 1,
            'duplicate' => true,
        ]);

    wakePost($this, 'event-cap-secret', 'event-first', wakeEnvelope('event-first', 2))->assertAccepted();
    wakePost($this, 'event-cap-secret', 'event-second', wakeEnvelope('event-second'))
        ->assertStatus(429);

    $batchApp->refresh();
    $eventApp->refresh();
    expect($batchApp->rate_limit_hits)->toBe(1)
        ->and($batchApp->rejected_batches)->toBe(1)
        ->and($eventApp->rate_limit_hits)->toBe(1)
        ->and($eventApp->rejected_batches)->toBe(1)
        ->and(StagingBatch::query()->count())->toBe(2);
});

it('updates ingest health once without persisting authorization material', function (): void {
    $app = wakeConnectedApp('health-app');
    $secret = 'health-secret-never-persist';
    wakeCredential($app->id, $secret);

    wakePost($this, $secret, 'health-key', wakeEnvelope('health-key', 3, 8, '4.5.6'))
        ->assertAccepted();

    $app->refresh();
    $batch = StagingBatch::query()->sole();
    $wakeRows = collect(['wake_connected_apps', 'wake_staging_batches', 'wake_ingest_windows'])
        ->flatMap(fn (string $table): array => DB::table($table)->get()->map(fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR))->all());

    expect($app->accepted_rows)->toBe(3)
        ->and($app->dropped_rows)->toBe(8)
        ->and($app->last_seen_at)->not->toBeNull()
        ->and($app->last_client_version)->toBe('4.5.6')
        ->and($batch->payload['client_version'])->toBe('4.5.6')
        ->and($wakeRows->contains(fn (string $row): bool => str_contains($row, $secret)))->toBeFalse()
        ->and($wakeRows->contains(fn (string $row): bool => str_contains(strtolower($row), 'authorization')))->toBeFalse();
});
