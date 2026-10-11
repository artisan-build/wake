<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

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
#[ToolClassification(Classification::Metadata)]
#[ToolEffect(Effect::Destructive)]
#[TwoPhase]
final class RotateCredential extends WakeTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect;

    protected string $name = 'rotate_credential';

    public function schema(JsonSchema $schema): array
    {
        return ['app' => $schema->string()->min(1)->required(), 'credential_id' => $schema->string()->min(1)->required()];
    }

    public function preview(Request $request): Response
    {
        $this->authorize();

        return Response::error('The rotate_credential preview is not available yet.');
    }

    public function handle(Request $request): Response
    {
        $this->authorize();

        return Response::error('The rotate_credential operation is not available yet.');
    }
}
