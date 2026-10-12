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
    public function find(string $appId, string $credentialId): ?Credential
    {
        if (! Str::isUuid($credentialId)) {
            return null;
        }

        return Credential::query()
            ->whereKey($credentialId)
            ->where('kind', CredentialKind::Bearer)
            ->where('purpose', CredentialPurpose::Consumption)
            ->where('subject_type', SubjectType::Installation)
            ->where('subject_ref', $appId)
            ->whereNull('user_id')
            ->where('status', CredentialStatus::Active)
            ->whereNull('revoked_at')
            ->whereNull('rotated_at')
            ->where(static function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();
    }
}
