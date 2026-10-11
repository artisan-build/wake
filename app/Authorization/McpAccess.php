<?php

declare(strict_types=1);

namespace App\Authorization;

use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use ArtisanBuild\BuiltForCloud\Console\RequestAssertion;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

final readonly class McpAccess
{
    public function allows(WakeCredentialAbility $ability): bool
    {
        return $this->resolve($ability) instanceof ActingPrincipal;
    }

    public function authorize(WakeCredentialAbility $ability): ActingPrincipal
    {
        $principal = $this->resolve($ability);

        if (! $principal instanceof ActingPrincipal) {
            throw new AuthorizationException('This MCP principal is not authorized to read Wake data.');
        }

        return $principal;
    }

    private function resolve(WakeCredentialAbility $ability): ?ActingPrincipal
    {
        $request = resolve('request');

        if (! $request instanceof Request) {
            return null;
        }

        $delegated = RequestAssertion::principal($request);

        if ($delegated instanceof ActingPrincipal && $delegated->check()) {
            return $delegated;
        }

        $credential = $request->user();

        if (! $credential instanceof Credential
            || $credential->purpose !== CredentialPurpose::Mcp
            || ! $credential->hasAbility($ability->value)) {
            return null;
        }

        return ActingPrincipal::local('wake.mcp', $credential);
    }
}
