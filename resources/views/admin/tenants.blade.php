<x-layouts.app title="Admin Tenants">
    <div class="topbar">
        <div>
            <span class="eyebrow">Admin Tenants</span>
            <h2>Tenant Records</h2>
            <p>Review the most important tenant signals here, then open a tenant to inspect operational details or perform destructive actions.</p>
        </div>
    </div>

    <section class="panel table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Business</th>
                    <th>Customer</th>
                    <th>Client VPS</th>
                    <th>Provisioning</th>
                    <th>Agent</th>
                    <th>Health</th>
                    <th>Workspace</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tenants as $tenant)
                    @php
                        $workspaceState = $workspaceStates[$tenant->id] ?? 'unknown';
                    @endphp
                    <tr class="clickable-row" data-href="{{ route('admin.tenants.show', $tenant) }}" tabindex="0">
                        <td>
                            <a href="{{ route('admin.tenants.show', $tenant) }}"><strong>{{ $tenant->business_name }}</strong></a><br>
                            <span class="hint">{{ $tenant->slug }}</span>
                        </td>
                        <td>
                            {{ $tenant->user?->name }}<br>
                            <span class="hint">{{ $tenant->user?->email }}</span>
                        </td>
                        <td>
                            {{ $tenant->server?->name ?? 'Unassigned' }}<br>
                            <span class="hint">{{ $tenant->server?->host ?? '—' }}</span>
                        </td>
                        <td><span class="badge {{ $tenant->provisioning_status->value }}">{{ $tenant->provisioning_status->value }}</span></td>
                        <td><span class="badge {{ $tenant->agent_status === 'live' ? 'ready' : ($tenant->agent_status === 'failed' ? 'failed' : 'pending') }}">{{ $tenant->agent_status ?? 'offline' }}</span></td>
                        <td>
                            <span class="badge {{ $tenant->last_health_check_status === 'healthy' ? 'ready' : ($tenant->last_health_check_status === 'failed' ? 'failed' : 'pending') }}">
                                {{ $tenant->last_health_check_status ?? 'unchecked' }}
                            </span>
                            <div class="hint" style="margin-top: 6px;">
                                {{ $tenant->health_check_message ?? 'No health check run yet.' }}
                            </div>
                        </td>
                        <td>
                            <span class="badge {{ $workspaceState === 'running' ? 'ready' : ($workspaceState === 'stopped' ? 'pending' : 'failed') }}">{{ str_replace('_', ' ', $workspaceState) }}</span>
                            <div class="hint" style="margin-top: 6px;">
                                {{ $tenant->workspace_url ?? 'Workspace URL pending' }}
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">No tenants found yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <script>
        (() => {
            document.querySelectorAll('.clickable-row').forEach((row) => {
                const href = row.dataset.href;
                if (!href) return;

                row.addEventListener('click', (event) => {
                    if (event.target.closest('a, button, input, textarea, select, form')) return;
                    window.location = href;
                });

                row.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        window.location = href;
                    }
                });
            });
        })();
    </script>
</x-layouts.app>
