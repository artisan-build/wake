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
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class GetContext extends WakeTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect;

    protected string $name = 'get_context';

    public function schema(JsonSchema $schema): array
    {
        return ['app' => $schema->string()->min(1)->max(255)->required(), 'event_id' => $schema->string()->min(1)->max(255)->required(), 'before' => $schema->integer()->min(0)->max(50), 'after' => $schema->integer()->min(0)->max(50)];
    }

    public function handle(Request $request): Response
    {
        $this->authorize();
        $validated = $this->validate($request, [
            'app' => ['required', 'string', 'min:1', 'max:255'],
            'event_id' => ['required', 'string', 'min:1', 'max:255'],
            'before' => ['nullable', 'integer', 'min:0', 'max:50'],
            'after' => ['nullable', 'integer', 'min:0', 'max:50'],
        ], ['app', 'event_id', 'before', 'after']);

        return $this->untrusted(resolve(WakeQueries::class)->context(
            (string) $validated['app'],
            (string) $validated['event_id'],
            (int) ($validated['before'] ?? 5),
            (int) ($validated['after'] ?? 5),
        ));
    }
}
