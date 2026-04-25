@php
    $recommendedAction = match (true) {
        ! $googleConnected => [
            'title' => 'Reconnect customer Google account',
            'detail' => 'No Google account is saved for this tenant yet. Finish the customer connection flow before tenant-side sync and smoke tests will succeed.',
            'primary' => null,
        ],
        ! $canManageWorkspace => [
            'title' => 'Repair workspace availability first',
            'detail' => 'Google runtime actions are blocked until the tenant workspace is provisioned and configured again.',
            'primary' => null,
        ],
        $googleState['can_queue_sync'] => [
            'title' => 'Queue Google Sync next',
            'detail' => 'The account is connected. Queue the sync job to seed runtime access and refresh the live Google state.',
            'primary' => 'sync',
        ],
        ($googleState['runtime_badge'] ?? 'pending') !== 'ready' => [
            'title' => 'Repair runtime capabilities',
            'detail' => 'The customer connection exists, but live access is still not ready. Repair the runtime contract, then verify again.',
            'primary' => 'runtime',
        ],
        default => [
            'title' => 'Run a fresh smoke test',
            'detail' => 'Connection and runtime both look ready. Run the tenant-side verification if you need current evidence before troubleshooting anything else.',
            'primary' => 'test',
        ],
    };
@endphp

<x-ui.panel title="Google Workspace Connection" description="Current connection state, the best next repair step, and the latest runtime evidence.">
    <x-slot:actions>
        <x-ui.badge :status="$googleState['connection_badge']">{{ $googleState['connection_label'] }}</x-ui.badge>
        <x-ui.badge :status="$googleState['runtime_badge']">{{ $googleState['runtime_label'] }}</x-ui.badge>
        <x-ui.badge :status="match($googleState['health_status'] ?? null) {
            'healthy' => 'ready',
            'expiring_soon' => 'warning',
            'reconnect_required' => 'failed',
            'degraded' => 'warning',
            default => 'pending',
        }">{{ $googleState['health_label'] ?? 'Pending' }}</x-ui.badge>
        @if ($googleState['sync_job_status'])
            <x-ui.badge :status="$googleState['sync_job_badge']">{{ $googleState['sync_job_status'] }}</x-ui.badge>
        @endif
    </x-slot:actions>

    <div class="sync-poc-detail-grid sync-poc-detail-grid--wide">
        <section class="sync-poc-subpanel">
            <span class="eyebrow">Current State</span>
            <div class="sync-poc-state-list" style="margin-top: 12px;">
                <div class="sync-poc-state-row">
                    <x-ui.status-icon :status="$googleState['connection_badge']" :label="'Google connection '.$googleState['connection_label']" />
                    <div class="sync-poc-state-copy">
                        <span class="sync-poc-state-label">Connection</span>
                        <span class="sync-poc-state-value">{{ $googleState['connection_label'] }}</span>
                    </div>
                </div>
                <div class="sync-poc-state-row">
                    <x-ui.status-icon :status="match($googleState['health_status'] ?? null) {
                        'healthy' => 'ready',
                        'expiring_soon' => 'warning',
                        'reconnect_required' => 'failed',
                        'degraded' => 'warning',
                        default => $googleState['runtime_badge'],
                    }" :label="'Google health '.($googleState['health_label'] ?? $googleState['runtime_label'])" />
                    <div class="sync-poc-state-copy">
                        <span class="sync-poc-state-label">Health</span>
                        <span class="sync-poc-state-value">{{ $googleState['health_label'] ?? $googleState['runtime_label'] }}</span>
                    </div>
                </div>
                @if ($googleState['sync_job_status'])
                    <div class="sync-poc-state-row">
                        <x-ui.status-icon :status="$googleState['sync_job_badge']" :label="'Google sync job '.$googleState['sync_job_status']" />
                        <div class="sync-poc-state-copy">
                            <span class="sync-poc-state-label">Sync Job</span>
                            <span class="sync-poc-state-value">{{ $googleState['sync_job_status'] }}</span>
                        </div>
                    </div>
                @endif
            </div>

            <div class="sync-poc-field" style="margin-top: 14px;">
                <span class="sync-poc-field__label">Google Email</span>
                <strong class="sync-poc-field__value">{{ $googleState['google_email'] ?? 'No Google account saved' }}</strong>
            </div>
        </section>

        <section class="sync-poc-subpanel">
            <span class="eyebrow">Recommended Next Step</span>
            <h3 class="type-section-title" style="margin-top: 12px;">{{ $recommendedAction['title'] }}</h3>
            <p class="type-body" style="margin: 10px 0 0;">{{ $recommendedAction['detail'] }}</p>
            @if (filled($googleState['health_note'] ?? null))
                <div class="hint" style="margin-top: 12px;">{{ $googleState['health_note'] }}</div>
            @endif

            <div class="sync-poc-panel-actions" style="margin-top: 16px;">
                <form method="POST" action="{{ route('admin.tenants.google.sync', $tenant) }}" class="inline">
                    @csrf
                    <input type="hidden" name="return_tab" value="google">
                    <x-ui.button type="submit" size="sm" icon="refresh-cw" :variant="$recommendedAction['primary'] === 'sync' ? 'primary' : 'secondary'" :disabled="! $googleState['can_queue_sync']">Queue Google Sync</x-ui.button>
                </form>
                <form method="POST" action="{{ route('admin.tenants.runtime-capabilities.sync', $tenant) }}" class="inline">
                    @csrf
                    <input type="hidden" name="return_tab" value="google">
                    <x-ui.button type="submit" size="sm" icon="wrench" :variant="$recommendedAction['primary'] === 'runtime' ? 'primary' : 'secondary'" :disabled="! $canManageWorkspace">Sync Runtime Capabilities</x-ui.button>
                </form>
                <form method="POST" action="{{ route('admin.tenants.google.test', $tenant) }}" class="inline">
                    @csrf
                    <input type="hidden" name="return_tab" value="google">
                    <x-ui.button type="submit" size="sm" icon="activity" :variant="$recommendedAction['primary'] === 'test' ? 'primary' : 'secondary'" :disabled="! ($canManageWorkspace && $googleConnected)">Test Google Workspace</x-ui.button>
                </form>
            </div>

            <div class="hint" style="margin-top: 14px;">
                Queue Google Sync reuses the initial Google sync job flow. Sync Runtime Capabilities repairs the <code>gog</code> runtime contract. Test Google Workspace runs the tenant-side smoke verification.
            </div>
        </section>
    </div>
