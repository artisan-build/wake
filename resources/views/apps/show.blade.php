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

    <section data-testid="app-health" class="bfc-panel" aria-labelledby="app-health-title">
        <h2 id="app-health-title">Ingest health</h2>
        <p class="bfc-status mt-4">
            @if ($health['state'] === 'never_seen')
                Wake has not received a batch from this app.
            @elseif ($health['state'] === 'pending')
                Wake has received data, but no flush has completed yet.
            @elseif ($health['state'] === 'lagging')
                Ingest is lagging behind the latest accepted batch.
            @else
                Ingest is caught up with the latest accepted batch.
            @endif
        </p>
        <dl class="grid gap-2 mt-4 sm:grid-cols-2">
            <div><dt>Last seen</dt><dd>{{ $health['last_seen_at']?->toIso8601String() ?? 'Never' }}</dd></div>
            <div><dt>Environments</dt><dd>{{ $health['environments'] === [] ? 'None reported' : implode(', ', $health['environments']) }}</dd></div>
            <div><dt>Accepted rows</dt><dd>{{ number_format($health['accepted_rows']) }}</dd></div>
            <div><dt>Flushed rows</dt><dd>{{ number_format($health['flushed_rows']) }}</dd></div>
            <div><dt>Rejected batches</dt><dd>{{ number_format($health['rejected_batches']) }}</dd></div>
            <div><dt>Rate-limit hits</dt><dd>{{ number_format($health['rate_limit_hits']) }}</dd></div>
            <div><dt>Client-reported drops</dt><dd>{{ number_format($health['dropped_rows']) }}</dd></div>
            <div><dt>Pending batches</dt><dd>{{ number_format($health['pending_batches']) }}</dd></div>
            <div><dt>Flush lag</dt><dd>{{ $health['lag_seconds'] === null ? 'Not available' : $health['lag_seconds'].' seconds' }}</dd></div>
            <div><dt>Client version</dt><dd>{{ $health['last_client_version'] ?? 'Not reported' }}</dd></div>
        </dl>
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
