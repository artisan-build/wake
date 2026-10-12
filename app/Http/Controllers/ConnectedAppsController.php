<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ConnectedAppCredentials;
use ArtisanBuild\BuiltForCloud\AuditActor;
use ArtisanBuild\BuiltForCloud\RevokeOutcome;
use ArtisanBuild\BuiltForCloud\User;
use ArtisanBuild\WakeServer\Models\ConnectedApp;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class ConnectedAppsController extends Controller
{
    public function index(Request $request): JsonResponse|View
    {
        $apps = ConnectedApp::query()->orderBy('name')->get(['id', 'name', 'enabled']);

        return $request->expectsJson()
            ? response()->json(['apps' => $apps])
            : view('apps.index', ['apps' => $apps]);
    }

    public function show(Request $request, ConnectedApp $app, ConnectedAppCredentials $credentials): JsonResponse|View
    {
        $credential = $credentials->current($app->id);

        return $request->expectsJson()
            ? response()->json([
                'app' => $app->only(['id', 'name', 'enabled']),
                'credential_id' => $credential?->id,
            ])
            : view('apps.show', [
                'app' => $app,
                'credential' => $credential,
                'wakeUrl' => url('/'),
            ]);
    }

    public function store(Request $request, ConnectedAppCredentials $credentials): Response
    {
        /** @var array{id: string, name: string} $validated */
        $validated = $request->validate([
            'id' => ['required', 'string', 'max:64', 'regex:/\A[a-z](?:[a-z0-9-]*[a-z0-9])?\z/', Rule::unique('wake_connected_apps', 'id')],
            'name' => ['required', 'string', 'max:100', 'regex:/\A\S(?:.*\S)?\z/u', Rule::unique('wake_connected_apps', 'name')],
        ]);

        $created = $credentials->create($validated['id'], trim($validated['name']), $this->actor($request));
        $secret = $created['credential']->secret?->reveal();

        if ($request->expectsJson()) {
            return response()->json([
                'app' => $created['app']->only(['id', 'name', 'enabled']),
                'credential_id' => $created['credential']->summary->id,
                'secret' => $secret,
            ], 201)->header('Cache-Control', 'no-store');
        }

        return response()->view('apps.secret', [
            'app' => $created['app'],
            'credentialId' => $created['credential']->summary->id,
            'secret' => $secret,
            'wakeUrl' => url('/'),
            'rotated' => false,
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function rotate(Request $request, ConnectedApp $app, ConnectedAppCredentials $credentials): Response
    {
        $credential = $credentials->current($app->id);
        abort_if($credential === null, 404);

        $rotated = $credentials->rotate($app->id, $credential->id, $this->actor($request));
        abort_if($rotated === null, 404);

        $secret = $rotated->mint->secret?->reveal();

        if ($request->expectsJson()) {
            return response()->json([
                'credential_id' => $rotated->mint->summary->id,
                'secret' => $secret,
            ], 201)->header('Cache-Control', 'no-store');
        }

        return response()->view('apps.secret', [
            'app' => $app,
            'credentialId' => $rotated->mint->summary->id,
            'secret' => $secret,
            'wakeUrl' => url('/'),
            'rotated' => true,
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function revoke(Request $request, ConnectedApp $app, ConnectedAppCredentials $credentials): Response
    {
        $credential = $credentials->current($app->id);
        abort_if($credential === null, 404);

        $outcome = $credentials->revoke($app->id, $credential->id, $this->actor($request));

        if ($outcome === RevokeOutcome::NotFound) {
            abort(404);
        }

        return $request->expectsJson() ? response()->noContent() : redirect()->route('apps.show', $app);
    }

    private function actor(Request $request): AuditActor
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return AuditActor::boundUser((string) $user->getAuthIdentifier());
    }
}
