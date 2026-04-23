<x-ui.panel title="Google Workspace Connection" description="Connection state, live sync progress, latest runtime error, and repair actions.">
    <x-slot:actions>
        <x-ui.badge :status="$googleState['connection_badge']" technical>{{ $googleState['connection_label'] }}</x-ui.badge>
        <x-ui.badge :status="$googleState['runtime_badge']" technical>{{ $googleState['runtime_label'] }}</x-ui.badge>
        @if ($googleState['sync_job_status'])
            <x-ui.badge :status="$googleState['sync_job_badge']" technical>{{ $googleState['sync_job_status'] }}</x-ui.badge>
        @endif
    </x-slot:actions>

    <div class="sync-poc-detail-grid">
        <div class="sync-poc-field">
            <span class="sync-poc-field__label">Google Email</span>
            <strong class="sync-poc-field__value">{{ $googleState['google_email'] ?? 'No Google account saved' }}</strong>
        </div>
        <div class="sync-poc-field">
            <span class="sync-poc-field__label">Connection Status</span>
            <strong class="sync-poc-field__value">{{ $googleState['connection_label'] }}</strong>
        </div>
        <div class="sync-poc-field">
            <span class="sync-poc-field__label">Live Access</span>
            <strong class="sync-poc-field__value">{{ $googleState['runtime_label'] }}</strong>
        </div>
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

    <div class="sync-poc-panel-actions" style="margin-top: 18px;">
        <form method="POST" action="{{ route('admin.tenants.google.sync', $tenant) }}" class="inline">
            @csrf
            <input type="hidden" name="return_tab" value="google">
            <x-ui.button type="submit" size="sm" icon="refresh-cw" :disabled="! $googleState['can_queue_sync']">Queue Google Sync</x-ui.button>
        </form>
        <form method="POST" action="{{ route('admin.tenants.runtime-capabilities.sync', $tenant) }}" class="inline">
            @csrf
            <input type="hidden" name="return_tab" value="google">
            <x-ui.button type="submit" size="sm" icon="wrench" :disabled="! $canManageWorkspace">Sync Runtime Capabilities</x-ui.button>
        </form>
        <form method="POST" action="{{ route('admin.tenants.google.test', $tenant) }}" class="inline">
            @csrf
            <input type="hidden" name="return_tab" value="google">
            <x-ui.button type="submit" size="sm" icon="activity" :disabled="! ($canManageWorkspace && $googleConnected)">Test Google Workspace</x-ui.button>
        </form>
    </div>

    <div class="hint" style="margin-top: 14px;">
        Queue Google Sync reuses the initial Google sync job flow. Sync Runtime Capabilities repairs the <code>gog</code> runtime contract. Test Google Workspace runs the tenant-side smoke verification.
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
