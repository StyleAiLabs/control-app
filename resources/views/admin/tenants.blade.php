<x-layouts.app title="Admin Tenants">
    <div class="topbar">
        <div>
            <span class="eyebrow">Admin Tenants</span>
            <h2>Tenant Records</h2>
            <p>Inspect provisioning status, assigned ports, workspace URLs, and runtime paths.</p>
        </div>
    </div>

    <section class="panel table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Business</th>
                    <th>User</th>
                    <th>Status</th>
                    <th>Workspace</th>
                    <th>Port</th>
                    <th>Workspace URL</th>
                    <th>Runtime Path</th>
                    <th>Latest Error</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($tenants as $tenant)
                    @php
                        $latestJob = $tenant->provisioningJobs->first();
                        $workspaceState = $workspaceStates[$tenant->id] ?? 'unknown';
                    @endphp
                    <tr>
                        <td>
                            <strong>{{ $tenant->business_name }}</strong><br>
                            <span class="hint">{{ $tenant->slug }}</span>
                        </td>
                        <td>
                            {{ $tenant->user?->name }}<br>
                            <span class="hint">{{ $tenant->user?->email }}</span>
                        </td>
                        <td><span class="badge {{ $tenant->provisioning_status->value }}">{{ $tenant->provisioning_status->value }}</span></td>
                        <td><span class="badge {{ $workspaceState === 'running' ? 'ready' : ($workspaceState === 'stopped' ? 'pending' : 'failed') }}">{{ str_replace('_', ' ', $workspaceState) }}</span></td>
                        <td>{{ $tenant->assigned_port ?? 'Pending' }}</td>
                        <td>{{ $tenant->workspace_url ?? 'Generating...' }}</td>
                        <td>{{ $tenant->runtime_path ?? 'Pending' }}</td>
                        <td>{{ $latestJob?->error_message ?? '—' }}</td>
                        <td>
                            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                <form method="POST" action="{{ route('admin.retry', $tenant) }}" class="inline">
                                    @csrf
                                    <button type="submit">Retry</button>
                                </form>
                                <form method="POST" action="{{ route('admin.workspace.start', $tenant) }}" class="inline">
                                    @csrf
                                    <button type="submit" {{ in_array($workspaceState, ['not_provisioned', 'missing_config'], true) ? 'disabled' : '' }}>Start</button>
                                </form>
                                <form method="POST" action="{{ route('admin.workspace.stop', $tenant) }}" class="inline">
                                    @csrf
                                    <button type="submit" {{ in_array($workspaceState, ['not_provisioned', 'missing_config'], true) ? 'disabled' : '' }}>Stop</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9">No tenants found yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>
</x-layouts.app>
