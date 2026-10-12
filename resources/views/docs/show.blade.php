<x-bfc-layout :title="$title">
<section data-testid="documentation" class="grid gap-8 py-4 lg:py-10">
    <nav aria-label="Documentation" class="bfc-nav flex flex-wrap gap-4">
        <a href="{{ route('docs.show', 'setup') }}">Setup</a>
        <a href="{{ route('docs.show', 'agent-redaction') }}">Redaction</a>
        <a href="{{ route('docs.show', 'retention-cost') }}">Retention and cost</a>
        <a href="{{ route('docs.show', 'ingest-contract') }}">Ingest contract</a>
        <a href="{{ route('apps.index') }}">Admin</a>
    </nav>
    <article data-testid="documentation-content" class="bfc-panel" style="max-width:100%; overflow-wrap:anywhere">
        {!! $content !!}
    </article>
</section>
</x-bfc-layout>
