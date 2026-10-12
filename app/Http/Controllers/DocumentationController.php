<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

final class DocumentationController extends Controller
{
    /** @var array<string, string> */
    private const array PAGES = [
        'setup' => 'setup.md',
        'agent-redaction' => 'agent-redaction.md',
        'retention-cost' => 'retention-cost.md',
        'ingest-contract' => 'ingest-contract.md',
    ];

    public function __invoke(string $page): View
    {
        abort_unless(isset(self::PAGES[$page]), 404);
        $markdown = file_get_contents(base_path('docs/'.self::PAGES[$page]));
        abort_if($markdown === false, 404);

        return view('docs.show', [
            'title' => Str::headline($page),
            'content' => Str::markdown($markdown),
        ]);
    }
}
