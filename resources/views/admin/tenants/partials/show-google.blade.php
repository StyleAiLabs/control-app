<section class="panel">
    <div class="topbar" style="margin-bottom: 16px;">
        <div>
            <span class="eyebrow">Google Workspace</span>
            <h2 class="type-section-title">Google Workspace Connection</h2>
            <p class="type-body">Connection state, live sync progress, latest runtime error, and the repair actions that already exist in this control plane.</p>
        </div>
    </div>

    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 18px;">
        <span class="badge badge--technical {{ $googleState['connection_badge'] }}">{{ $googleState['connection_label'] }}</span>
        <span class="badge badge--technical {{ $googleState['runtime_badge'] }}">{{ $googleState['runtime_label'] }}</span>
        @if ($googleState['sync_job_status'])
            <span class="badge badge--technical {{ $googleState['sync_job_badge'] }}">{{ $googleState['sync_job_status'] }}</span>
        @endif
    </div>

    <div class="meta">
        <div class="meta-item">
            <small class="type-label">Google Email</small>
            <strong class="type-value">{{ $googleState['google_email'] ?? 'No Google account saved' }}</strong>
        </div>
        <div class="meta-item">
            <small class="type-label">Connection Status</small>
            <strong class="type-value type-value--technical">{{ $googleState['connection_label'] }}</strong>
        </div>
        <div class="meta-item">
            <small class="type-label">Live Access</small>
            <strong class="type-value type-value--technical">{{ $googleState['runtime_label'] }}</strong>
        </div>
        <div class="meta-item">
            <small class="type-label">Connected At</small>
            <strong class="type-value type-value--technical">{{ $googleState['connected_at'] ?? '—' }}</strong>
        </div>
        <div class="meta-item">
            <small class="type-label">Disconnected At</small>
            <strong class="type-value type-value--technical">{{ $googleState['disconnected_at'] ?? '—' }}</strong>
        </div>
        <div class="meta-item">
            <small class="type-label">Last Synced At</small>
            <strong class="type-value type-value--technical">{{ $googleState['last_synced_at'] ?? '—' }}</strong>
        </div>
        <div class="meta-item">
            <small class="type-label">Initial Sync Job</small>
            <strong class="type-value type-value--technical">{{ $googleState['sync_job_status'] ?? 'No sync job recorded' }}</strong>
        </div>
        <div class="meta-item">
            <small class="type-label">Sync Job Started</small>
            <strong class="type-value type-value--technical">{{ $googleState['sync_job_started_at'] ?? '—' }}</strong>
        </div>
        <div class="meta-item">
            <small class="type-label">Sync Job Completed</small>
            <strong class="type-value type-value--technical">{{ $googleState['sync_job_completed_at'] ?? '—' }}</strong>
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
