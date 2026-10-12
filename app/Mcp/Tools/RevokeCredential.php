<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\IngestCredentialTarget;
use ArtisanBuild\BuiltForCloud\Actions\RevokeCredential as RevokeCredentialAction;
use ArtisanBuild\BuiltForCloud\AuditActor;
use ArtisanBuild\BuiltForCloud\CredentialManagementScope;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\TwoPhase;
use ArtisanBuild\BuiltForCloud\RevokeOutcome;
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[ToolClassification(Classification::Metadata)]
#[ToolEffect(Effect::Destructive)]
#[TwoPhase]
final class RevokeCredential extends WakeTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect;

    protected string $name = 'revoke_credential';

    public function schema(JsonSchema $schema): array
    {
        return ['app' => $schema->string()->min(1)->max(255)->required(), 'credential_id' => $schema->string()->min(1)->max(255)->required()];
    }

    public function preview(Request $request): Response
    {
        $this->authorize();
        [$app, $credentialId] = $this->arguments($request);
        if (! $this->target($app, $credentialId)) {
            return $this->missingTarget();
        }

        return Response::json(['app' => $app, 'credential_id' => $credentialId, 'would_revoke' => true]);
    }

    public function handle(Request $request): Response
    {
        $principal = $this->authorize();
        [$app, $credentialId] = $this->arguments($request);
        if (! $this->target($app, $credentialId)) {
            return $this->missingTarget();
        }
        $identifier = $principal->identifier();
        if (! is_int($identifier) && ! is_string($identifier)) {
            return Response::error('The acting MCP principal cannot be attributed.');
        }
        $outcome = resolve(RevokeCredentialAction::class)(
            $credentialId,
            AuditActor::operatorIntegration((string) $identifier),
            new Subject(SubjectType::Installation, $app),
            CredentialManagementScope::memberInstallation(),
        );

        return $outcome === RevokeOutcome::Revoked
            ? Response::json(['app' => $app, 'credential_id' => $credentialId, 'revoked' => true])
            : $this->missingTarget();
    }

    /** @return array{string, string} */
    private function arguments(Request $request): array
    {
        $validated = $this->validate($request, [
            'app' => ['required', 'string', 'min:1', 'max:255'],
            'credential_id' => ['required', 'string', 'min:1', 'max:255'],
        ], ['app', 'credential_id']);

        return [(string) $validated['app'], (string) $validated['credential_id']];
    }

    private function target(string $app, string $credentialId): bool
    {
        if (! DB::table('wake_connected_apps')->where('id', $app)->exists()) {
            return false;
        }

        return resolve(IngestCredentialTarget::class)->findForRevoke($app, $credentialId) !== null;
    }

    private function missingTarget(): Response
    {
        return Response::error('The requested ingest credential does not exist.');
    }
}
