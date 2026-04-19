<section class="panel">
    <div class="topbar" style="margin-bottom: 16px;">
        <div>
            <span class="eyebrow">Overview</span>
            <h2 class="type-section-title">Tenant Summary</h2>
            <p class="type-body">Quick identifiers, customer context, and the latest provisioning outcome.</p>
        </div>
    </div>

    <div class="meta">
        <div class="meta-item">
            <small class="type-label">Tenant Slug</small>
            <strong class="type-value type-value--technical">{{ $tenant->slug }}</strong>
        </div>
        <div class="meta-item">
            <small class="type-label">External Tenant ID</small>
            <strong class="type-value type-value--technical">{{ $tenant->tenant_id }}</strong>
        </div>
        <div class="meta-item">
            <small class="type-label">Workspace URL</small>
            <strong class="type-value type-value--technical">{{ $tenant->workspace_url ?? 'Pending' }}</strong>
        </div>
        <div class="meta-item">
            <small class="type-label">Assigned Port</small>
            <strong class="type-value type-value--technical">{{ $tenant->assigned_port ?? 'Pending' }}</strong>
        </div>
    </div>
</section>

<div class="grid grid-2">
    <section class="panel">
        <div class="topbar" style="margin-bottom: 16px;">
            <div>
                <span class="eyebrow">Customer</span>
                <h2 class="type-section-title">Account Details</h2>
            </div>
        </div>
        <div class="meta">
            <div class="meta-item">
                <small class="type-label">Name</small>
                <strong class="type-value">{{ $tenant->user?->name ?? 'No linked user' }}</strong>
            </div>
            <div class="meta-item">
                <small class="type-label">Email</small>
                <strong class="type-value">{{ $tenant->user?->email ?? 'No linked user' }}</strong>
            </div>
            <div class="meta-item">
                <small class="type-label">Phone</small>
                <strong class="type-value">{{ $tenant->user?->phone ?? 'Not provided' }}</strong>
            </div>
            <div class="meta-item">
                <small class="type-label">Role Safety</small>
                <strong class="type-value">{{ $tenant->user?->is_admin ? 'Admin linked account' : 'Customer account' }}</strong>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="topbar" style="margin-bottom: 16px;">
            <div>
                <span class="eyebrow">Latest Job</span>
                <h2 class="type-section-title">Provisioning Outcome</h2>
            </div>
        </div>
        <div class="meta">
            <div class="meta-item">
                <small class="type-label">Job Type</small>
                <strong class="type-value type-value--technical">{{ $latestJob?->job_type ?? 'No jobs yet' }}</strong>
            </div>
            <div class="meta-item">
                <small class="type-label">Status</small>
                <strong class="type-value type-value--technical">{{ $latestJob?->status?->value ?? '—' }}</strong>
            </div>
            <div class="meta-item">
                <small class="type-label">Started</small>
                <strong class="type-value type-value--technical">{{ $latestJob?->started_at?->toDateTimeString() ?? '—' }}</strong>
            </div>
            <div class="meta-item">
                <small class="type-label">Completed</small>
                <strong class="type-value type-value--technical">{{ $latestJob?->completed_at?->toDateTimeString() ?? '—' }}</strong>
            </div>
        </div>
        <div class="note{{ $latestJob?->error_message ? ' error' : '' }}" style="margin-top: 16px;">
            {{ $latestJob?->error_message ?? 'No recent provisioning error recorded.' }}
        </div>
    </section>
</div>
