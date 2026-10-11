<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\AdvertisesToolEffect;
use ArtisanBuild\BuiltForCloud\Mcp\Classification;
use ArtisanBuild\BuiltForCloud\Mcp\Effect;
use ArtisanBuild\BuiltForCloud\Mcp\ToolClassification;
use ArtisanBuild\BuiltForCloud\Mcp\ToolEffect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class RecentErrors extends WakeTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect;

    protected string $name = 'recent_errors';

    public function schema(JsonSchema $schema): array
    {
        return ['app' => $schema->string()->min(1), 'all_apps' => $schema->boolean(), 'environment' => $schema->string(), 'from' => $schema->string(), 'to' => $schema->string(), 'limit' => $schema->integer()->min(1)->max(500)];
    }

    public function handle(Request $request): Response
    {
        $this->authorize();

        return Response::error('The recent_errors query is not available yet.');
    }
}
