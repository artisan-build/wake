<nav data-testid="app-navigation" aria-label="Wake" class="bfc-sidebar-links">
    <a href="{{ route('docs.show', 'setup') }}"
       class="bfc-shell-link"
       @if (request()->routeIs('docs.show') && request()->route('page') === 'setup') aria-current="page" @endif>
        Setup
    </a>
    <a href="{{ route('docs.show', 'agent-redaction') }}"
       class="bfc-shell-link"
       @if (request()->routeIs('docs.show') && request()->route('page') === 'agent-redaction') aria-current="page" @endif>
        Redaction
    </a>
    <a href="{{ route('docs.show', 'retention-cost') }}"
       class="bfc-shell-link"
       @if (request()->routeIs('docs.show') && request()->route('page') === 'retention-cost') aria-current="page" @endif>
        Retention and cost
    </a>
    <a href="{{ route('docs.show', 'ingest-contract') }}"
       class="bfc-shell-link"
       @if (request()->routeIs('docs.show') && request()->route('page') === 'ingest-contract') aria-current="page" @endif>
        Ingest contract
    </a>
    <a href="{{ route('apps.index') }}"
       class="bfc-shell-link"
       @if (request()->routeIs('apps.*')) aria-current="page" @endif>
        Admin
    </a>
</nav>
