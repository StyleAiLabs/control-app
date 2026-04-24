<x-ui.panel title="Operational Controls" description="Repair, health, and workspace lifecycle actions for this tenant runtime.">
    <div class="tenant-admin-support-grid">
        <section class="sync-poc-subpanel tenant-admin-action-cluster">
            <div class="tenant-admin-action-cluster__intro">
                <span class="eyebrow">Recovery</span>
                <h3 class="type-section-title" style="margin:0;">Repair & Verification</h3>
                <p class="hint" style="margin:0;">Use these when the tenant runtime or customer setup needs intervention.</p>
            </div>

            <div class="tenant-admin-action-row">
                <form method="POST" action="{{ route('admin.tenants.health-check', $tenant) }}" class="inline">
                    @csrf
                    <input type="hidden" name="return_tab" value="support">
                    <x-ui.button type="submit" size="sm" icon="activity" :disabled="! $canManageWorkspace">Health Check</x-ui.button>
                </form>
                <form method="POST" action="{{ route('admin.tenants.resync-agent', $tenant) }}" class="inline">
                    @csrf
                    <input type="hidden" name="return_tab" value="support">
                    <x-ui.button type="submit" size="sm" icon="rotate-ccw" variant="secondary" :disabled="! $canManageWorkspace">Resync Agent</x-ui.button>
                </form>
                <form method="POST" action="{{ route('admin.retry', $tenant) }}" class="inline">
                    @csrf
                    <input type="hidden" name="return_tab" value="support">
                    <x-ui.button type="submit" size="sm" icon="refresh-cw" variant="secondary">Retry</x-ui.button>
                </form>
                <form method="POST" action="{{ route('admin.tenants.runtime.bootstrap', $tenant) }}" class="inline">
                    @csrf
                    <input type="hidden" name="return_tab" value="support">
                    <x-ui.button type="submit" size="sm" icon="server" variant="secondary" :disabled="! $tenant->server">Bootstrap VPS</x-ui.button>
                </form>
            </div>
        </section>

        <section class="sync-poc-subpanel tenant-admin-action-cluster">
            <div class="tenant-admin-action-cluster__intro">
                <span class="eyebrow">Lifecycle</span>
                <h3 class="type-section-title" style="margin:0;">Workspace Lifecycle</h3>
                <p class="hint" style="margin:0;">Start, stop, or recycle the tenant container once the workspace itself is healthy enough to manage.</p>
            </div>

            <div class="tenant-admin-action-row">
                <form method="POST" action="{{ route('admin.workspace.start', $tenant) }}" class="inline">
                    @csrf
                    <input type="hidden" name="return_tab" value="support">
                    <x-ui.button type="submit" size="sm" icon="play" :disabled="! $canManageWorkspace">Start</x-ui.button>
                </form>
                <form method="POST" action="{{ route('admin.workspace.restart', $tenant) }}" class="inline">
                    @csrf
                    <input type="hidden" name="return_tab" value="support">
                    <x-ui.button type="submit" size="sm" icon="refresh-cw" variant="secondary" :disabled="! $canManageWorkspace">Restart</x-ui.button>
                </form>
                <form method="POST" action="{{ route('admin.workspace.stop', $tenant) }}" class="inline">
                    @csrf
                    <input type="hidden" name="return_tab" value="support">
                    <x-ui.button type="submit" size="sm" icon="square" variant="secondary" :disabled="! $canManageWorkspace">Stop</x-ui.button>
                </form>
            </div>
        </section>
    </div>

    <div class="hint tenant-admin-support-note">
        Bootstrap VPS installs host-managed runtime dependencies on the assigned client VPS. Workspace controls only manage the running tenant container and do not repair Google auth/runtime state.
    </div>
</x-ui.panel>

<x-ui.panel title="Permanent Delete" description="Remove the tenant record, linked customer account, runtime, and LiteLLM resources permanently.">
    <x-slot:actions>
        <x-ui.badge status="error" technical>danger zone</x-ui.badge>
    </x-slot:actions>

    <div class="tenant-admin-danger">
        <div class="note error">
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
            <div class="sync-poc-panel-actions tenant-admin-danger__actions">
                <x-ui.button type="submit" variant="danger" icon="trash-2" data-delete-submit disabled>Delete Tenant Permanently</x-ui.button>
            </div>
        </form>
    </div>
</x-ui.panel>
