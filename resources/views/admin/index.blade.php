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
</x-layouts.app>
