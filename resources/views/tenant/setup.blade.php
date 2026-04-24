<x-layouts.app title="Getting Ready — Sync360">
    <div class="topbar">
        <div>
            <span class="eyebrow">Getting Ready</span>
            <h2>Setting up your digital employee&hellip;</h2>
            <p>This usually takes under a minute. We'll take you straight through when it's done.</p>
        </div>
    </div>

    <section class="grid grid-2">
        <div class="panel">
            <span class="eyebrow">Progress</span>
            <div style="margin-top: 18px; display: grid; gap: 18px;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <strong id="status-label">
                            @switch($tenant->provisioning_status->value)
                                @case('pending')      Queued @break
                                @case('provisioning') Working on it @break
                                @case('ready')        Ready @break
                                @case('failed')       Something went wrong @break
                                @default              {{ ucfirst($tenant->provisioning_status->value) }}
                            @endswitch
                        </strong>
                        <span class="badge {{ $tenant->provisioning_status->value }}" id="status-badge">
                            @switch($tenant->provisioning_status->value)
                                @case('pending')      Queued @break
                                @case('provisioning') In Progress @break
                                @case('ready')        Live @break
                                @case('failed')       Failed @break
                                @default              {{ ucfirst($tenant->provisioning_status->value) }}
                            @endswitch
                        </span>
                    </div>
                    <div class="progress-bar">
                        <span id="progress-bar"></span>
                    </div>
                </div>
                <div class="note" id="progress-message">
                    We're putting the finishing touches on your digital employee. Sit tight &mdash; this page will
                    update automatically and take you straight through once everything is ready.
                </div>
                <div class="note error" id="error-message" style="display: none;"></div>
            </div>
        </div>

        <div class="panel">
            <span class="eyebrow">What We're Setting Up</span>
            <div class="meta" style="margin-top: 18px;">
                <div class="meta-item">
                    <small>Business</small>
                    {{ $tenant->business_name }}
                </div>
                <div class="meta-item">
                    <small>Modules</small>
                    Core modules included
                </div>
                <div class="meta-item">
                    <small>Industry</small>
                    {{ $tenant->industry }}
                </div>
                <div class="meta-item">
                    <small>Your Workspace</small>
                    <span id="workspace-url">{{ $tenant->workspace_url ?? 'Almost ready&hellip;' }}</span>
                </div>
            </div>
        </div>
    </section>

    <script>
        const statusEndpoint = @json(route('tenant.status'));
        const statusLabel    = document.getElementById('status-label');
        const statusBadge    = document.getElementById('status-badge');
        const progressMessage = document.getElementById('progress-message');
        const errorMessage   = document.getElementById('error-message');
        const workspaceUrl   = document.getElementById('workspace-url');

        const labelMap = {
            pending:      'Queued',
            provisioning: 'Working on it',
            ready:        'Ready',
            failed:       'Something went wrong',
        };

        const badgeMap = {
            pending:      'Queued',
            provisioning: 'In Progress',
            ready:        'Live',
            failed:       'Failed',
        };

        const messageMap = {
            pending:      'You\'re in the queue — we\'ll start setting things up in just a moment.',
            provisioning: 'Your digital employee is being configured right now. Hang tight, almost there.',
            failed:       'Something went wrong during setup. Please contact support if this persists.',
        };

        async function pollStatus() {
            const response = await fetch(statusEndpoint, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                return;
            }

            const data = await response.json();

            statusLabel.textContent = labelMap[data.provisioning_status] ?? data.provisioning_status;
            statusBadge.textContent = badgeMap[data.provisioning_status] ?? data.provisioning_status;
            statusBadge.className   = `badge ${data.provisioning_status}`;
            workspaceUrl.textContent = data.workspace_url ?? 'Almost ready\u2026';
            progressMessage.textContent = messageMap[data.provisioning_status] ?? 'Checking your setup status\u2026';

            if (data.provisioning_status === 'failed' && data.error_message) {
                errorMessage.style.display = 'block';
                errorMessage.textContent   = data.error_message;
            }

            if (data.ready_redirect) {
                window.location.assign(data.ready_redirect);
            }
        }

        pollStatus();
        window.setInterval(pollStatus, 3000);
    </script>
</x-layouts.app>
