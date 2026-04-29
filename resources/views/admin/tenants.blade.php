<x-layouts.app title="Admin Tenants">
    @php
        $totalTenants = $tenants->count();
        $readyNowCount = $tenants->filter(function ($tenant) use ($workspaceStates, $agentStates): bool {
            $workspaceState = $workspaceStates[$tenant->id] ?? 'unknown';
            $agentState = $agentStates[$tenant->id] ?? ['value' => 'offline'];

            return $tenant->provisioning_status->value === 'ready'
                && ($agentState['value'] ?? 'offline') === 'live'
                && $workspaceState === 'running';
        })->count();
        $attentionCount = $tenants->filter(function ($tenant) use ($workspaceStates, $googleStates, $agentStates): bool {
            $workspaceState = $workspaceStates[$tenant->id] ?? 'unknown';
            $agentState = $agentStates[$tenant->id] ?? ['value' => 'offline'];
            $googleState = $googleStates[$tenant->id] ?? [];
            $trialUrgency = $tenant->isTrialExpired() ? 'expired' : $tenant->trialUrgency();

            return $tenant->provisioning_status->value === 'failed'
                || in_array($agentState['value'] ?? 'offline', ['paused', 'failed', 'offline'], true)
                || $tenant->last_health_check_status === 'failed'
                || in_array($workspaceState, ['failed', 'stopped', 'unknown'], true)
                || in_array($googleState['connection_badge'] ?? 'pending', ['failed', 'error'], true)
                || in_array($googleState['runtime_badge'] ?? 'pending', ['failed', 'error'], true)
                || in_array($trialUrgency, ['warning', 'critical', 'expired'], true);
        })->count();
        $workspaceStoppedCount = $tenants->filter(function ($tenant) use ($workspaceStates): bool {
            return ($workspaceStates[$tenant->id] ?? 'unknown') !== 'running';
        })->count();
    @endphp

    <div class="topbar">
        <div>
            <span class="eyebrow">Admin Tenants</span>
            <h2>Tenant Records</h2>
            <p>Review the most important tenant signals here, then open a tenant to inspect operational details or perform destructive actions.</p>
        </div>
    </div>

    <div class="sync-poc-summary-grid sync-poc-summary-grid--tenants" aria-label="Tenant scan summary">
        <section class="sync-poc-summary-item">
            <span class="sync-poc-summary-label">Tenants In View</span>
            <strong class="sync-poc-summary-value">{{ $totalTenants }}</strong>
            <span class="sync-poc-summary-note">Identity stays visible before secondary metadata truncates.</span>
        </section>
        <section class="sync-poc-summary-item">
            <span class="sync-poc-summary-label">Needs Attention</span>
            <strong class="sync-poc-summary-value">{{ $attentionCount }}</strong>
            <span class="sync-poc-summary-note">Provisioning, runtime, health, and trial risk surface here first.</span>
        </section>
        <section class="sync-poc-summary-item">
            <span class="sync-poc-summary-label">Workspace Not Running</span>
            <strong class="sync-poc-summary-value">{{ $workspaceStoppedCount }}</strong>
            <span class="sync-poc-summary-note">{{ $readyNowCount }} tenant{{ $readyNowCount === 1 ? '' : 's' }} ready for customer traffic right now.</span>
        </section>
    </div>

    <x-ui.panel class="admin-tenants-poc" aria-label="Tenant records table">
        <x-ui.table fit>
            <colgroup>
                <col style="width: 27%;">
                <col style="width: 18%;">
                <col style="width: 10%;">
                <col style="width: 17%;">
                <col style="width: 8%;">
                <col style="width: 8%;">
                <col style="width: 12%;">
            </colgroup>
            <thead>
                <tr>
                    <th>Tenant</th>
                    <th>Runtime</th>
                    <th>Provisioning</th>
                    <th>Google</th>
                    <th>Agent</th>
                    <th>Trial</th>
                    <th>Health</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tenants as $tenant)
                    @php
                        $workspaceState = $workspaceStates[$tenant->id] ?? 'unknown';
                        $googleState = $googleStates[$tenant->id] ?? null;
                        $agentState = $agentStates[$tenant->id] ?? ['status' => 'offline', 'value' => 'offline'];
                        $agentStatus = $agentState['status'];
                        $healthStatus = $tenant->last_health_check_status === 'healthy' ? 'healthy' : ($tenant->last_health_check_status === 'failed' ? 'failed' : 'unchecked');
                        $workspaceBadgeStatus = $workspaceState === 'running' ? 'running' : ($workspaceState === 'stopped' ? 'stopped' : ($workspaceState === 'unknown' ? 'unchecked' : 'failed'));
                        $workspaceLabel = str_replace('_', ' ', $workspaceState);
                        $serverName = $tenant->server?->name ?? 'Unassigned VPS';
                        $serverHost = $tenant->server?->host ?? 'No host recorded';
                        $workspaceUrl = $tenant->workspace_url ?? 'Workspace URL pending';
                        $customerName = $tenant->user?->name ?? 'No customer linked';
                        $customerEmail = $tenant->user?->email ?? 'No customer email saved';
                        $googleConnectionLabel = $googleState['connection_label'] ?? 'Pending';
                        $googleRuntimeLabel = $googleState['runtime_label'] ?? 'Pending';
                        $googleAccountLabel = $googleState['google_email'] ?? 'No Google account saved';
                        $googleMeta = null;

                        if (filled($googleState['last_error'] ?? null)) {
                            $googleMeta = \Illuminate\Support\Str::limit($googleState['last_error'], 120);
                        } elseif (filled($googleState['last_timestamp_label'] ?? null) && filled($googleState['last_timestamp'] ?? null)) {
                            $googleMeta = $googleState['last_timestamp_label'].': '.$googleState['last_timestamp'];
                        }
                    @endphp
                    <tr
                        class="clickable-row clickable-row--tenant"
                        data-href="{{ route('admin.tenants.show', $tenant) }}"
                        tabindex="0"
                    >
                        <td>
                            <div class="sync-poc-table-cell-stack sync-poc-table-cell-stack--roomy">
                                <div class="sync-poc-open-row">
                                    <a href="{{ route('admin.tenants.show', $tenant) }}" class="sync-poc-table-link" title="{{ $tenant->business_name }}">
                                        <strong class="sync-poc-table-title sync-poc-clamp-2">{{ $tenant->business_name }}</strong>
                                    </a>
                                    <span class="sync-poc-open-hint" aria-hidden="true">
                                        <x-ui.icon name="chevron-right" size="sm" />
                                    </span>
                                </div>
                                <span class="hint sync-poc-truncate" title="{{ $tenant->slug }}">{{ $tenant->slug }}</span>
                                <div class="sync-poc-table-cell-stack">
                                    <span class="sync-poc-state-value sync-poc-truncate" title="{{ $customerName }}">{{ $customerName }}</span>
                                    <span class="hint sync-poc-truncate" title="{{ $customerEmail }}">{{ $customerEmail }}</span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="sync-poc-table-cell-stack sync-poc-table-cell-stack--roomy">
                                <div class="sync-poc-state-row">
                                    <x-ui.icon name="server" size="sm" class="mt-0.5 text-base-content/55" />
                                    <div class="sync-poc-state-copy">
                                        <span class="sync-poc-state-label">Client VPS</span>
                                        <span class="sync-poc-state-value sync-poc-truncate" title="{{ $serverName }}">{{ $serverName }}</span>
                                        <span class="hint sync-poc-truncate" title="{{ $serverHost }}">{{ $serverHost }}</span>
                                    </div>
                                </div>
                                <div class="sync-poc-state-copy">
                                    <span class="sync-poc-state-label">Workspace URL</span>
                                    <span class="sync-poc-state-value sync-poc-state-value--technical sync-poc-truncate" title="{{ $workspaceUrl }}">{{ $workspaceUrl }}</span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <x-ui.badge :status="$tenant->provisioning_status->value">
                                {{ $tenant->provisioning_status->value }}
                            </x-ui.badge>
                        </td>
                        <td>
                            <div class="sync-poc-state-list sync-poc-state-list--dense">
                                <div class="sync-poc-state-row">
                                    <x-ui.status-icon :status="$googleState['connection_badge'] ?? 'pending'" :label="'Google connection '.$googleConnectionLabel" />
                                    <div class="sync-poc-state-copy">
                                        <span class="sync-poc-state-label">Connection</span>
                                        <span class="sync-poc-state-value sync-poc-truncate" title="{{ $googleConnectionLabel }}">{{ $googleConnectionLabel }}</span>
                                    </div>
                                </div>
                                <div class="sync-poc-state-row">
                                    <x-ui.status-icon :status="$googleState['runtime_badge'] ?? 'pending'" :label="'Google runtime '.$googleRuntimeLabel" />
                                    <div class="sync-poc-state-copy">
                                        <span class="sync-poc-state-label">Runtime</span>
                                        <span class="sync-poc-state-value sync-poc-truncate" title="{{ $googleRuntimeLabel }}">{{ $googleRuntimeLabel }}</span>
                                    </div>
                                </div>
                                <div class="hint sync-poc-table-note sync-poc-table-note--tight sync-poc-truncate" title="{{ $googleAccountLabel }}">
                                    {{ $googleAccountLabel }}
                                </div>
                                @if (filled($googleMeta))
                                    <div class="hint sync-poc-table-note sync-poc-table-note--tight sync-poc-truncate" title="{{ $googleMeta }}">
                                        {{ $googleMeta }}
                                    </div>
                                @endif
                            </div>
                        </td>
                        <td>
                            <x-ui.badge :status="$agentStatus">
                                {{ $agentState['value'] }}
                            </x-ui.badge>
                        </td>
                        <td>
                            @if ($tenant->hasPaidActivation())
                                <x-ui.badge :status="$tenant->isBillingActive() ? 'ready' : ($tenant->isBillingPastDue() ? 'warning' : 'failed')">
                                    {{ $tenant->billing_status?->label() ?? 'Unknown' }}
                                </x-ui.badge>
                                <div class="hint sync-poc-table-note sync-poc-table-note--tight">
                                    {{ $tenant->billing_plan ? \Illuminate\Support\Str::headline((string) $tenant->billing_plan) : 'Plan pending' }}
                                </div>
                            @elseif ($tenant->isTrialExpired())
                                <x-ui.badge status="expired">expired</x-ui.badge>
                            @else
                                @php
                                    $urgency = $tenant->trialUrgency();
                                    $daysLeft = $tenant->trialDaysLeft();
                                @endphp
                                <x-ui.badge
                                    :status="$urgency === 'critical' ? 'failed' : ($urgency === 'warning' ? 'warning' : 'ready')"
                                    class="sync-poc-badge--trial"
                                >
                                    {{ $daysLeft }}d left
                                </x-ui.badge>
                                <div class="hint sync-poc-table-note sync-poc-table-note--tight">
                                    ${{ number_format((float)($tenant->litellm_spend ?? 0), 2) }} / ${{ number_format((float)($tenant->litellm_max_budget ?? 5), 2) }}
                                </div>
                            @endif
                        </td>
                        <td>
                            <div class="sync-poc-state-list sync-poc-state-list--dense">
                                <div class="sync-poc-state-row">
                                    <x-ui.status-icon :status="$healthStatus" :label="'Tenant health '.($tenant->last_health_check_status ?? 'unchecked')" />
                                    <div class="sync-poc-state-copy">
                                        <span class="sync-poc-state-label">Health</span>
                                        <span class="sync-poc-state-value sync-poc-truncate" title="{{ $tenant->last_health_check_status ?? 'unchecked' }}">{{ $tenant->last_health_check_status ?? 'unchecked' }}</span>
                                    </div>
                                </div>
                                <div class="sync-poc-state-row">
                                    <x-ui.status-icon :status="$workspaceBadgeStatus" :label="'Workspace '.ucfirst($workspaceLabel)" />
                                    <div class="sync-poc-state-copy">
                                        <span class="sync-poc-state-label">Workspace</span>
                                        <span class="sync-poc-state-value sync-poc-truncate" title="{{ ucfirst($workspaceLabel) }}">{{ ucfirst($workspaceLabel) }}</span>
                                    </div>
                                </div>
                                <div class="hint sync-poc-table-note sync-poc-table-note--tight sync-poc-truncate" title="{{ $tenant->health_check_message ?? 'No health check run yet.' }}">
                                    {{ $tenant->health_check_message ?? 'No health check run yet.' }}
                                </div>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
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
