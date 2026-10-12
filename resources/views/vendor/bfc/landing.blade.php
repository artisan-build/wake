<x-bfc-layout title="Wake">
<section data-testid="landing" class="grid gap-8 py-4 lg:py-10">
    <header class="grid gap-4">
        <h1 class="font-display text-4xl font-normal tracking-tight text-clay-ink sm:text-5xl">Logs for your agents</h1>
        <p class="max-w-2xl text-lg leading-8 text-clay-soft">Wake is a self-hosted log sink for Laravel apps. It stores logs in your Laravel Cloud account so agents can investigate them through MCP.</p>
    </header>

    <section class="bfc-panel" aria-labelledby="landing-admin-title">
        <h2 id="landing-admin-title">Connect your apps</h2>
        <p class="mt-4">The admin shows connected apps, credentials, and ingest health. Log content is available to agents through MCP.</p>
        <a data-testid="landing-admin" class="bfc-button w-fit mt-4" href="{{ route('apps.index') }}">Open admin</a>
    </section>

    <section class="bfc-panel" aria-labelledby="landing-raw-title">
        <h2 id="landing-raw-title">Logs are sent as written</h2>
        <p class="mt-4">Wake stores the log records each app sends without changing them. Logs can contain secrets, tokens, or personal data. Configure redaction in each client app before sending anything that should not be stored.</p>
        <a class="bfc-link" href="{{ route('docs.show', 'setup') }}">Read the setup guide</a>
    </section>
</section>
</x-bfc-layout>
