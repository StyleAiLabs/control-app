<x-layouts.app title="Workspace Placeholder">
    <div class="topbar">
        <div>
            <span class="eyebrow">Workspace Placeholder</span>
            <h2>{{ $tenant->business_name }}</h2>
            <p>This internal route stands in for the future tenant workspace target.</p>
        </div>
    </div>

    <section class="grid grid-2">
        <div class="panel">
            <span class="eyebrow">Tenant Context</span>
            <div class="meta" style="margin-top: 18px;">
                <div class="meta-item">
                    <small>Tenant Slug</small>
                    {{ $tenant->slug }}
                </div>
                <div class="meta-item">
                    <small>Tenant ID</small>
                    {{ $tenant->tenant_id }}
                </div>
                <div class="meta-item">
                    <small>Industry</small>
                    {{ $tenant->industry }}
                </div>
                <div class="meta-item">
                    <small>Modules</small>
                    Core modules included
                </div>
            </div>
        </div>

        <div class="panel">
            <span class="eyebrow">Routing Proof</span>
            <div style="margin-top: 18px; display: grid; gap: 14px;">
                <div>
                    <small class="hint">Generated workspace URL</small>
                    <div style="font-weight: 600; margin-top: 6px;">{{ $tenant->workspace_url }}</div>
                </div>
                <div>
                    <small class="hint">Assigned local port</small>
                    <div style="font-weight: 600; margin-top: 6px;">{{ $tenant->assigned_port }}</div>
                </div>
                <div>
                    <small class="hint">Runtime path</small>
                    <div style="font-weight: 600; margin-top: 6px;">{{ $tenant->runtime_path }}</div>
                </div>
            </div>
        </div>
    </section>
</x-layouts.app>
