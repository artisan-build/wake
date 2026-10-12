<?php

declare(strict_types=1);

namespace App\Services;

use ArtisanBuild\BuiltForCloud\AppPurposeRegistry;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialKind;
use ArtisanBuild\BuiltForCloud\CredentialOwnership;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\WakeServer\Models\ConnectedApp;
use Illuminate\Support\Str;

final readonly class ProvisionConnectedApp
{
    public function __construct(private AppPurposeRegistry $purposes) {}

    public function forCredential(Credential $credential): ?ConnectedApp
    {
        if ($credential->kind !== CredentialKind::Bearer
            || $credential->purpose !== $this->purposes->purpose('wake.ingest')
            || $credential->ownership() !== CredentialOwnership::Installation
            || $credential->subject_type !== SubjectType::Installation) {
            return null;
        }

        $subjectRef = $credential->subject_ref;
        $connectedApp = ConnectedApp::query()->where('subject_ref', $subjectRef)->first();

        if ($connectedApp !== null) {
            return $connectedApp;
        }

        /** @var ConnectedApp|null $legacyApp */
        $legacyApp = ConnectedApp::query()->whereKey($subjectRef)->whereNull('subject_ref')->first();

        if ($legacyApp !== null) {
            $legacyApp->forceFill(['subject_ref' => $subjectRef])->save();

            return $legacyApp;
        }

        $id = 'app-'.strtolower((string) Str::ulid());

        return ConnectedApp::query()->firstOrCreate(
            ['subject_ref' => $subjectRef],
            [
                'id' => $id,
                'name' => $this->displayName($subjectRef, $id),
                'enabled' => true,
            ],
        );
    }

    private function displayName(string $subjectRef, string $id): string
    {
        $suffix = " [{$id}]";

        return Str::substr($subjectRef, 0, 100 - Str::length($suffix)).$suffix;
    }
}
