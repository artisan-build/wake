<x-bfc-layout :title="$title">
<x-slot:sidebar>
    @include('partials.sidebar')
</x-slot:sidebar>
<style>
    [data-testid="documentation"],
    [data-testid="documentation-content"] { min-width: 0; max-width: 100%; }
    [data-testid="documentation-content"] { overflow: hidden; }
    [data-testid="documentation-content"] pre { display: block; max-width: 100%; overflow-x: auto; white-space: pre; }
</style>
<section data-testid="documentation" class="grid gap-8 py-4 lg:py-10">
    <article data-testid="documentation-content" class="bfc-panel" style="max-width:100%; min-width:0; overflow:hidden; overflow-wrap:anywhere">
        {!! $content !!}
    </article>
</section>
</x-bfc-layout>
