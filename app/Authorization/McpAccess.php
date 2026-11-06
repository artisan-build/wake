<?php

declare(strict_types=1);

namespace App\Authorization;

use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipalResolver;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\Exceptions\DelegatedPrincipalUnsupported;
use ArtisanBuild\BuiltForCloud\Exceptions\NoActingPrincipal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

final readonly class McpAccess
{
    public function __construct(private ActingPrincipalResolver $principals) {}

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

        try {
            $principal = $this->principals->require($request);
        } catch (DelegatedPrincipalUnsupported|NoActingPrincipal) {
            return null;
        }

        if ($principal->delegated) {
            return $principal;
        }

        $credential = $principal->principal;

        if (! $credential instanceof Credential
            || $credential->purpose !== CredentialPurpose::Mcp
            || ! $credential->hasAbility($ability->value)) {
            return null;
        }

        return $principal;
    }
}
