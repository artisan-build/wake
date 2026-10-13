<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ConnectedAppCredentials;
use App\Services\ConnectedAppHealth;
use ArtisanBuild\BuiltForCloud\AuditActor;
use ArtisanBuild\BuiltForCloud\Exceptions\RotationCutoverIncomplete;
use ArtisanBuild\BuiltForCloud\Exceptions\RotationRefused;
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

    public function show(Request $request, string $app, ConnectedAppCredentials $credentials, ConnectedAppHealth $health): JsonResponse|View
    {
        $connectedApp = ConnectedApp::query()->findOrFail($app);
        $credential = $credentials->current($connectedApp->id);

        return $request->expectsJson()
            ? response()->json([
                'app' => $connectedApp->only(['id', 'name', 'enabled']),
                'credential_id' => $credential?->id,
            ])
            : view('apps.show', [
                'app' => $connectedApp,
                'credential' => $credential,
                'health' => $health->for($connectedApp),
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

    public function rotate(Request $request, string $app, ConnectedAppCredentials $credentials): Response
    {
        $connectedApp = ConnectedApp::query()->findOrFail($app);
        $credential = $credentials->current($connectedApp->id);
        abort_if($credential === null, 404);

        try {
            $rotated = $credentials->rotate($connectedApp->id, $credential->id, $this->actor($request));
        } catch (RotationRefused) {
            abort(409, 'The ingest credential changed during rotation.');
        } catch (RotationCutoverIncomplete $incomplete) {
            if (! $incomplete->getPrevious() instanceof RotationRefused) {
                throw $incomplete;
            }

            abort(409, 'Rotation partially completed. The previous credential may remain active; review and revoke it before retrying.');
        }
        abort_if($rotated === null, 404);

        $secret = $rotated->mint->secret?->reveal();

        if ($request->expectsJson()) {
            return response()->json([
                'credential_id' => $rotated->mint->summary->id,
                'secret' => $secret,
            ], 201)->header('Cache-Control', 'no-store');
        }

        return response()->view('apps.secret', [
            'app' => $connectedApp,
            'credentialId' => $rotated->mint->summary->id,
            'secret' => $secret,
            'wakeUrl' => url('/'),
            'rotated' => true,
        ], 201)->header('Cache-Control', 'no-store');
    }

    public function revoke(Request $request, string $app, ConnectedAppCredentials $credentials): Response
    {
        $connectedApp = ConnectedApp::query()->findOrFail($app);
        $credential = $credentials->current($connectedApp->id);
        abort_if($credential === null, 404);

        $outcome = $credentials->revoke($connectedApp->id, $credential->id, $this->actor($request));

        if ($outcome === RevokeOutcome::NotFound) {
            abort(404);
        }

        return $request->expectsJson() ? response()->noContent() : to_route('apps.show', $connectedApp);
    }

    private function actor(Request $request): AuditActor
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return AuditActor::boundUser((string) $user->getAuthIdentifier());
    }
}
