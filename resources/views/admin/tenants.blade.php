<x-layouts.app title="Admin Tenants">
    <div class="topbar">
        <div>
            <span class="eyebrow">Admin Tenants</span>
            <h2>Tenant Records</h2>
            <p>Review the most important tenant signals here, then open a tenant to inspect operational details or perform destructive actions.</p>
        </div>
    </div>

    <x-ui.panel class="admin-tenants-poc" aria-label="Tenant records table">
        <x-ui.table fit>
            <colgroup>
                <col style="width: 9%;">
                <col style="width: 14%;">
                <col style="width: 10%;">
                <col style="width: 10%;">
                <col style="width: 15%;">
                <col style="width: 8%;">
                <col style="width: 8%;">
                <col style="width: 11%;">
                <col style="width: 15%;">
            </colgroup>
            <thead>
                <tr>
                    <th>Business</th>
                    <th>Customer</th>
                    <th>Client VPS</th>
                    <th>Provisioning</th>
                    <th>Google</th>
                    <th>Agent</th>
                    <th>Trial</th>
                    <th>Health</th>
                    <th>Workspace</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tenants as $tenant)
                    @php
                        $workspaceState = $workspaceStates[$tenant->id] ?? 'unknown';
                        $googleState = $googleStates[$tenant->id] ?? null;
                        $agentStatus = $tenant->agent_status === 'live' ? 'live' : ($tenant->agent_status === 'failed' ? 'failed' : 'pending');
                        $healthStatus = $tenant->last_health_check_status === 'healthy' ? 'healthy' : ($tenant->last_health_check_status === 'failed' ? 'failed' : 'unchecked');
                        $workspaceBadgeStatus = $workspaceState === 'running' ? 'running' : ($workspaceState === 'stopped' ? 'stopped' : ($workspaceState === 'unknown' ? 'unchecked' : 'failed'));
                    @endphp
                    <tr
                        class="clickable-row"
                        data-href="{{ route('admin.tenants.show', $tenant) }}"
                        tabindex="0"
                    >
                        <td>
                            <div class="sync-poc-table-cell-stack">
                                <a href="{{ route('admin.tenants.show', $tenant) }}" class="sync-poc-truncate" title="{{ $tenant->business_name }}"><strong>{{ $tenant->business_name }}</strong></a>
                                <span class="hint sync-poc-truncate" title="{{ $tenant->slug }}">{{ $tenant->slug }}</span>
                            </div>
                        </td>
                        <td>
                            <div class="sync-poc-table-cell-stack">
                                <span class="sync-poc-truncate" title="{{ $tenant->user?->name }}">{{ $tenant->user?->name }}</span>
                                <span class="hint sync-poc-truncate" title="{{ $tenant->user?->email }}">{{ $tenant->user?->email }}</span>
                            </div>
                        </td>
                        <td>
                            <div class="sync-poc-table-cell-stack">
                                <span class="sync-poc-truncate" title="{{ $tenant->server?->name ?? 'Unassigned' }}">{{ $tenant->server?->name ?? 'Unassigned' }}</span>
                                <span class="hint sync-poc-truncate" title="{{ $tenant->server?->host ?? '—' }}">{{ $tenant->server?->host ?? '—' }}</span>
                            </div>
                        </td>
                        <td>
                            <x-ui.badge :status="$tenant->provisioning_status->value">
                                {{ $tenant->provisioning_status->value }}
                            </x-ui.badge>
                        </td>
                        <td>
                            <x-ui.badge :status="$googleState['connection_badge'] ?? 'pending'">
                                {{ $googleState['connection_label'] ?? 'Pending' }}
                            </x-ui.badge>
                            <div style="margin-top: 6px;">
                                <x-ui.badge :status="$googleState['runtime_badge'] ?? 'pending'">
                                    {{ $googleState['runtime_label'] ?? 'Pending' }}
                                </x-ui.badge>
                            </div>
                            <div class="hint sync-poc-truncate" style="margin-top: 6px;" title="{{ $googleState['google_email'] ?? 'No Google account saved' }}">
                                {{ $googleState['google_email'] ?? 'No Google account saved' }}
                            </div>
                            @if (filled($googleState['last_timestamp_label'] ?? null) && filled($googleState['last_timestamp'] ?? null))
                                <div class="hint sync-poc-truncate" style="margin-top: 6px;" title="{{ $googleState['last_timestamp_label'] }}: {{ $googleState['last_timestamp'] }}">
                                    {{ $googleState['last_timestamp_label'] }}: {{ $googleState['last_timestamp'] }}
                                </div>
                            @endif
                            @if (filled($googleState['last_error'] ?? null))
                                <div class="hint sync-poc-truncate" style="margin-top: 6px;" title="{{ $googleState['last_error'] }}">
                                    {{ \Illuminate\Support\Str::limit($googleState['last_error'], 140) }}
                                </div>
                            @endif
                        </td>
                        <td>
                            <x-ui.badge :status="$agentStatus">
                                {{ $tenant->agent_status ?? 'offline' }}
                            </x-ui.badge>
                        </td>
                        <td>
                            @if ($tenant->isTrialExpired())
                                <x-ui.badge status="expired">expired</x-ui.badge>
                            @else
                                @php
                                    $urgency = $tenant->trialUrgency();
                                    $daysLeft = $tenant->trialDaysLeft();
                                @endphp
                                <x-ui.badge :status="$urgency === 'critical' ? 'failed' : ($urgency === 'warning' ? 'warning' : 'ready')">
                                    {{ $daysLeft }}d left
                                </x-ui.badge>
                                <div class="hint" style="margin-top: 4px;">
                                    ${{ number_format((float)($tenant->litellm_spend ?? 0), 2) }} / ${{ number_format((float)($tenant->litellm_max_budget ?? 5), 2) }}
                                </div>
                            @endif
                        </td>
                        <td>
                            <x-ui.badge :status="$healthStatus">
                                {{ $tenant->last_health_check_status ?? 'unchecked' }}
                            </x-ui.badge>
                            <div class="hint sync-poc-truncate" style="margin-top: 6px;" title="{{ $tenant->health_check_message ?? 'No health check run yet.' }}">
                                {{ $tenant->health_check_message ?? 'No health check run yet.' }}
                            </div>
                        </td>
                        <td>
                            <x-ui.badge :status="$workspaceBadgeStatus">
                                {{ str_replace('_', ' ', $workspaceState) }}
                            </x-ui.badge>
                            <div class="hint sync-poc-truncate" style="margin-top: 6px;" title="{{ $tenant->workspace_url ?? 'Workspace URL pending' }}">
                                {{ $tenant->workspace_url ?? 'Workspace URL pending' }}
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9">
                            <x-ui.empty-state
                                title="No tenants found yet."
                                description="Tenant records will appear here after the first customer signs up."
                            />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </x-ui.table>
    </x-ui.panel>

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
