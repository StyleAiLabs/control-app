<x-ui.panel
    title="Tenant Summary"
    description="Quick identifiers, customer context, and the latest provisioning outcome."
>
    <x-slot:actions>
        <x-ui.badge :status="$tenant->provisioning_status->value" technical>
            {{ $tenant->provisioning_status->value }}
        </x-ui.badge>
    </x-slot:actions>

    <div class="sync-poc-detail-grid">
        <div class="sync-poc-field">
            <small class="type-label">Tenant Slug</small>
            <strong class="type-value type-value--technical">{{ $tenant->slug }}</strong>
        </div>
        <div class="sync-poc-field">
            <small class="type-label">External Tenant ID</small>
            <strong class="type-value type-value--technical">{{ $tenant->tenant_id }}</strong>
        </div>
        <div class="sync-poc-field">
            <small class="type-label">Workspace URL</small>
            <strong class="type-value type-value--technical">{{ $tenant->workspace_url ?? 'Pending' }}</strong>
        </div>
        <div class="sync-poc-field">
            <small class="type-label">Assigned Port</small>
            <strong class="type-value type-value--technical">{{ $tenant->assigned_port ?? 'Pending' }}</strong>
        </div>
    </div>
</x-ui.panel>

<div class="sync-poc-detail-grid sync-poc-detail-grid--wide">
    <x-ui.panel title="Account Details">
        <x-slot:actions>
            <x-ui.badge :status="$tenant->user?->is_admin ? 'warning' : 'neutral'" technical>
                {{ $tenant->user?->is_admin ? 'admin linked' : 'customer' }}
            </x-ui.badge>
        </x-slot:actions>

        <div class="sync-poc-detail-grid">
            <div class="sync-poc-field">
                <small class="type-label">Name</small>
                <strong class="type-value">{{ $tenant->user?->name ?? 'No linked user' }}</strong>
            </div>
            <div class="sync-poc-field">
                <small class="type-label">Email</small>
                <strong class="type-value">{{ $tenant->user?->email ?? 'No linked user' }}</strong>
            </div>
            <div class="sync-poc-field">
                <small class="type-label">Phone</small>
                <strong class="type-value">{{ $tenant->user?->phone ?? 'Not provided' }}</strong>
            </div>
            <div class="sync-poc-field">
                <small class="type-label">Role Safety</small>
                <strong class="type-value">{{ $tenant->user?->is_admin ? 'Admin linked account' : 'Customer account' }}</strong>
            </div>
        </div>
    </x-ui.panel>

    <x-ui.panel title="Provisioning Outcome">
        <x-slot:actions>
            <x-ui.badge :status="$latestJob?->status?->value ?? 'neutral'" technical>
                {{ $latestJob?->status?->value ?? 'no jobs' }}
            </x-ui.badge>
        </x-slot:actions>

        <div class="sync-poc-detail-grid">
            <div class="sync-poc-field">
                <small class="type-label">Job Type</small>
                <strong class="type-value type-value--technical">{{ $latestJob?->job_type ?? 'No jobs yet' }}</strong>
            </div>
            <div class="sync-poc-field">
                <small class="type-label">Status</small>
                <strong class="type-value type-value--technical">{{ $latestJob?->status?->value ?? '—' }}</strong>
            </div>
            <div class="sync-poc-field">
                <small class="type-label">Started</small>
                <strong class="type-value type-value--technical">{{ $latestJob?->started_at?->toDateTimeString() ?? '—' }}</strong>
            </div>
            <div class="sync-poc-field">
                <small class="type-label">Completed</small>
                <strong class="type-value type-value--technical">{{ $latestJob?->completed_at?->toDateTimeString() ?? '—' }}</strong>
            </div>
        </div>
        <div class="note{{ $latestJob?->error_message ? ' error' : '' }}" style="margin-top: 16px;">
            {{ $latestJob?->error_message ?? 'No recent provisioning error recorded.' }}
        </div>
    </x-ui.panel>
</div>
