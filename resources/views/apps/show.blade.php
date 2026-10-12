<x-bfc-layout :title="$app->name">
<section data-testid="app-detail" class="grid gap-8">
    <nav aria-label="Breadcrumb"><a class="bfc-link" href="{{ route('apps.index') }}">Apps</a></nav>
    <header class="grid gap-4">
        <h1 class="font-display text-4xl font-normal tracking-tight text-clay-ink">{{ $app->name }}</h1>
        <dl class="grid gap-2">
            <div><dt>Identifier</dt><dd><code>{{ $app->id }}</code></dd></div>
            <div><dt>Status</dt><dd>{{ $app->enabled ? 'Enabled' : 'Disabled' }}</dd></div>
        </dl>
    </header>

    <section data-testid="app-credential" class="bfc-panel" aria-labelledby="app-credential-title">
        <h2 id="app-credential-title">Ingest credential</h2>
        @if ($credential)
            <p class="mt-4">Current credential: <code>{{ $credential->id }}</code></p>
            <div class="flex flex-wrap gap-4 mt-4">
                <form method="POST" action="{{ route('apps.credential.rotate', $app) }}">@csrf<button type="submit" class="bfc-button">Rotate credential</button></form>
                <form method="POST" action="{{ route('apps.credential.revoke', $app) }}">@csrf @method('DELETE')<button type="submit" class="bfc-button-soft">Revoke credential</button></form>
            </div>
        @else
            <p class="bfc-status mt-4">This app has no current ingest credential.</p>
        @endif
    </section>

    <section data-testid="app-setup" class="bfc-panel" aria-labelledby="app-setup-title">
        <h2 id="app-setup-title">Setup</h2>
        <p class="mt-4">Use the Wake installation URL. The client adds <code>/api/v1/ingest</code> when it sends a batch.</p>
        <pre tabindex="0" aria-label="Wake client setup" style="max-width:100%; overflow-x:auto; white-space:pre-wrap"><code>composer require artisan-build/wake-client
WAKE_URL={{ $wakeUrl }}
WAKE_TOKEN=&lt;your Wake token&gt;
LOG_STACK=single,wake</code></pre>
        <p><a class="bfc-link" href="/docs/agent-redaction">Read the agent redaction guide</a></p>
    </section>
</section>
</x-bfc-layout>
