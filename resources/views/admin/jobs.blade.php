<x-layouts.app title="Admin Jobs">
    <div class="topbar">
        <div>
            <span class="eyebrow">Admin Jobs</span>
            <h2>Provisioning Jobs</h2>
            <p>Each signup or retry creates a new provisioning job record with its own status and error details.</p>
        </div>
    </div>

    <section class="panel table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Tenant</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Started</th>
                    <th>Completed</th>
                    <th>Payload</th>
                    <th>Error</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($jobs as $job)
                    <tr>
                        <td>{{ $job->id }}</td>
                        <td>
                            <strong>{{ $job->tenant?->business_name }}</strong><br>
                            <span class="hint">{{ $job->tenant?->user?->email }}</span>
                        </td>
                        <td>{{ $job->job_type }}</td>
                        <td><span class="badge {{ $job->status->value }}">{{ $job->status->value }}</span></td>
                        <td>{{ optional($job->started_at)->toDateTimeString() ?? '—' }}</td>
                        <td>{{ optional($job->completed_at)->toDateTimeString() ?? '—' }}</td>
                        <td><code>{{ json_encode($job->payload_json) }}</code></td>
                        <td>
                            {{ $job->error_message ?? '—' }}
                            @if ($job->status->value === 'failed' && $job->tenant)
                                <div style="margin-top: 10px;">
                                    <form method="POST" action="{{ route('admin.retry', $job->tenant) }}" class="inline">
                                        @csrf
                                        <button type="submit">Retry</button>
                                    </form>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8">No provisioning jobs have been created yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>
</x-layouts.app>
