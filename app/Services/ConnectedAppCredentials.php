<?php

declare(strict_types=1);

namespace App\Services;

use App\Authorization\WakeCredentialAbility;
use ArtisanBuild\BuiltForCloud\Actions\MintCredential;
use ArtisanBuild\BuiltForCloud\Actions\RevokeCredential;
use ArtisanBuild\BuiltForCloud\Actions\RotateCredential;
use ArtisanBuild\BuiltForCloud\AuditActor;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\MintOptions;
use ArtisanBuild\BuiltForCloud\MintResult;
use ArtisanBuild\BuiltForCloud\RevokeOutcome;
use ArtisanBuild\BuiltForCloud\RotateOptions;
use ArtisanBuild\BuiltForCloud\RotationResult;
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\WakeServer\Models\ConnectedApp;
use Illuminate\Support\Facades\DB;

final readonly class ConnectedAppCredentials
{
    public function __construct(
        private MintCredential $mint,
        private RotateCredential $rotate,
        private RevokeCredential $revoke,
        private IngestCredentialTarget $targets,
    ) {}

    /** @return array{app: ConnectedApp, credential: MintResult} */
    public function create(string $id, string $name, AuditActor $actor): array
    {
        return DB::transaction(function () use ($id, $name, $actor): array {
            $app = ConnectedApp::query()->create([
                'id' => $id,
                'name' => $name,
                'enabled' => true,
            ]);

            $credential = ($this->mint)(
                new Subject(SubjectType::Installation, $id),
                new MintOptions(
                    kind: CredentialKind::Bearer,
                    purpose: CredentialPurpose::Consumption,
                    name: $name.' ingest',
                    abilities: [WakeCredentialAbility::Read->value],
                ),
                $actor,
            );

            return ['app' => $app, 'credential' => $credential];
        });
    }

    public function current(string $appId): ?Credential
    {
        return Credential::query()
            ->where('kind', CredentialKind::Bearer)
            ->where('purpose', CredentialPurpose::Consumption)
            ->where('subject_type', SubjectType::Installation)
            ->where('subject_ref', $appId)
            ->whereNull('user_id')
            ->where('status', CredentialStatus::Active)
            ->whereNull('rotated_at')
            ->whereNull('revoked_at')
            ->where(static function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();
    }

    public function rotate(string $appId, string $credentialId, AuditActor $actor): ?RotationResult
    {
        $target = $this->targets->findForRotate($appId, $credentialId);

        if ($target === null) {
            return null;
        }

        return ($this->rotate)($target->id, new RotateOptions, $actor);
    }

    public function revoke(string $appId, string $credentialId, AuditActor $actor): RevokeOutcome
    {
        $target = $this->targets->findForRevoke($appId, $credentialId);

        if ($target === null) {
            return RevokeOutcome::NotFound;
        }

        return ($this->revoke)(
            $target->id,
            $actor,
            new Subject(SubjectType::Installation, $appId),
        );
    }
}
