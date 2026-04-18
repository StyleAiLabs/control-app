<section class="panel">
    <div class="topbar" style="margin-bottom: 16px;">
        <div>
            <span class="eyebrow">Google Workspace</span>
            <h2 style="font-size: 1.2rem;">Google Workspace Connection</h2>
            <p>Connection state, live sync progress, latest runtime error, and the repair actions that already exist in this control plane.</p>
        </div>
    </div>

    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 18px;">
        <span class="badge {{ $googleState['connection_badge'] }}">{{ $googleState['connection_label'] }}</span>
        <span class="badge {{ $googleState['runtime_badge'] }}">{{ $googleState['runtime_label'] }}</span>
        @if ($googleState['sync_job_status'])
            <span class="badge {{ $googleState['sync_job_badge'] }}">{{ $googleState['sync_job_status'] }}</span>
        @endif
    </div>

    <div class="meta">
        <div class="meta-item">
            <small>Google Email</small>
            <strong>{{ $googleState['google_email'] ?? 'No Google account saved' }}</strong>
        </div>
        <div class="meta-item">
            <small>Connection Status</small>
            <strong>{{ $googleState['connection_label'] }}</strong>
        </div>
        <div class="meta-item">
            <small>Live Access</small>
            <strong>{{ $googleState['runtime_label'] }}</strong>
        </div>
        <div class="meta-item">
            <small>Connected At</small>
            <strong>{{ $googleState['connected_at'] ?? '—' }}</strong>
        </div>
        <div class="meta-item">
            <small>Disconnected At</small>
            <strong>{{ $googleState['disconnected_at'] ?? '—' }}</strong>
        </div>
        <div class="meta-item">
            <small>Last Synced At</small>
            <strong>{{ $googleState['last_synced_at'] ?? '—' }}</strong>
        </div>
        <div class="meta-item">
            <small>Initial Sync Job</small>
            <strong>{{ $googleState['sync_job_status'] ?? 'No sync job recorded' }}</strong>
        </div>
        <div class="meta-item">
            <small>Sync Job Started</small>
            <strong>{{ $googleState['sync_job_started_at'] ?? '—' }}</strong>
        </div>
        <div class="meta-item">
            <small>Sync Job Completed</small>
            <strong>{{ $googleState['sync_job_completed_at'] ?? '—' }}</strong>
        </div>
    </div>

    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px;">
        <form method="POST" action="{{ route('admin.tenants.google.sync', $tenant) }}" class="inline">
            @csrf
            <input type="hidden" name="return_tab" value="google">
            <button type="submit" {{ $googleState['can_queue_sync'] ? '' : 'disabled' }}>Queue Google Sync</button>
        </form>
        <form method="POST" action="{{ route('admin.tenants.runtime-capabilities.sync', $tenant) }}" class="inline">
            @csrf
            <input type="hidden" name="return_tab" value="google">
            <button type="submit" {{ $canManageWorkspace ? '' : 'disabled' }}>Sync Runtime Capabilities</button>
        </form>
        <form method="POST" action="{{ route('admin.tenants.google.test', $tenant) }}" class="inline">
            @csrf
            <input type="hidden" name="return_tab" value="google">
            <button type="submit" {{ $canManageWorkspace && $googleConnected ? '' : 'disabled' }}>Test Google Workspace</button>
        </form>
    </div>

    <div class="hint" style="margin-top: 14px;">
        Queue Google Sync reuses the existing initial Google sync job flow. Sync Runtime Capabilities repairs the `gog` runtime contract. Test Google Workspace runs the full tenant-side smoke verification.
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
</section>
