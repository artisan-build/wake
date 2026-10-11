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
#[ToolClassification(Classification::Metadata)]
#[ToolEffect(Effect::Read)]
final class AggregateLogs extends WakeTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect;

    protected string $name = 'aggregate_logs';

    public function schema(JsonSchema $schema): array
    {
        return ['app' => $schema->string()->min(1), 'all_apps' => $schema->boolean(), 'environment' => $schema->string(), 'level' => $schema->string(), 'from' => $schema->string(), 'to' => $schema->string(), 'group_by' => $schema->array()->items($schema->string())];
    }

    public function handle(Request $request): Response
    {
        $this->authorize();

        return Response::error('The aggregate_logs query is not available yet.');
    }
}
