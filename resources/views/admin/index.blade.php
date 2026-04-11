<x-layouts.app title="Admin Debug">
    <div class="topbar">
        <div>
            <span class="eyebrow">Super Admin</span>
            <h2>Admin Overview</h2>
            <p>Monitor user signups, tenant readiness, and provisioning outcomes across the local MVP.</p>
        </div>
        <div style="display: flex; gap: 10px;">
            <a href="{{ route('admin.users') }}" class="button button--secondary">View Users</a>
            <a href="{{ route('admin.tenants') }}" class="button button--primary">View Tenants</a>
            <a href="{{ route('admin.jobs') }}" class="button button--secondary">View Jobs</a>
        </div>
    </div>

    <section class="stats">
        <div class="stat">
            <div class="hint">Users</div>
            <strong>{{ $userCount }}</strong>
        </div>
        <div class="stat">
            <div class="hint">Tenants</div>
            <strong>{{ $tenantCount }}</strong>
        </div>
        <div class="stat">
            <div class="hint">Queued Jobs</div>
            <strong>{{ $jobCounts['queued'] }}</strong>
        </div>
        <div class="stat">
            <div class="hint">Running Jobs</div>
            <strong>{{ $jobCounts['running'] }}</strong>
        </div>
    </section>

    <section class="stats" style="margin-top: 18px;">
        <div class="stat">
            <div class="hint">Completed Jobs</div>
            <strong>{{ $jobCounts['completed'] }}</strong>
        </div>
        <div class="stat">
            <div class="hint">Failed Jobs</div>
            <strong>{{ $jobCounts['failed'] }}</strong>
        </div>
        <div class="stat">
            <div class="hint">Pending Tenants</div>
            <strong>{{ $tenantCounts['pending'] }}</strong>
        </div>
        <div class="stat">
            <div class="hint">Ready Tenants</div>
            <strong>{{ $tenantCounts['ready'] }}</strong>
        </div>
    </section>

    <section class="stats" style="margin-top: 18px; grid-template-columns: repeat(1, minmax(0, 1fr));">
        <div class="stat">
            <div class="hint">Failed Tenants</div>
            <strong>{{ $tenantCounts['failed'] }}</strong>
        </div>
    </section>

    <section class="panel" style="margin-top: 20px;">
        <div class="topbar" style="margin-bottom: 18px;">
            <div>
                <span class="eyebrow">Control App Deploy</span>
                <h2 style="font-size: 1.4rem;">Production Update</h2>
                <p>Fetch the latest deployment branch on the primary server and rebuild the control app safely through the host deploy script.</p>
            </div>
            <div>
                <form method="POST" action="{{ route('admin.deploy.control-app') }}" class="inline">
                    @csrf
                    <button type="submit" {{ !($controlAppDeployStatus['enabled'] ?? false) ? 'disabled' : '' }}>Fetch Latest And Deploy</button>
                </form>
            </div>
        </div>

        <div class="meta">
            <div class="meta-item">
                <small>Deploy State</small>
                <div>
                    <span class="badge {{ match($controlAppDeployStatus['state'] ?? 'not_configured') {
                        'running' => 'running',
                        'succeeded' => 'ready',
                        'failed', 'unreachable' => 'failed',
                        default => 'pending',
                    } }}">{{ str_replace('_', ' ', $controlAppDeployStatus['state'] ?? 'not_configured') }}</span>
                </div>
            </div>
            <div class="meta-item">
                <small>Enabled</small>
                <strong>{{ ($controlAppDeployStatus['enabled'] ?? false) ? 'Yes' : 'No' }}</strong>
            </div>
            <div class="meta-item">
                <small>Primary Server</small>
                <strong>{{ $controlAppDeployStatus['user'] ?? '—' }}@{{ $controlAppDeployStatus['host'] ?? '—' }}</strong>
            </div>
            <div class="meta-item">
                <small>Branch</small>
                <strong>{{ $controlAppDeployStatus['branch'] ?? '—' }}</strong>
            </div>
            <div class="meta-item">
                <small>Started</small>
                <strong>{{ $controlAppDeployStatus['started_at'] ?? '—' }}</strong>
            </div>
            <div class="meta-item">
                <small>Finished</small>
                <strong>{{ $controlAppDeployStatus['finished_at'] ?? '—' }}</strong>
            </div>
        </div>

        @if (!empty($controlAppDeployStatus['message']))
            <div class="note" style="margin-top: 16px;">{{ $controlAppDeployStatus['message'] }}</div>
        @endif

        @if (!empty($controlAppDeployStatus['log_tail']))
            <div class="meta-item" style="margin-top: 16px;">
                <small>Last Deploy Log</small>
                <pre style="margin: 0; white-space: pre-wrap; font-family: 'Space Mono', monospace; font-size: 0.78rem; color: #374151;">{{ $controlAppDeployStatus['log_tail'] }}</pre>
            </div>
        @endif
    </section>
</x-layouts.app>
