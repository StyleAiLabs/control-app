<section class="panel">
    <div class="topbar" style="margin-bottom: 16px;">
        <div>
            <span class="eyebrow">Support Actions</span>
            <h2 class="type-section-title">Operational Controls</h2>
        </div>
    </div>

    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <form method="POST" action="{{ route('admin.retry', $tenant) }}" class="inline">
            @csrf
            <input type="hidden" name="return_tab" value="support">
            <button type="submit">Retry</button>
        </form>
        <form method="POST" action="{{ route('admin.tenants.health-check', $tenant) }}" class="inline">
            @csrf
            <input type="hidden" name="return_tab" value="support">
            <button type="submit" {{ $canManageWorkspace ? '' : 'disabled' }}>Health Check</button>
        </form>
        <form method="POST" action="{{ route('admin.tenants.resync-agent', $tenant) }}" class="inline">
            @csrf
            <input type="hidden" name="return_tab" value="support">
            <button type="submit" {{ $canManageWorkspace ? '' : 'disabled' }}>Resync Agent</button>
        </form>
        <form method="POST" action="{{ route('admin.tenants.runtime.bootstrap', $tenant) }}" class="inline">
            @csrf
            <input type="hidden" name="return_tab" value="support">
            <button type="submit" {{ $tenant->server ? '' : 'disabled' }}>Bootstrap VPS</button>
        </form>
        <form method="POST" action="{{ route('admin.workspace.start', $tenant) }}" class="inline">
            @csrf
            <input type="hidden" name="return_tab" value="support">
            <button type="submit" {{ $canManageWorkspace ? '' : 'disabled' }}>Start</button>
        </form>
        <form method="POST" action="{{ route('admin.workspace.stop', $tenant) }}" class="inline">
            @csrf
            <input type="hidden" name="return_tab" value="support">
            <button type="submit" {{ $canManageWorkspace ? '' : 'disabled' }}>Stop</button>
        </form>
        <form method="POST" action="{{ route('admin.workspace.restart', $tenant) }}" class="inline">
            @csrf
            <input type="hidden" name="return_tab" value="support">
            <button type="submit" {{ $canManageWorkspace ? '' : 'disabled' }}>Restart</button>
        </form>
    </div>

    <div class="hint" style="margin-top: 14px;">
        Bootstrap VPS installs host-managed runtime dependencies on the assigned client VPS. Workspace controls only manage the running tenant container and do not repair Google auth/runtime state.
    </div>
</section>

<section class="panel danger-panel">
    <div class="topbar" style="margin-bottom: 16px;">
        <div>
            <span class="eyebrow">Danger Zone</span>
            <h2 class="type-section-title">Permanent Delete</h2>
            <p class="type-body">This removes the control-app tenant record, linked customer account, tenant runtime, and LiteLLM resources permanently.</p>
        </div>
    </div>

    <div class="note error" style="margin-bottom: 16px;">
        This action is irreversible. Type <strong>{{ $tenant->slug }}</strong> exactly to enable permanent deletion.
    </div>

    <form method="POST" action="{{ route('admin.tenants.destroy', $tenant) }}" class="field-single">
        @csrf
        @method('DELETE')
        <input type="hidden" name="return_tab" value="support">
        <label>
            Confirm Tenant Slug
            <input
                type="text"
                name="confirmation_slug"
                placeholder="{{ $tenant->slug }}"
                data-delete-confirmation
                data-expected-slug="{{ $tenant->slug }}"
                autocomplete="off"
            >
        </label>
        <div style="display: flex; justify-content: flex-end;">
            <button type="submit" class="button button--danger" data-delete-submit disabled>Delete Tenant Permanently</button>
        </div>
    </form>
</section>
