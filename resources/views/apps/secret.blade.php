<x-bfc-layout :title="$rotated ? 'Credential rotated' : 'App created'">
<section data-testid="app-secret" class="grid gap-8">
    <header class="grid gap-4">
        <h1 class="font-display text-4xl font-normal tracking-tight text-clay-ink">{{ $rotated ? 'Credential rotated' : 'App created' }}</h1>
        <p role="status">Copy this token now. Wake will not show it again.</p>
    </header>

    <section data-testid="app-secret-delivery" class="bfc-panel" aria-labelledby="app-secret-title">
        <h2 id="app-secret-title">{{ $app->name }} ingest token</h2>
        <p class="mt-4">Credential ID: <code>{{ $credentialId }}</code></p>
        <pre tabindex="0" aria-label="Wake ingest token" style="max-width:100%; overflow-x:auto; white-space:pre-wrap"><code>{{ $secret }}</code></pre>
    </section>

    <section data-testid="app-secret-setup" class="bfc-panel" aria-labelledby="app-secret-setup-title">
        <h2 id="app-secret-setup-title">Set up {{ $app->name }}</h2>
        <pre tabindex="0" aria-label="Wake client setup" style="max-width:100%; overflow-x:auto; white-space:pre-wrap"><code>composer require artisan-build/wake-client
WAKE_URL={{ $wakeUrl }}
WAKE_TOKEN={{ $secret }}
LOG_STACK=single,wake</code></pre>
        <p><a class="bfc-link" href="/docs/agent-redaction">Read the agent redaction guide</a></p>
    </section>

    <a class="bfc-button w-fit" href="{{ route('apps.show', $app) }}">Continue to app</a>
</section>
</x-bfc-layout>
