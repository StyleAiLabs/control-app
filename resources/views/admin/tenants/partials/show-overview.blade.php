<section class="panel">
    <div class="topbar" style="margin-bottom: 16px;">
        <div>
            <span class="eyebrow">Overview</span>
            <h2 style="font-size: 1.35rem;">Tenant Summary</h2>
            <p>Quick identifiers, customer context, and the latest provisioning outcome.</p>
        </div>
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

<div class="grid grid-2">
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
