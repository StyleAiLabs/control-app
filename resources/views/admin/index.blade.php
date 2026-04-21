<x-layouts.app title="Admin Debug">
    <div class="topbar">
        <div>
            <span class="eyebrow">Super Admin</span>
            <h2>Admin Overview</h2>
            <p>Monitor user signups, tenant readiness, and provisioning outcomes across the local MVP.</p>
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

    <section class="panel" style="margin-top: 20px;" data-system-health data-status-url="{{ route('admin.system-health.status') }}">
        <div class="topbar" style="margin-bottom: 18px;">
            <div>
                <span class="eyebrow">System Health</span>
                <h2 style="font-size: 1.4rem;">Scheduler And Queue</h2>
                <p>App-level heartbeats prove the scheduler is ticking and a queue worker is handling background jobs.</p>
            </div>
            <div>
                <span
                    data-health-field="overall_status"
                    class="badge badge--technical {{ $systemHealth['overall_badge_class'] ?? 'pending' }}"
                >{{ $systemHealth['overall_status'] ?? 'unknown' }}</span>
            </div>
        </div>

        <div class="meta">
            <div class="meta-item" data-health-component="scheduler">
                <small class="type-label">Scheduler</small>
                <strong>
                    <span class="badge badge--technical {{ $systemHealth['components']['scheduler']['badge_class'] ?? 'pending' }}" data-health-field="status">{{ $systemHealth['components']['scheduler']['status'] ?? 'unknown' }}</span>
                </strong>
                <span class="hint" data-health-field="last_seen_at">Last seen: {{ $systemHealth['components']['scheduler']['last_seen_at'] ?? '—' }}</span>
            </div>
            <div class="meta-item" data-health-component="queue_worker">
                <small class="type-label">Queue Worker</small>
                <strong>
                    <span class="badge badge--technical {{ $systemHealth['components']['queue_worker']['badge_class'] ?? 'pending' }}" data-health-field="status">{{ $systemHealth['components']['queue_worker']['status'] ?? 'unknown' }}</span>
                </strong>
                <span class="hint" data-health-field="last_seen_at">Last seen: {{ $systemHealth['components']['queue_worker']['last_seen_at'] ?? '—' }}</span>
            </div>
            <div class="meta-item" data-health-queue>
                <small class="type-label">Queue Backlog</small>
                <strong>
                    <span class="badge badge--technical {{ $systemHealth['queue']['badge_class'] ?? 'pending' }}" data-health-field="status">{{ $systemHealth['queue']['status'] ?? 'unknown' }}</span>
                </strong>
                <span class="hint" data-health-field="message">{{ $systemHealth['queue']['message'] ?? '—' }}</span>
            </div>
            <div class="meta-item" data-health-queue>
                <small class="type-label">Pending Jobs</small>
                <strong class="type-value type-value--technical" data-health-field="pending_count">{{ $systemHealth['queue']['pending_count'] ?? '—' }}</strong>
            </div>
            <div class="meta-item" data-health-queue>
                <small class="type-label">Reserved Jobs</small>
                <strong class="type-value type-value--technical" data-health-field="reserved_count">{{ $systemHealth['queue']['reserved_count'] ?? '—' }}</strong>
            </div>
            <div class="meta-item" data-health-queue>
                <small class="type-label">Failed Jobs</small>
                <strong class="type-value type-value--technical" data-health-field="failed_count">{{ $systemHealth['queue']['failed_count'] ?? '—' }}</strong>
            </div>
        </div>

        <div
            data-health-notes
            class="note"
            style="margin-top: 16px;{{ empty($systemHealth['notes']) ? ' display: none;' : '' }}"
        >{{ implode(' ', $systemHealth['notes'] ?? []) }}</div>

        <div class="table-wrap" style="margin-top: 16px;">
            <table>
                <thead>
                    <tr>
                        <th>Scheduled Command</th>
                        <th>Status</th>
                        <th>Last Success</th>
                        <th>Last Failure</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody data-health-scheduled>
                    @foreach (($systemHealth['scheduled_commands'] ?? []) as $command)
                        <tr data-health-scheduled-key="{{ $command['key'] }}">
                            <td>{{ $command['label'] }}</td>
                            <td><span class="badge badge--technical {{ $command['badge_class'] }}">{{ $command['status'] }}</span></td>
                            <td class="type-tech type-tech--wrap">{{ $command['last_success_at'] ?? '—' }}</td>
                            <td class="type-tech type-tech--wrap">{{ $command['last_failed_at'] ?? '—' }}</td>
                            <td class="hint">{{ $command['message'] ?? $command['last_error'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
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
            const panel = document.querySelector('[data-system-health]');
            if (!panel) return;

            const statusUrl = panel.getAttribute('data-status-url');
            if (!statusUrl) return;

            const textOrDash = (value) => value === null || value === undefined || value === '' ? '—' : value;
            const setBadge = (element, status, badgeClass) => {
                if (!element) return;

                element.textContent = status ?? 'unknown';
                element.className = `badge badge--technical ${badgeClass ?? 'pending'}`;
            };
            const setText = (selector, value, root = panel) => {
                const element = root.querySelector(selector);
                if (element) element.textContent = textOrDash(value);
            };

            const updateComponent = (key, payload) => {
                const root = panel.querySelector(`[data-health-component="${key}"]`);
                if (!root || !payload) return;

                setBadge(root.querySelector('[data-health-field="status"]'), payload.status, payload.badge_class);
                setText('[data-health-field="last_seen_at"]', `Last seen: ${textOrDash(payload.last_seen_at)}`, root);
            };

            const updateQueue = (payload) => {
                if (!payload) return;

                const statusNode = panel.querySelector('[data-health-queue] [data-health-field="status"]');
                setBadge(statusNode, payload.status, payload.badge_class);
                panel.querySelectorAll('[data-health-queue]').forEach((root) => {
                    ['message', 'pending_count', 'reserved_count', 'failed_count'].forEach((field) => {
                        setText(`[data-health-field="${field}"]`, payload[field], root);
                    });
                });
            };

            const renderScheduled = (commands) => {
                const body = panel.querySelector('[data-health-scheduled]');
                if (!body) return;

                body.innerHTML = '';
                (commands ?? []).forEach((command) => {
                    const row = document.createElement('tr');
                    const name = document.createElement('td');
                    const status = document.createElement('td');
                    const lastSuccess = document.createElement('td');
                    const lastFailure = document.createElement('td');
                    const message = document.createElement('td');
                    const badge = document.createElement('span');

                    row.setAttribute('data-health-scheduled-key', command.key ?? '');
                    name.textContent = command.label ?? 'Scheduled command';
                    badge.textContent = command.status ?? 'unknown';
                    badge.className = `badge badge--technical ${command.badge_class ?? 'pending'}`;
                    status.appendChild(badge);
                    lastSuccess.textContent = textOrDash(command.last_success_at);
                    lastSuccess.className = 'type-tech type-tech--wrap';
                    lastFailure.textContent = textOrDash(command.last_failed_at);
                    lastFailure.className = 'type-tech type-tech--wrap';
                    message.textContent = textOrDash(command.message ?? command.last_error);
                    message.className = 'hint';

                    row.append(name, status, lastSuccess, lastFailure, message);
                    body.appendChild(row);
                });
            };

            const updatePanel = (payload) => {
                setBadge(panel.querySelector('[data-health-field="overall_status"]'), payload.overall_status, payload.overall_badge_class);
                updateComponent('scheduler', payload.components?.scheduler);
                updateComponent('queue_worker', payload.components?.queue_worker);
                updateQueue(payload.queue);
                renderScheduled(payload.scheduled_commands);

                const notes = panel.querySelector('[data-health-notes]');
                if (notes) {
                    const message = (payload.notes ?? []).join(' ');
                    notes.textContent = message;
                    notes.style.display = message ? '' : 'none';
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

                    if (response.ok) updatePanel(await response.json());
                } catch (error) {
                    // Leave the last known health state visible.
                }
            };

            window.setInterval(poll, 30000);
        })();
    </script>

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
