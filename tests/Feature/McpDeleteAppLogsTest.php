<?php

declare(strict_types=1);

use App\Authorization\WakeCredentialAbility;
use App\Mcp\Tools\DeleteAppLogs as DeleteAppLogsTool;
use App\Services\DeleteAppLogs;
use ArtisanBuild\BuiltForCloud\Audit\AppActionEvent;
use ArtisanBuild\BuiltForCloud\Audit\AppActorType;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\Mcp\TwoPhaseConfirmationRefused;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\WithCredentials;
use ArtisanBuild\WakeServer\Lake\LakeAppDeletion;
use ArtisanBuild\WakeServer\Lake\LakeWriterLock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Request as McpRequest;

uses(WithCredentials::class);

final class DeleteToolLake implements LakeAppDeletion
{
    public int $rows = 2;

    public function countAppRows(string $appId): int
    {
        return $this->rows;
    }

    public function deleteAppRows(string $appId): int
    {
        $rows = $this->rows;
        $this->rows = 0;

        return $rows;
    }

    public function expireSnapshots(DateTimeInterface $through): void {}

    public function cleanupFiles(): void {}
}

final class DeleteToolLock implements LakeWriterLock
{
    public function execute(Closure $operation): bool
    {
        $operation();

        return true;
    }
}

/** @param array<string, mixed> $arguments */
function callDeleteTool(string $path, string $bearer, array $arguments): TestResponse
{
    return test()->withHeader('Authorization', $bearer)->postJson($path, [
        'jsonrpc' => '2.0', 'id' => 'delete', 'method' => 'tools/call',
        'params' => ['name' => 'delete_app_logs', 'arguments' => $arguments],
    ]);
}

beforeEach(function (): void {
    config()->set('built-for-cloud.console.audience', 'https://wake.test');
    config()->set('built-for-cloud.mcp.two_phase.cache_store', 'database');
    $this->lake = new DeleteToolLake;
    app()->instance(LakeAppDeletion::class, $this->lake);
    app()->instance(LakeWriterLock::class, new DeleteToolLock);
    $this->credential = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp,
        'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'delete-tool',
        'abilities' => [WakeCredentialAbility::Read->value],
    ]);
    DB::table('wake_connected_apps')->insert([
        'id' => 'alpha', 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('wake_event_locators')->insert([
        'event_id' => (string) Str::ulid(), 'app_id' => 'alpha', 'event_date' => '2026-10-01', 'occurred_at' => '2026-10-01 00:00:00+00',
    ]);
});

it('previews exactly without mutation then confirms deletion with bounded attributed audit', function (): void {
    DB::table('wake_error_events')->insert([
        'event_id' => (string) Str::ulid(), 'app_id' => 'alpha', 'event_date' => '2026-10-01', 'occurred_at' => '2026-10-01 00:00:00+00',
        'level' => 'error', 'channel' => 'app', 'message' => 'SECRETLOGCANARY', 'context' => '{}', 'extra' => '{}',
        'exception' => null, 'environment' => 'testing', 'hostname' => 'host', 'correlation_id' => null,
    ]);
    $preview = callDeleteTool('/mcp/destructive', $this->credential->bearerHeader(), ['app' => 'alpha'])->assertOk();
    $confirmation = $preview->json('result._meta.two_phase.confirmation');
    expect($confirmation)->toBeString()
        ->and(json_decode($preview->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR))->toMatchArray(['lake_rows' => 2, 'locators' => 1])
        ->and($this->lake->rows)->toBe(2)
        ->and(DB::table('wake_event_locators')->count())->toBe(1)
        ->and(AppActionEvent::query()->count())->toBe(0);

    $confirmed = callDeleteTool('/mcp/destructive', $this->credential->bearerHeader(), ['app' => 'alpha', 'confirm' => $confirmation])->assertOk();
    expect(json_decode($confirmed->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR))->toMatchArray(['lake_rows' => 2, 'locators' => 1])
        ->and($confirmed->getContent())->not->toContain('SECRETLOGCANARY', $this->credential->plaintext())
        ->and($this->lake->rows)->toBe(0)
        ->and(DB::table('wake_event_locators')->count())->toBe(0);
    $audit = AppActionEvent::query()->sole();
    expect($audit->action)->toBe('app-logs-deleted')
        ->and($audit->actor_type)->toBe(AppActorType::ApiToken)
        ->and($audit->actor_ref)->toBe((string) $this->credential->credential->getAuthIdentifier())
        ->and(json_encode($audit->getAttributes(), JSON_THROW_ON_ERROR))->not->toContain('alpha', 'SECRETLOGCANARY');
});

