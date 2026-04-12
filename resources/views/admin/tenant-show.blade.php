<x-layouts.app title="Tenant Detail">
    @php
        $channelConfig = is_array($tenant->channel_config) ? $tenant->channel_config : [];
        $canManageWorkspace = ! in_array($workspaceState, ['not_provisioned', 'missing_config'], true);
    @endphp

    <div class="topbar">
        <div>
            <span class="eyebrow">Tenant Detail</span>
            <h2>{{ $tenant->business_name }}</h2>
            <p>Operational details, customer context, runtime metadata, and permanent deletion live here.</p>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="{{ route('admin.tenants') }}" class="button button--secondary">Back to Tenants</a>
            @if ($tenant->workspace_url)
                <a href="{{ $tenant->workspace_url }}" class="button button--secondary" target="_blank" rel="noreferrer">Open Customer Workspace URL</a>
            @endif
        </div>
    </div>

    <section class="panel">
        <div class="topbar" style="margin-bottom: 16px;">
            <div>
                <span class="eyebrow">Summary</span>
                <h2 style="font-size: 1.35rem;">Tenant Status</h2>
                <p>Quick status for provisioning, agent sync, health, and workspace runtime.</p>
            </div>
        </div>

        <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 18px;">
            <span class="badge {{ $tenant->provisioning_status->value }}">{{ $tenant->provisioning_status->value }}</span>
            <span class="badge {{ $tenant->agent_status === 'live' ? 'ready' : ($tenant->agent_status === 'failed' ? 'failed' : 'pending') }}">{{ $tenant->agent_status ?? 'offline' }}</span>
            <span class="badge {{ $tenant->last_health_check_status === 'healthy' ? 'ready' : ($tenant->last_health_check_status === 'failed' ? 'failed' : 'pending') }}">{{ $tenant->last_health_check_status ?? 'unchecked' }}</span>
            <span class="badge {{ $workspaceState === 'running' ? 'ready' : ($workspaceState === 'stopped' ? 'pending' : 'failed') }}">{{ str_replace('_', ' ', $workspaceState) }}</span>
        </div>

        <div class="meta">
            <div class="meta-item">
                <small>Tenant Slug</small>
                <strong>{{ $tenant->slug }}</strong>
            </div>
            <div class="meta-item">
                <small>External Tenant ID</small>
                <strong>{{ $tenant->tenant_id }}</strong>
            </div>
            <div class="meta-item">
                <small>Workspace URL</small>
                <strong>{{ $tenant->workspace_url ?? 'Pending' }}</strong>
            </div>
            <div class="meta-item">
                <small>Assigned Port</small>
                <strong>{{ $tenant->assigned_port ?? 'Pending' }}</strong>
            </div>
        </div>
    </section>

    <div class="grid grid-2" style="margin-top: 18px;">
        <section class="panel">
            <div class="topbar" style="margin-bottom: 16px;">
                <div>
                    <span class="eyebrow">Customer</span>
                    <h2 style="font-size: 1.2rem;">Account Details</h2>
                </div>
            </div>
            <div class="meta">
                <div class="meta-item">
                    <small>Name</small>
                    <strong>{{ $tenant->user?->name ?? 'No linked user' }}</strong>
                </div>
                <div class="meta-item">
                    <small>Email</small>
                    <strong>{{ $tenant->user?->email ?? 'No linked user' }}</strong>
                </div>
                <div class="meta-item">
                    <small>Phone</small>
                    <strong>{{ $tenant->user?->phone ?? 'Not provided' }}</strong>
                </div>
                <div class="meta-item">
                    <small>Role Safety</small>
                    <strong>{{ $tenant->user?->is_admin ? 'Admin linked account' : 'Customer account' }}</strong>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="topbar" style="margin-bottom: 16px;">
                <div>
                    <span class="eyebrow">Runtime</span>
                    <h2 style="font-size: 1.2rem;">Server & Workspace</h2>
                </div>
            </div>
            <div class="meta">
                <div class="meta-item">
                    <small>Client VPS</small>
                    <strong>{{ $tenant->server?->name ?? 'Unassigned' }}</strong>
                </div>
                <div class="meta-item">
                    <small>Host</small>
                    <strong>{{ $tenant->server?->host ?? '—' }}</strong>
                </div>
                <div class="meta-item">
                    <small>Runtime Path</small>
                    <strong>{{ $tenant->runtime_path ?? 'Pending' }}</strong>
                </div>
                <div class="meta-item">
                    <small>Last Health Message</small>
                    <strong>{{ $tenant->health_check_message ?? 'No health check run yet.' }}</strong>
                </div>
            </div>
        </section>
    </div>

    <div class="grid grid-2" style="margin-top: 18px;">
        <section class="panel">
            <div class="topbar" style="margin-bottom: 16px;">
                <div>
                    <span class="eyebrow">Onboarding</span>
                    <h2 style="font-size: 1.2rem;">Profile & Channel</h2>
                </div>
            </div>
            <div class="meta">
                <div class="meta-item">
                    <small>Onboarding Status</small>
                    <strong>{{ $tenant->onboarding_status ?? 'not_started' }} (step {{ $tenant->onboarding_step ?? 0 }})</strong>
                </div>
                <div class="meta-item">
                    <small>Tone</small>
                    <strong>{{ $tenant->tone ?? 'Not set' }}</strong>
                </div>
                <div class="meta-item">
                    <small>Capabilities</small>
                    <strong>{{ is_array($tenant->capabilities) && $tenant->capabilities !== [] ? implode(', ', $tenant->capabilities) : 'None saved yet' }}</strong>
                </div>
                <div class="meta-item">
                    <small>Channel</small>
                    <strong>{{ $tenant->channel ?? 'Not connected' }}</strong>
                    @if ($tenant->channel === 'telegram')
                        <div class="hint" style="margin-top: 6px;">Bot token saved: {{ filled($channelConfig['telegram_bot_token'] ?? null) ? 'Yes' : 'No' }}</div>
                    @elseif ($tenant->channel === 'whatsapp')
                        <div class="hint" style="margin-top: 6px;">Phone ID saved: {{ filled($channelConfig['whatsapp_phone_number_id'] ?? null) ? 'Yes' : 'No' }}</div>
                    @endif
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="topbar" style="margin-bottom: 16px;">
                <div>
                    <span class="eyebrow">Latest Job</span>
                    <h2 style="font-size: 1.2rem;">Provisioning Outcome</h2>
                </div>
            </div>
            <div class="meta">
                <div class="meta-item">
                    <small>Job Type</small>
                    <strong>{{ $latestJob?->job_type ?? 'No jobs yet' }}</strong>
                </div>
                <div class="meta-item">
                    <small>Status</small>
                    <strong>{{ $latestJob?->status?->value ?? '—' }}</strong>
                </div>
                <div class="meta-item">
                    <small>Started</small>
                    <strong>{{ $latestJob?->started_at?->toDateTimeString() ?? '—' }}</strong>
                </div>
                <div class="meta-item">
                    <small>Completed</small>
                    <strong>{{ $latestJob?->completed_at?->toDateTimeString() ?? '—' }}</strong>
                </div>
            </div>
            <div class="note{{ $latestJob?->error_message ? ' error' : '' }}" style="margin-top: 16px;">
                {{ $latestJob?->error_message ?? 'No recent provisioning error recorded.' }}
            </div>
        </section>
    </div>

    <section class="panel" style="margin-top: 18px;">
        <div class="topbar" style="margin-bottom: 16px;">
            <div>
                <span class="eyebrow">Support Actions</span>
                <h2 style="font-size: 1.2rem;">Operational Controls</h2>
            </div>
        </div>

        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <form method="POST" action="{{ route('admin.retry', $tenant) }}" class="inline">
                @csrf
                <button type="submit">Retry</button>
            </form>
            <form method="POST" action="{{ route('admin.tenants.health-check', $tenant) }}" class="inline">
                @csrf
                <button type="submit" {{ $canManageWorkspace ? '' : 'disabled' }}>Health Check</button>
            </form>
            <form method="POST" action="{{ route('admin.tenants.resync-agent', $tenant) }}" class="inline">
                @csrf
                <button type="submit" {{ $canManageWorkspace ? '' : 'disabled' }}>Resync Agent</button>
            </form>
            <form method="POST" action="{{ route('admin.workspace.start', $tenant) }}" class="inline">
                @csrf
                <button type="submit" {{ $canManageWorkspace ? '' : 'disabled' }}>Start</button>
            </form>
            <form method="POST" action="{{ route('admin.workspace.stop', $tenant) }}" class="inline">
                @csrf
                <button type="submit" {{ $canManageWorkspace ? '' : 'disabled' }}>Stop</button>
            </form>
            <form method="POST" action="{{ route('admin.workspace.restart', $tenant) }}" class="inline">
                @csrf
                <button type="submit" {{ $canManageWorkspace ? '' : 'disabled' }}>Restart</button>
            </form>
        </div>
    </section>

    <section class="panel danger-panel" style="margin-top: 18px;">
        <div class="topbar" style="margin-bottom: 16px;">
            <div>
                <span class="eyebrow">Danger Zone</span>
                <h2 style="font-size: 1.2rem;">Permanent Delete</h2>
                <p>This removes the control-app tenant record, linked customer account, tenant runtime, and LiteLLM resources permanently.</p>
            </div>
        </div>

        <div class="note error" style="margin-bottom: 16px;">
            This action is irreversible. Type <strong>{{ $tenant->slug }}</strong> exactly to enable permanent deletion.
        </div>

        <form method="POST" action="{{ route('admin.tenants.destroy', $tenant) }}" class="field-single">
            @csrf
            @method('DELETE')
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

    <script>
        (() => {
            const input = document.querySelector('[data-delete-confirmation]');
            const button = document.querySelector('[data-delete-submit]');
            if (!input || !button) return;

            const expected = input.dataset.expectedSlug ?? '';
            const sync = () => {
                button.disabled = input.value.trim() !== expected;
            };

            input.addEventListener('input', sync);
            sync();
        })();
    </script>
</x-layouts.app>
