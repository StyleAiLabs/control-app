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
            <a href="{{ route('admin.analytics.skills') }}" class="button button--secondary">Skill Analytics</a>
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

    <section class="panel" style="margin-top: 20px;" data-control-app-deploy data-status-url="{{ route('admin.deploy.control-app.status') }}">
        <div class="topbar" style="margin-bottom: 18px;">
            <div>
                <span class="eyebrow">Control App Deploy</span>
                <h2 style="font-size: 1.4rem;">Production Update</h2>
                <p>Fetch the latest deployment branch on the primary server and rebuild the control app safely through the host deploy script.</p>
            </div>
            <div>
                <form method="POST" action="{{ route('admin.deploy.control-app') }}" class="inline" data-deploy-form>
                    @csrf
                    <button
                        type="submit"
                        data-deploy-trigger
                        {{ !($controlAppDeployStatus['enabled'] ?? false) || ($controlAppDeployStatus['is_up_to_date'] ?? false) ? 'disabled' : '' }}
                    >{{ ($controlAppDeployStatus['is_up_to_date'] ?? false) ? 'Up-to-date' : 'Fetch Latest And Deploy' }}</button>
                </form>
            </div>
        </div>

        <div class="meta">
            <div class="meta-item">
                <small>Deploy State</small>
                <div>
                    <span
                        data-deploy-field="state"
                        class="badge {{ match($controlAppDeployStatus['state'] ?? 'not_configured') {
                        'running' => 'running',
                        'succeeded' => 'ready',
                        'failed', 'unreachable' => 'failed',
                        default => 'pending',
                    } }}"
                    >{{ str_replace('_', ' ', $controlAppDeployStatus['state'] ?? 'not_configured') }}</span>
                </div>
            </div>
            <div class="meta-item">
                <small>Enabled</small>
                <strong data-deploy-field="enabled">{{ ($controlAppDeployStatus['enabled'] ?? false) ? 'Yes' : 'No' }}</strong>
            </div>
            <div class="meta-item">
                <small>Primary Server</small>
                <strong data-deploy-field="primary_server">{{ $controlAppDeployStatus['primary_server'] ?? (($controlAppDeployStatus['user'] ?? '—').'@'.($controlAppDeployStatus['host'] ?? '—')) }}</strong>
            </div>
            <div class="meta-item">
                <small>Branch</small>
                <strong data-deploy-field="branch">{{ $controlAppDeployStatus['branch'] ?? '—' }}</strong>
            </div>
            <div class="meta-item">
                <small>Started</small>
                <strong data-deploy-field="started_at">{{ $controlAppDeployStatus['started_at'] ?? '—' }}</strong>
            </div>
            <div class="meta-item">
                <small>Finished</small>
                <strong data-deploy-field="finished_at">{{ $controlAppDeployStatus['finished_at'] ?? '—' }}</strong>
            </div>
            <div class="meta-item">
                <small>Latest Commit</small>
                <strong data-deploy-field="latest_commit_short">{{ $controlAppDeployStatus['latest_commit_short'] ?? '—' }}</strong>
            </div>
            <div class="meta-item">
                <small>Commit Message</small>
                <strong data-deploy-field="latest_commit_subject">{{ $controlAppDeployStatus['latest_commit_subject'] ?? '—' }}</strong>
            </div>
        </div>

        <div
            data-deploy-message
            class="note"
            style="margin-top: 16px;{{ empty($controlAppDeployStatus['message']) ? ' display: none;' : '' }}"
        >{{ $controlAppDeployStatus['message'] ?? '' }}</div>

        <div data-deploy-log-wrap class="meta-item" style="margin-top: 16px;{{ empty($controlAppDeployStatus['log_tail']) ? ' display: none;' : '' }}">
            <small>Last Deploy Log</small>
            <pre data-deploy-field="log_tail" class="type-tech type-tech--wrap" style="margin: 0; color: #374151;">{{ $controlAppDeployStatus['log_tail'] ?? '' }}</pre>
        </div>
    </section>

    <script>
        (() => {
            const panel = document.querySelector('[data-control-app-deploy]');
            if (!panel) return;

            const statusUrl = panel.getAttribute('data-status-url');
            if (!statusUrl) return;

            const stateBadge = panel.querySelector('[data-deploy-field="state"]');
            const enabledField = panel.querySelector('[data-deploy-field="enabled"]');
            const messageField = panel.querySelector('[data-deploy-message]');
            const logWrap = panel.querySelector('[data-deploy-log-wrap]');
            const logField = panel.querySelector('[data-deploy-field="log_tail"]');
            const deployForm = panel.querySelector('[data-deploy-form]');
            const deployTrigger = panel.querySelector('[data-deploy-trigger]');
            const textFields = ['primary_server', 'branch', 'started_at', 'finished_at', 'latest_commit_short', 'latest_commit_subject'];

            const badgeClassForState = (state) => {
                if (state === 'running') return 'running';
                if (state === 'succeeded') return 'ready';
                if (state === 'failed' || state === 'unreachable') return 'failed';
                return 'pending';
            };

            const normalize = (value) => value === null || value === undefined || value === '' ? '—' : value;
            const shouldDisableDeploy = (payload) => {
                if (!payload.enabled) return true;
                if ((payload.state ?? '') === 'running') return true;

                return Boolean(payload.is_up_to_date);
            };

            const deployLabelFor = (payload) => {
                if ((payload.state ?? '') === 'running') return 'Deploying...';
                if (payload.is_up_to_date) return 'Up-to-date';
                if (!payload.enabled) return 'Deploy Disabled';

                return 'Fetch Latest And Deploy';
            };

            const updatePanel = (payload) => {
                const state = payload.state ?? 'not_configured';

                if (stateBadge) {
                    stateBadge.textContent = String(state).replaceAll('_', ' ');
                    stateBadge.className = `badge ${badgeClassForState(state)}`;
                }

                if (enabledField) {
                    enabledField.textContent = payload.enabled ? 'Yes' : 'No';
                }

                textFields.forEach((field) => {
                    const element = panel.querySelector(`[data-deploy-field="${field}"]`);
                    if (element) {
                        element.textContent = normalize(payload[field]);
                    }
                });

                if (messageField) {
                    const message = payload.message ?? '';
                    messageField.textContent = message;
                    messageField.style.display = message ? '' : 'none';
                }

                if (logWrap && logField) {
                    const logTail = payload.log_tail ?? '';
                    logField.textContent = logTail;
                    logWrap.style.display = logTail ? '' : 'none';
                }

                if (deployForm && deployTrigger) {
                    deployTrigger.textContent = deployLabelFor(payload);
                    deployTrigger.disabled = shouldDisableDeploy(payload);
                }
            };

            const poll = async () => {
                try {
                    const response = await fetch(statusUrl, {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json',
                        },
                        credentials: 'same-origin',
                    });

                    if (!response.ok) return;

                    updatePanel(await response.json());
                } catch (error) {
                    console.debug('Deploy status refresh failed.', error);
                }
            };

            poll();
            window.setInterval(poll, 5000);
        })();
    </script>
</x-layouts.app>
