<x-layouts.guest title="Wrong Workspace — Sync360">
    <section class="auth-wrap auth-wrap--login">
        <div class="hero-card auth-aside auth-story">
            <div>
                <span class="eyebrow"><span class="eyebrow-dot"></span> Workspace Access</span>
                <h2>This workspace URL belongs to another customer account.</h2>
                <p>
                    You are signed in, but this tenant URL is reserved for {{ $workspaceTenant->business_name }}.
                    Use your own Sync360 workspace link to continue.
                </p>
            </div>
        </div>

        <div class="panel auth-card">
            <header>
                <span class="kicker">Access blocked</span>
                <h2>Use the correct workspace link</h2>
                <p class="hint">We’ve blocked access here so one tenant can never open another tenant’s workspace host.</p>
            </header>

            <div class="note" style="margin-top: 18px;">
                Requested workspace: <strong>{{ $workspaceTenant->workspace_url ?: $workspaceTenant->slug }}</strong>
            </div>

            <div style="margin-top: 18px; display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="{{ $destinationUrl }}" class="button button--primary">{{ $destinationLabel }}</a>
                <a href="{{ route('logout') }}"
                    class="button button--secondary"
                    onclick="event.preventDefault(); document.getElementById('workspace-access-logout').submit();">
                    Log Out
                </a>
            </div>

            <form id="workspace-access-logout" method="POST" action="{{ route('logout') }}" style="display: none;">
                @csrf
            </form>
        </div>
    </section>
</x-layouts.guest>
