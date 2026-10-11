<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Audit\WakeAction;
use App\Services\DeleteAppLogs as DeleteAppLogsService;
use ArtisanBuild\BuiltForCloud\Audit\AppActionActor;
use ArtisanBuild\BuiltForCloud\Audit\AppActionReason;
use ArtisanBuild\BuiltForCloud\Audit\AppActionRecorder;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\TwoPhase;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Destructive)]
#[TwoPhase]
final class DeleteAppLogs extends WakeTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect;

    protected string $name = 'delete_app_logs';

    public function schema(JsonSchema $schema): array
    {
        return ['app' => $schema->string()->min(1)->max(255)->required()];
    }

    public function preview(Request $request): Response
    {
        $this->authorize();
        $app = $this->app($request);

        return Response::json(resolve(DeleteAppLogsService::class)->preview($app));
    }

    public function handle(Request $request): Response
    {
        $principal = $this->authorize();
        $app = $this->app($request);
        $service = resolve(DeleteAppLogsService::class);
        $recorder = resolve(AppActionRecorder::class);
        $actor = AppActionActor::fromActingPrincipal($principal);

        return Response::json($service->run($app, static fn () => $recorder->record(
            action: WakeAction::AppLogsDeleted,
            actor: $actor,
            reason: AppActionReason::Requested,
        )));
    }

    private function app(Request $request): string
    {
        $validated = $this->validate($request, [
            'app' => ['required', 'string', 'min:1', 'max:255'],
        ], ['app']);

        return (string) $validated['app'];
    }
}
