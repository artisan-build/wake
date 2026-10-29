<x-bfc-layout title="Apps">
<x-slot:sidebar>
    @include('partials.sidebar')
</x-slot:sidebar>
<style>
    [data-testid="apps-create"] input { width: 100%; border: 1px solid var(--color-clay-edge); border-radius: .75rem; background: var(--color-clay-surface); padding: .75rem 1rem; color: var(--color-clay-ink); }
    [data-testid="apps-create"] input:focus-visible { outline: 3px solid var(--color-clay-iron); outline-offset: 2px; }
</style>
<section data-testid="apps-index" class="grid gap-8">
    <header class="grid gap-4">
        <h1 class="font-display text-4xl font-normal tracking-tight text-clay-ink">Apps</h1>
        <p class="max-w-2xl text-lg leading-8 text-clay-soft">Connect each Laravel app that sends logs to this Wake installation.</p>
    </header>

    @if ($errors->any())
        <section data-testid="apps-errors" class="bfc-panel" role="alert" aria-labelledby="apps-errors-title">
            <h2 id="apps-errors-title">Check the app details</h2>
            <ul class="bfc-list mt-4">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    <section data-testid="apps-create" class="bfc-panel" aria-labelledby="apps-create-title">
        <h2 id="apps-create-title">Add an app</h2>
        <form method="POST" action="{{ route('apps.store') }}" class="grid gap-4 mt-4">
            @csrf
            <label for="app-id">Identifier</label>
            <input id="app-id" name="id" value="{{ old('id') }}" required maxlength="64" pattern="[a-z][a-z0-9-]*[a-z0-9]|[a-z]" aria-describedby="app-id-help">
            <p id="app-id-help" class="text-clay-soft">Use lowercase letters, numbers, and hyphens.</p>
            <label for="app-name">Name</label>
            <input id="app-name" name="name" value="{{ old('name') }}" required maxlength="100">
            <button type="submit" class="bfc-button w-fit">Create app</button>
        </form>
    </section>

    <section data-testid="apps-list" aria-labelledby="apps-list-title">
        <h2 id="apps-list-title">Connected apps</h2>
        @if ($apps->isEmpty())
            <p class="bfc-status mt-4">No apps have been connected yet.</p>
        @else
            <ul class="bfc-list mt-4">
                @foreach ($apps as $app)
                    <li data-testid="apps-item" class="bfc-row flex flex-wrap items-center gap-4">
                        <a class="bfc-link" href="{{ route('apps.show', $app) }}">{{ $app->name }}</a>
                        <code>{{ $app->id }}</code>
                        <span>{{ $app->enabled ? 'Enabled' : 'Disabled' }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</section>
</x-bfc-layout>
