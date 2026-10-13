<?php

declare(strict_types=1);

namespace App\Services;

use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialOwnership;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\ExactCredentialTarget;
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\WakeServer\Models\ConnectedApp;
use Illuminate\Support\Str;

final class IngestCredentialTarget
{
    public function findForRevoke(string $appId, string $credentialId): ?Credential
    {
        return $this->find($appId, $credentialId, false);
    }

    public function findForRotate(string $appId, string $credentialId): ?Credential
    {
        return $this->find($appId, $credentialId, true);
    }

    public function exactForRotate(string $appId): ?ExactCredentialTarget
    {
        $subjectRef = ConnectedApp::query()->whereKey($appId)->value('subject_ref');

        if (! is_string($subjectRef)) {
            return null;
        }

        return new ExactCredentialTarget(
            purpose: CredentialPurpose::Consumption,
            subject: new Subject(SubjectType::Installation, $subjectRef),
            kind: CredentialKind::Bearer,
            ownership: CredentialOwnership::Installation,
        );
    }

    private function find(string $appId, string $credentialId, bool $unrotated): ?Credential
    {
        if (! Str::isUuid($credentialId)) {
            return null;
        }

        $subjectRef = ConnectedApp::query()->whereKey($appId)->value('subject_ref');

        if (! is_string($subjectRef)) {
            return null;
        }

        $query = Credential::query()
            ->whereKey($credentialId)
            ->where('kind', CredentialKind::Bearer)
            ->where('purpose', CredentialPurpose::Consumption)
            ->where('subject_type', SubjectType::Installation)
            ->where('subject_ref', $subjectRef)
            ->whereNull('user_id')
            ->where('status', CredentialStatus::Active)
            ->whereNull('revoked_at')
            ->where(static function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });

        return $unrotated ? $query->whereNull('rotated_at')->first() : $query->first();
    }
}
