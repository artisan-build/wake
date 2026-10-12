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
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[ToolClassification(Classification::Content)]
#[ToolEffect(Effect::Read)]
final class SearchLogs extends WakeTool
{
    use AdvertisesToolClassification, AdvertisesToolEffect;

    protected string $name = 'search_logs';

    public function schema(JsonSchema $schema): array
    {
        return ['app' => $schema->string()->min(1)->max(255), 'all_apps' => $schema->boolean(), 'text' => $schema->string()->min(1)->max(500), 'regex' => $schema->string()->min(1)->max(500), 'environment' => $schema->string()->min(1)->max(255), 'level' => $schema->string()->min(1)->max(20), 'from' => $schema->string()->required(), 'to' => $schema->string()->required(), 'limit' => $schema->integer()->min(1)->max(500)];
    }

    public function handle(Request $request): Response
    {
        $this->authorize();
        $validated = $this->validateScoped($request, [
            'app' => ['nullable', 'string', 'min:1', 'max:255'],
            'all_apps' => ['nullable', 'boolean'],
            'text' => ['nullable', 'string', 'min:1', 'max:500', 'required_without:regex'],
            'regex' => ['nullable', 'string', 'min:1', 'max:500', 'required_without:text'],
            'environment' => ['nullable', 'string', 'min:1', 'max:255'],
            'level' => ['nullable', 'string', 'min:1', 'max:20'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ], ['app', 'all_apps', 'text', 'regex', 'environment', 'level', 'from', 'to', 'limit']);
        if (isset($validated['text'], $validated['regex'])) {
            throw ValidationException::withMessages(['search' => 'Specify exactly one of text or regex.']);
        }

        return $this->untrusted(resolve(WakeQueries::class)->search($validated));
    }
}