</x-ui.panel>

<x-ui.panel title="Google Sync Evidence" description="Recent timestamps, job evidence, and the latest runtime error captured for this tenant.">
    <div class="sync-poc-detail-grid">
        <div class="sync-poc-field">
            <span class="sync-poc-field__label">Connected At</span>
            <strong class="sync-poc-field__value">{{ $googleState['connected_at'] ?? '—' }}</strong>
        </div>
        <div class="sync-poc-field">
            <span class="sync-poc-field__label">Disconnected At</span>
            <strong class="sync-poc-field__value">{{ $googleState['disconnected_at'] ?? '—' }}</strong>
        </div>
        <div class="sync-poc-field">
            <span class="sync-poc-field__label">Last Synced At</span>
            <strong class="sync-poc-field__value">{{ $googleState['last_synced_at'] ?? '—' }}</strong>
        </div>
        <div class="sync-poc-field">
            <span class="sync-poc-field__label">Last Health Check</span>
            <strong class="sync-poc-field__value">{{ $googleState['last_health_checked_at'] ?? '—' }}</strong>
        </div>
        <div class="sync-poc-field">
            <span class="sync-poc-field__label">Last Verified</span>
            <strong class="sync-poc-field__value">{{ $googleState['last_verified_at'] ?? '—' }}</strong>
        </div>
        <div class="sync-poc-field">
            <span class="sync-poc-field__label">Predicted Expiry</span>
            <strong class="sync-poc-field__value">{{ $googleState['predicted_expiry_at'] ?? '—' }}</strong>
        </div>
        <div class="sync-poc-field">
            <span class="sync-poc-field__label">Initial Sync Job</span>
            <strong class="sync-poc-field__value">{{ $googleState['sync_job_status'] ?? 'No sync job recorded' }}</strong>
        </div>
        <div class="sync-poc-field">
            <span class="sync-poc-field__label">Sync Job Started</span>
            <strong class="sync-poc-field__value">{{ $googleState['sync_job_started_at'] ?? '—' }}</strong>
        </div>
        <div class="sync-poc-field">
            <span class="sync-poc-field__label">Sync Job Completed</span>
            <strong class="sync-poc-field__value">{{ $googleState['sync_job_completed_at'] ?? '—' }}</strong>
        </div>
    </div>

    @if (filled($googleState['last_error']))
        <div class="note error" style="margin-top: 16px;">
            {{ $googleState['last_error'] }}
        </div>
    @endif

    @if (filled($googleState['sync_job_error']) && $googleState['sync_job_error'] !== $googleState['last_error'])
        <div class="note error" style="margin-top: 16px;">
            Latest sync job error: {{ $googleState['sync_job_error'] }}
        </div>
    @endif
</x-ui.panel>
