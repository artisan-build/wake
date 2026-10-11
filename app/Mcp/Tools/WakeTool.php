<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Authorization\McpAccess;
use App\Authorization\WakeCredentialAbility;
use ArtisanBuild\BuiltForCloud\Console\ActingPrincipal;
use ArtisanBuild\BuiltForCloud\Mcp\RespectsEffectCeiling;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;

abstract class WakeTool extends Tool
{
    use RespectsEffectCeiling;

    public function shouldRegister(McpAccess $access): bool
    {
        return $access->allows(WakeCredentialAbility::Read);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $tool = parent::toArray();
        $tool['inputSchema']['additionalProperties'] = false;

        return $tool;
    }

    protected function authorize(): ActingPrincipal
    {
        return resolve(McpAccess::class)->authorize(WakeCredentialAbility::Read);
    }

    /**
     * @param  array<string, mixed>  $rules
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    protected function validate(Request $request, array $rules, array $keys): array
    {
        $unexpected = array_values(array_diff(array_keys($request->all()), $keys));

        if ($unexpected !== []) {
            throw ValidationException::withMessages([
                'arguments' => 'Unexpected arguments: '.implode(', ', $unexpected).'.',
            ]);
        }

        return $request->validate($rules);
    }
}
