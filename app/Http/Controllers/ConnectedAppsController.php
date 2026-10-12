<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ConnectedAppCredentials;
use ArtisanBuild\BuiltForCloud\AuditActor;
use ArtisanBuild\BuiltForCloud\RevokeOutcome;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\WakeServer\Models\ConnectedApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class ConnectedAppsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'apps' => ConnectedApp::query()->orderBy('name')->get(['id', 'name', 'enabled']),
        ]);
    }

    public function show(ConnectedApp $app, ConnectedAppCredentials $credentials): JsonResponse
    {
        return response()->json([
            'app' => $app->only(['id', 'name', 'enabled']),
            'credential_id' => $credentials->current($app->id)?->id,
        ]);
    }

    public function store(Request $request, ConnectedAppCredentials $credentials): JsonResponse
    {
        /** @var array{id: string, name: string} $validated */
        $validated = $request->validate([
            'id' => ['required', 'string', 'max:64', 'regex:/\A[a-z](?:[a-z0-9-]*[a-z0-9])?\z/', Rule::unique('wake_connected_apps', 'id')],
            'name' => ['required', 'string', 'max:100', 'regex:/\A\S(?:.*\S)?\z/u', Rule::unique('wake_connected_apps', 'name')],
        ]);

        $created = $credentials->create($validated['id'], trim($validated['name']), $this->actor($request));
        $secret = $created['credential']->secret?->reveal();

        return response()->json([
            'app' => $created['app']->only(['id', 'name', 'enabled']),
            'credential_id' => $created['credential']->summary->id,
            'secret' => $secret,
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function rotate(Request $request, ConnectedApp $app, ConnectedAppCredentials $credentials): JsonResponse
    {
        $credential = $credentials->current($app->id);
        abort_if($credential === null, 404);

        $rotated = $credentials->rotate($app->id, $credential->id, $this->actor($request));
        abort_if($rotated === null, 404);

        return response()->json([
            'credential_id' => $rotated->mint->summary->id,
            'secret' => $rotated->mint->secret?->reveal(),
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function revoke(Request $request, ConnectedApp $app, ConnectedAppCredentials $credentials): Response
    {
        $credential = $credentials->current($app->id);
        abort_if($credential === null, 404);

        $outcome = $credentials->revoke($app->id, $credential->id, $this->actor($request));

        return match ($outcome) {
            RevokeOutcome::NotFound => abort(404),
            RevokeOutcome::Revoked, RevokeOutcome::AlreadyDead => response()->noContent(),
        };
    }

    private function actor(Request $request): AuditActor
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return AuditActor::boundUser((string) $user->getAuthIdentifier());
    }
}
