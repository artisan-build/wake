<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\WakeQueries;
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
        return ['app' => $schema->string()->min(1)->max(255), 'all_apps' => $schema->boolean(), 'environment' => $schema->string()->min(1)->max(255), 'level' => $schema->string()->min(1)->max(20), 'from' => $schema->string()->required(), 'to' => $schema->string()->required(), 'group_by' => $schema->array()->min(1)->max(4)->items($schema->string()->enum(['hour', 'app', 'environment', 'level']))];
    }

    public function handle(Request $request): Response
    {
        $this->authorize();
        $validated = $this->validateScoped($request, [
            'app' => ['nullable', 'string', 'min:1', 'max:255'],
            'all_apps' => ['nullable', 'boolean'],
            'environment' => ['nullable', 'string', 'min:1', 'max:255'],
            'level' => ['nullable', 'string', 'min:1', 'max:20'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'group_by' => ['nullable', 'array', 'min:1', 'max:4'],
            'group_by.*' => ['string', 'in:hour,app,environment,level'],
        ], ['app', 'all_apps', 'environment', 'level', 'from', 'to', 'group_by']);

        return $this->untrusted(resolve(WakeQueries::class)->aggregate($validated));
    }
}