it('binds confirmations to exact arguments and acting subject and refuses replay', function (): void {
    DB::table('wake_connected_apps')->insert(['id' => 'beta', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    $other = $this->mintCredential([
        'purpose' => CredentialPurpose::Mcp, 'subject_type' => SubjectType::ExternalConsumer,
        'subject_ref' => 'other-delete-tool', 'abilities' => [WakeCredentialAbility::Read->value],
    ]);
    $preview = callDeleteTool('/mcp/destructive', $this->credential->bearerHeader(), ['app' => 'alpha'])->assertOk();
    $confirmation = $preview->json('result._meta.two_phase.confirmation');

    callDeleteTool('/mcp/destructive', $this->credential->bearerHeader(), ['app' => 'beta', 'confirm' => $confirmation])
        ->assertStatus(400)->assertJsonPath('error.message', TwoPhaseConfirmationRefused::MISMATCHED);
    callDeleteTool('/mcp/destructive', $other->bearerHeader(), ['app' => 'alpha', 'confirm' => $confirmation])
        ->assertStatus(400)->assertJsonPath('error.message', TwoPhaseConfirmationRefused::MISMATCHED);
    callDeleteTool('/mcp/destructive', $this->credential->bearerHeader(), ['app' => 'alpha', 'confirm' => $confirmation])->assertOk();
    callDeleteTool('/mcp/destructive', $this->credential->bearerHeader(), ['app' => 'alpha', 'confirm' => $confirmation])
        ->assertStatus(400)->assertJsonPath('error.message', TwoPhaseConfirmationRefused::SPENT);
    expect(AppActionEvent::query()->count())->toBe(1);
});

it('refuses destructive calls at the read ceiling and authorizes before validation', function (): void {
    callDeleteTool('/mcp', $this->credential->bearerHeader(), ['app' => 'alpha'])
        ->assertStatus(400)->assertJsonPath('error.message', 'effect_above_ceiling');
    app()->instance('request', HttpRequest::create('/mcp', 'POST'));
    expect(fn () => (new DeleteAppLogsTool)->handle(new McpRequest(['unexpected' => 'value'])))
        ->toThrow(AuthorizationException::class);
});

it('rejects missing blank and unknown app arguments before resolving write collaborators', function (): void {
    app()->bind(DeleteAppLogs::class, static fn (): never => throw new LogicException('Delete service resolved before validation.'));
    foreach ([[], ['app' => ''], ['app' => 'alpha', 'unknown' => 'value']] as $arguments) {
        $response = callDeleteTool('/mcp/destructive', $this->credential->bearerHeader(), $arguments);
        expect($response->status())->toBeIn([200, 400]);
        if ($response->status() === 200) {
            $response->assertJsonPath('result.isError', true);
        }
    }
    expect($this->lake->rows)->toBe(2)
        ->and(AppActionEvent::query()->count())->toBe(0);
});

it('refuses a syntactically valid missing app before resolving write collaborators', function (): void {
    app()->bind(DeleteAppLogs::class, static fn (): never => throw new LogicException('Delete service resolved for a missing app.'));

    $response = callDeleteTool('/mcp/destructive', $this->credential->bearerHeader(), ['app' => 'missing'])->assertOk();
    $response->assertJsonPath('result.isError', true)
        ->assertJsonPath('result.content.0.text', 'The requested Wake app does not exist.');
    expect($response->json('result._meta.two_phase.confirmation'))->toBeNull()
        ->and($this->lake->rows)->toBe(2)
        ->and(AppActionEvent::query()->count())->toBe(0);
});
