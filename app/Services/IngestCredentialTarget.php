<?php

declare(strict_types=1);

namespace App\Services;

use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\SubjectType;
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

    private function find(string $appId, string $credentialId, bool $unrotated): ?Credential
    {
        if (! Str::isUuid($credentialId)) {
            return null;
        }

        $query = Credential::query()
            ->whereKey($credentialId)
            ->where('kind', CredentialKind::Bearer)
            ->where('purpose', CredentialPurpose::Consumption)
            ->where('subject_type', SubjectType::Installation)
            ->where('subject_ref', $appId)
            ->whereNull('user_id')
            ->where('status', CredentialStatus::Active)
            ->whereNull('revoked_at')
            ->where(static function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });

        return $unrotated ? $query->whereNull('rotated_at')->first() : $query->first();
    }
}
