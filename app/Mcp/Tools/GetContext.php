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
final class GetContext extends WakeTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect;

    protected string $name = 'get_context';

    public function schema(JsonSchema $schema): array
    {
        return ['app' => $schema->string()->min(1)->required(), 'event_id' => $schema->string()->min(1)->required(), 'before' => $schema->integer()->min(0)->max(50), 'after' => $schema->integer()->min(0)->max(50)];
    }

    public function handle(Request $request): Response
    {
        $this->authorize();

        return Response::error('The get_context query is not available yet.');
    }
}
