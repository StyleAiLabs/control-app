<x-layouts.app title="Skill Detail">
    <div class="topbar">
        <div>
            <span class="eyebrow">Skill Detail</span>
            <h2>{{ $skill->label }}</h2>
            <p>{{ $skill->description }}</p>
        </div>
        <div style="display: flex; gap: 10px;">
            <a href="{{ route('admin.skills.index') }}" class="button button--secondary">Back to Skill Catalog</a>
        </div>
    </div>

    @if ($skill->orphaned_warning)
        <div class="note note--danger">{{ $skill->orphaned_warning }}</div>
    @endif

    <section class="panel" style="margin-bottom:18px;">
        <span class="eyebrow">Version Flow</span>
        <div class="grid grid-3" style="margin-top:14px;">
            <div style="padding:14px; border:1px solid var(--stroke); border-radius:14px; background:#fff;">
                <strong>1. Publish</strong>
                <div class="hint" style="margin-top:6px;">Publishing makes the catalog version assignable. It does not change any tenant assignments.</div>
            </div>
            <div style="padding:14px; border:1px solid var(--stroke); border-radius:14px; background:#fff;">
                <strong>2. Roll out</strong>
                <div class="hint" style="margin-top:6px;">Roll out updates selected tenant assignments from older versions to the target published version.</div>
            </div>
            <div style="padding:14px; border:1px solid var(--stroke); border-radius:14px; background:#fff;">
                <strong>3. Apply runtime</strong>
                <div class="hint" style="margin-top:6px;">Rollout queues tenant runtime apply jobs automatically so the new assigned version is materialized.</div>
            </div>
        </div>
    </section>

    <section class="panel">
        <span class="eyebrow">Versions</span>
        <h3 style="margin-top: 8px;">Catalog Versions</h3>

        <table class="table">
            <thead>
                <tr>
                    <th>Version</th>
                    <th>Status</th>
                    <th>Tenants On This Version</th>
                    <th>Outdated Tenants</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($skill->versions as $version)
                    @php
                        $rolloutSummary = $rolloutSummaries[$version->id] ?? [
                            'tenants_on_version_count' => 0,
                            'outdated_count' => 0,
                            'eligible_tenants' => [],
                            'can_rollout' => false,
                        ];
                    @endphp
                    <tr>
                        <td>{{ $version->version }}</td>
                        <td>
                            @if ($version->is_active_published)
                                <span class="badge ready">published</span>
                            @elseif ($version->is_archived)
                                <span class="badge failed">archived</span>
                            @else
                                <span class="badge pending">registered</span>
                            @endif
                        </td>
                        <td>{{ $rolloutSummary['tenants_on_version_count'] }}</td>
                        <td>{{ $rolloutSummary['outdated_count'] }}</td>
                        <td style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <form method="POST" action="{{ route('admin.skills.versions.publish', ['skill' => $skill, 'version' => $version]) }}">
                                @csrf
                                <button type="submit" class="button button--primary">Publish</button>
                            </form>
                            <form method="POST" action="{{ route('admin.skills.versions.archive', ['skill' => $skill, 'version' => $version]) }}">
                                @csrf
                                <button type="submit" class="button button--secondary">Archive</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    @foreach ($skill->versions as $version)
        @php
            $rolloutSummary = $rolloutSummaries[$version->id] ?? [
                'tenants_on_version_count' => 0,
                'outdated_count' => 0,
                'eligible_tenants' => [],
                'can_rollout' => false,
            ];
            $isProgressVersion = ($rolloutProgress['version_id'] ?? null) === $version->id;
            $progressTenantIds = $isProgressVersion ? collect($rolloutProgress['tenants'] ?? [])->pluck('tenant_id')->implode(',') : '';
        @endphp

        @if ($rolloutSummary['can_rollout'])
            <section class="panel" style="margin-top:18px;">
                <div style="display:flex; justify-content:space-between; gap:12px; align-items:start; flex-wrap:wrap;">
                    <div>
                        <span class="eyebrow">Rollout</span>
                        <h3 style="margin-top:8px;">Roll out v{{ $version->version }}</h3>
                        <p class="hint" style="margin-top:6px;">Only tenants already assigned this skill on an older version are eligible. Runtime apply jobs are queued automatically after rollout.</p>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <span class="badge badge--technical ready">{{ $rolloutSummary['tenants_on_version_count'] }} on version</span>
                        <span class="badge badge--technical pending">{{ $rolloutSummary['outdated_count'] }} outdated</span>
                    </div>
                </div>

                @if ($isProgressVersion)
                    <div
                        style="margin-top:16px; padding:16px; border:1px solid var(--stroke); border-radius:16px; background:#faf9f8;"
                        data-rollout-progress
                        data-progress-url="{{ route('admin.skills.versions.rollout-progress', ['skill' => $skill, 'version' => $version]) }}"
                        data-tenant-ids="{{ $progressTenantIds }}"
                        data-should-poll="{{ ($rolloutProgress['should_poll'] ?? false) ? 'true' : 'false' }}"
                    >
                        <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                            <span class="badge badge--technical queued" data-role="count-queued">queued {{ $rolloutProgress['counts']['queued'] ?? 0 }}</span>
                            <span class="badge badge--technical running" data-role="count-running">running {{ $rolloutProgress['counts']['running'] ?? 0 }}</span>
                            <span class="badge badge--technical ready" data-role="count-completed">completed {{ $rolloutProgress['counts']['completed'] ?? 0 }}</span>
                            <span class="badge badge--technical failed" data-role="count-failed">failed {{ $rolloutProgress['counts']['failed'] ?? 0 }}</span>
                        </div>
                        <div class="hint" style="margin-top:10px;" data-role="summary">
                            Tracking {{ $rolloutProgress['total'] ?? 0 }} tenant rollout job{{ ($rolloutProgress['total'] ?? 0) === 1 ? '' : 's' }} for v{{ $version->version }}.
                        </div>
                        <div style="display:grid; gap:8px; margin-top:14px;" data-role="tenant-status-list">
                            @foreach (($rolloutProgress['tenants'] ?? []) as $tenantProgress)
                                <div style="padding:10px 12px; border:1px solid var(--stroke); border-radius:12px; background:#fff;">
                                    <div style="display:flex; justify-content:space-between; gap:10px; align-items:start; flex-wrap:wrap;">
                                        <div>
                                            <strong>{{ $tenantProgress['business_name'] }}</strong>
                                            <div class="hint">{{ $tenantProgress['slug'] }}</div>
                                        </div>
                                        <span class="badge badge--technical {{ $tenantProgress['status'] }}">{{ $tenantProgress['status'] }}</span>
                                    </div>
                                    @if ($tenantProgress['error_message'])
                                        <div class="hint" style="margin-top:8px; color:#b45309;">{{ $tenantProgress['error_message'] }}</div>
                                    @elseif ($tenantProgress['completed_at'])
                                        <div class="hint" style="margin-top:8px;">Completed {{ $tenantProgress['completed_at'] }}</div>
                                    @elseif ($tenantProgress['started_at'])
                                        <div class="hint" style="margin-top:8px;">Started {{ $tenantProgress['started_at'] }}</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($rolloutSummary['eligible_tenants'] !== [])
                    <div style="margin-top:18px;">
                        <form method="POST" action="{{ route('admin.skills.versions.rollout', ['skill' => $skill, 'version' => $version]) }}">
                            @csrf
                            <input type="hidden" name="scope" value="selected">
                            <div style="display:grid; gap:10px;">
                                @foreach ($rolloutSummary['eligible_tenants'] as $eligibleTenant)
                                    <label style="display:block; padding:14px 16px; border:1px solid var(--stroke); border-radius:16px; background:#fff;">
                                        <div style="display:grid; grid-template-columns:28px minmax(0, 1fr); gap:14px; align-items:flex-start;">
                                            <input
                                                type="checkbox"
                                                name="tenant_ids[]"
                                                value="{{ $eligibleTenant['tenant_id'] }}"
                                                checked
                                                style="margin-top:2px;"
                                            >
                                            <div style="min-width:0;">
                                                <div style="display:flex; justify-content:space-between; gap:12px; align-items:start; flex-wrap:wrap;">
                                                    <div style="min-width:0;">
                                                        <strong style="display:block; font-size:1rem; line-height:1.3;">{{ $eligibleTenant['business_name'] }}</strong>
                                                        <div class="hint" style="margin-top:3px; font-size:0.82rem;">{{ $eligibleTenant['slug'] }}</div>
                                                    </div>
                                                    <a href="{{ $eligibleTenant['tenant_url'] }}" class="button button--secondary" style="padding:10px 14px;">Open Tenant</a>
                                                </div>
                                                <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:8px;">
                                                    <span class="badge badge--technical pending">assigned {{ $eligibleTenant['current_version'] }}</span>
                                                    <span class="badge badge--technical ready">target {{ $eligibleTenant['target_version'] }}</span>
                                                    @if ($eligibleTenant['last_apply_status'])
                                                        <span class="badge badge--technical {{ $eligibleTenant['last_apply_status'] === 'failed' ? 'failed' : ($eligibleTenant['last_apply_status'] === 'applied' ? 'ready' : 'pending') }}">
                                                            last apply {{ $eligibleTenant['last_apply_status'] }}
                                                        </span>
                                                    @endif
                                                </div>
                                                @if ($eligibleTenant['last_apply_error'])
                                                    <div class="hint" style="margin-top:8px; color:#b45309;">{{ $eligibleTenant['last_apply_error'] }}</div>
                                                @elseif ($eligibleTenant['last_applied_at'])
                                                    <div class="hint" style="margin-top:8px;">Last applied {{ $eligibleTenant['last_applied_at'] }}</div>
                                                @endif
                                            </div>
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:16px;">
                                <button type="submit">Roll Out To Selected Tenants</button>
                            </div>
                        </form>

                        <form method="POST" action="{{ route('admin.skills.versions.rollout', ['skill' => $skill, 'version' => $version]) }}" style="margin-top:10px;">
                            @csrf
                            <input type="hidden" name="scope" value="all_outdated">
                            <button type="submit" class="button button--secondary">Roll Out To All Outdated Tenants</button>
                        </form>
                    </div>
                @else
                    <div class="hint" style="margin-top:16px;">No tenants assigned to this skill are behind v{{ $version->version }} right now.</div>
                @endif
            </section>
        @endif
    @endforeach

    <script>
        (() => {
            const progressEl = document.querySelector('[data-rollout-progress]');

            if (!progressEl) {
                return;
            }

            const progressUrl = progressEl.dataset.progressUrl;
            const tenantIds = progressEl.dataset.tenantIds;
            let shouldPoll = progressEl.dataset.shouldPoll === 'true';
            let timeoutId = null;
            const escapeHtml = (value) => String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#39;');

            const renderRows = (rows) => {
                const list = progressEl.querySelector('[data-role="tenant-status-list"]');

                if (!list) {
                    return;
                }

                list.innerHTML = rows.map((row) => `
                    <div style="padding:10px 12px; border:1px solid var(--stroke); border-radius:12px; background:#fff;">
                        <div style="display:flex; justify-content:space-between; gap:10px; align-items:start; flex-wrap:wrap;">
                            <div>
                                <strong>${escapeHtml(row.business_name)}</strong>
                                <div class="hint">${escapeHtml(row.slug)}</div>
                            </div>
                            <span class="badge badge--technical ${escapeHtml(row.status)}">${escapeHtml(row.status)}</span>
                        </div>
                        ${row.error_message ? `<div class="hint" style="margin-top:8px; color:#b45309;">${escapeHtml(row.error_message)}</div>` : row.completed_at ? `<div class="hint" style="margin-top:8px;">Completed ${escapeHtml(row.completed_at)}</div>` : row.started_at ? `<div class="hint" style="margin-top:8px;">Started ${escapeHtml(row.started_at)}</div>` : ''}
                    </div>
                `).join('');
            };

            const renderProgress = (payload) => {
                progressEl.querySelector('[data-role="count-queued"]').textContent = `queued ${payload.counts.queued ?? 0}`;
                progressEl.querySelector('[data-role="count-running"]').textContent = `running ${payload.counts.running ?? 0}`;
                progressEl.querySelector('[data-role="count-completed"]').textContent = `completed ${payload.counts.completed ?? 0}`;
                progressEl.querySelector('[data-role="count-failed"]').textContent = `failed ${payload.counts.failed ?? 0}`;
                progressEl.querySelector('[data-role="summary"]').textContent = `Tracking ${payload.total ?? 0} tenant rollout job${(payload.total ?? 0) === 1 ? '' : 's'} for v${payload.version}.`;
                renderRows(payload.tenants ?? []);
                shouldPoll = Boolean(payload.should_poll);
            };

            const poll = async () => {
                if (!shouldPoll || !progressUrl) {
                    return;
                }

                const url = new URL(progressUrl, window.location.origin);

                if (tenantIds) {
                    url.searchParams.set('tenant_ids', tenantIds);
                }

                try {
                    const response = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });

                    if (!response.ok) {
                        shouldPoll = false;
                        return;
                    }

                    renderProgress(await response.json());
                } catch (error) {
                    shouldPoll = false;
                    return;
                }

                if (shouldPoll) {
                    timeoutId = window.setTimeout(poll, 3000);
                }
            };

            if (shouldPoll) {
                timeoutId = window.setTimeout(poll, 3000);
            }

            window.addEventListener('beforeunload', () => {
                if (timeoutId) {
                    window.clearTimeout(timeoutId);
                }
            });
        })();
    </script>
</x-layouts.app>
