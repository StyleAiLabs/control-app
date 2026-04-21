@if (! $tenantSkillsAvailable)
    <section class="panel">
        <div class="topbar" style="margin-bottom: 16px;">
            <div>
                <span class="eyebrow">Tenant Skills</span>
                <h2 class="type-section-title">Tenant Skills</h2>
            </div>
        </div>
        <div class="note error">
            Tenant skills are unavailable until the skill catalog and tenant skill migrations are applied locally.
        </div>
        <div class="hint" style="margin-top: 14px;">
            Run the required skill catalog migrations locally, then refresh this page to enable skill assignment drafts, apply, and history.
        </div>
    </section>
@else
    <section class="panel">
        <div class="topbar" style="margin-bottom: 18px;">
            <div>
                <span class="eyebrow">Tenant Skills</span>
                <h2 class="type-section-title">Tenant Skills</h2>
                <p class="type-body">Assign published Sync360 skills to this tenant, save the draft when you want Sync360 state updated, then apply when you want the tenant runtime to use the currently assigned versions.</p>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                @if (! empty($tenantSkillsStatus['summary_label']) && ($tenantSkillsStatus['summary_label'] ?? null) !== ($tenantSkillsStatus['label'] ?? null))
                    <span class="badge badge--technical {{ $tenantSkillsStatus['summary_class'] ?? 'pending' }}">
                        {{ $tenantSkillsStatus['summary_label'] }}
                    </span>
                @endif
                @if (($tenantSkillsStatus['updates_available_count'] ?? 0) > 0)
                    <span class="badge badge--technical pending">
                        {{ $tenantSkillsStatus['updates_available_count'] }} update{{ ($tenantSkillsStatus['updates_available_count'] ?? 0) === 1 ? '' : 's' }} available
                    </span>
                @endif
            </div>
        </div>

        <section class="panel" style="padding: 18px; background:#faf9f8; border-radius:16px; margin-bottom: 18px; box-shadow:none;">
            <span class="eyebrow">Assignment Workflow</span>
            <div class="grid grid-2" style="margin-top: 14px;">
                <div style="padding:14px; border:1px solid var(--stroke); border-radius:14px; background:white;">
                    <strong>1. Choose published skills</strong>
                    <div class="hint" style="margin-top: 6px;">Only skills with a published catalog version can be assigned to a tenant.</div>
                </div>
                <div style="padding:14px; border:1px solid var(--stroke); border-radius:14px; background:white;">
                    <strong>2. Save the draft</strong>
                    <div class="hint" style="margin-top: 6px;">Save Draft updates assignment and mapping state in Sync360 only. It does not change tenant runtime files or upgrade assigned versions.</div>
                </div>
                <div style="padding:14px; border:1px solid var(--stroke); border-radius:14px; background:white;">
                    <strong>3. Apply when ready</strong>
                    <div class="hint" style="margin-top: 6px;">Apply syncs the current tenant draft into the runtime using the versions already assigned to this tenant.</div>
                </div>
                <div style="padding:14px; border:1px solid var(--stroke); border-radius:14px; background:white;">
                    <strong>Version upgrades</strong>
                    <div class="hint" style="margin-top: 6px;">Publish creates a catalog version. Roll out from Skill Catalog when you want existing tenants moved to that newer version.</div>
                </div>
            </div>
        </section>

        <section style="margin-bottom:18px;">
            <h3 class="type-section-title" style="margin-top:0; font-size:1.12rem;">Assigned Skill Versions</h3>
            <div class="hint" style="margin-top:6px;">These are the versions this tenant is currently pinned to. New catalog releases do not replace them until a rollout updates the assignment.</div>
            <div style="display:grid; gap:10px; margin-top:14px;">
                @forelse ($tenantSkillRows as $tenantSkillRow)
                    <div style="padding:14px 16px; border:1px solid var(--stroke); border-radius:16px; background:#fff;">
                        <div style="display:flex; justify-content:space-between; gap:12px; align-items:start; flex-wrap:wrap;">
                            <div style="min-width:0;">
                                <strong style="display:block; font-size:1rem; line-height:1.3;">{{ $tenantSkillRow['label'] }}</strong>
                                <div class="hint" style="margin-top:3px; font-size:0.82rem;">{{ $tenantSkillRow['skill_key'] }}</div>
                            </div>
                            <a href="{{ $tenantSkillRow['detail_url'] }}" class="button button--secondary" style="padding:10px 14px;">Manage Rollout</a>
                        </div>
                        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
                            <span class="badge badge--technical ready">assigned {{ $tenantSkillRow['assigned_version'] ?? 'unknown' }}</span>
                            <span class="badge badge--technical {{ $tenantSkillRow['latest_published_version'] ? 'ready' : 'pending' }}">
                                {{ $tenantSkillRow['latest_published_version'] ? 'latest '.$tenantSkillRow['latest_published_version'] : 'not published' }}
                            </span>
                            <span class="badge badge--technical {{ $tenantSkillRow['update_class'] }}">{{ $tenantSkillRow['update_label'] }}</span>
                            @if ($tenantSkillRow['last_apply_status'])
                                <span class="badge badge--technical {{ $tenantSkillRow['last_apply_status'] === 'failed' ? 'failed' : ($tenantSkillRow['last_apply_status'] === 'applied' ? 'ready' : 'pending') }}">
                                    last apply {{ $tenantSkillRow['last_apply_status'] }}
                                </span>
                            @endif
                        </div>
                        @if ($tenantSkillRow['last_apply_error'])
                            <div class="hint" style="margin-top:8px; color:#b45309;">{{ $tenantSkillRow['last_apply_error'] }}</div>
                        @elseif ($tenantSkillRow['last_applied_at'])
                            <div class="hint" style="margin-top:8px;">Last applied {{ $tenantSkillRow['last_applied_at'] }}</div>
                        @endif
                    </div>
                @empty
                    <div class="hint">No skill versions are currently assigned to this tenant.</div>
                @endforelse
            </div>
        </section>

        <form method="POST" action="{{ route('admin.tenants.agent-customization.update', $tenant) }}" class="field-single">
            @csrf
            @method('PATCH')
            <input type="hidden" name="return_tab" value="skills">
            <input type="hidden" name="customization_scope" value="skills">

            <div style="display:grid; gap:18px; align-items:start;">
                <section data-skill-catalog-layout="full-width">
                    <div style="display:flex; justify-content:space-between; gap:12px; align-items:start; margin-bottom:14px;">
                        <div>
                            <h3 class="type-section-title" style="margin:0; font-size:1.12rem;">Available Skills</h3>
                            <div class="hint" style="margin-top:6px;">Assign published catalog skills to this tenant.</div>
                        </div>
                    </div>
                    <div style="display:grid; gap:10px;">
                        @forelse ($skillCatalog as $skillPack)
                            @php
                                $publishedVersion = $skillPack->activePublishedVersion;
                            @endphp
                            <label style="display:block; padding:14px 16px; border:1px solid var(--stroke); border-radius:16px; background:#fff;">
                                <div style="display:grid; grid-template-columns:28px minmax(0, 1fr); gap:14px; align-items:flex-start;">
                                    <input
                                        type="checkbox"
                                        name="assigned_skill_keys[]"
                                        value="{{ $skillPack->skill_key }}"
                                        {{ in_array($skillPack->skill_key, $assignedSkillKeys, true) ? 'checked' : '' }}
                                        {{ $publishedVersion ? '' : 'disabled' }}
                                        style="margin-top:2px;"
                                    >
                                    <div style="min-width:0;">
                                        <div style="display:flex; justify-content:space-between; gap:12px; align-items:start; flex-wrap:wrap;">
                                            <div style="min-width:0;">
                                                <strong style="display:block; font-size:1rem; line-height:1.3;">{{ $skillPack->label }}</strong>
                                                <div class="hint" style="margin-top:3px; font-size:0.82rem;">{{ $skillPack->skill_key }}</div>
                                            </div>
                                            <span class="badge badge--technical {{ $publishedVersion ? 'ready' : 'pending' }}" style="font-size:0.76rem;">
                                                {{ $publishedVersion ? 'published '.$publishedVersion->version : 'publish required' }}
                                            </span>
                                        </div>
                                        <div style="margin-top:8px; font-size:0.94rem; line-height:1.45;">{{ $skillPack->description }}</div>
                                        @php
                                            $assignedSkillRow = collect($tenantSkillRows)->firstWhere('skill_key', $skillPack->skill_key);
                                        @endphp
                                        @if ($assignedSkillRow)
                                            <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:8px;">
                                                <span class="badge badge--technical ready" style="font-size:0.76rem;">assigned {{ $assignedSkillRow['assigned_version'] ?? 'unknown' }}</span>
                                                <span class="badge badge--technical {{ $assignedSkillRow['update_class'] }}" style="font-size:0.76rem;">{{ $assignedSkillRow['update_label'] }}</span>
                                            </div>
                                        @endif
                                        @if ($publishedVersion)
                                            <div class="hint" style="margin-top:8px; font-size:0.84rem;">Save Draft changes assignment only. To upgrade tenants already assigned to {{ $skillPack->label }}, use Skill Catalog rollout.</div>
                                        @else
                                            <div class="hint" style="margin-top:8px; font-size:0.84rem;">Publish this skill in Skill Catalog before assigning it to tenants.</div>
                                        @endif
                                        @if ($skillPack->orphaned_warning)
                                            <div class="hint" style="margin-top:8px; color:#b45309; font-size:0.84rem;">{{ $skillPack->orphaned_warning }}</div>
                                        @endif
                                    </div>
                                </div>
                            </label>
                        @empty
                            <div class="hint">No Sync360 skill packs are registered yet.</div>
                        @endforelse
                    </div>
                </section>

                <section style="max-width: 560px;">
                    <div style="padding:16px; border:1px solid var(--stroke); border-radius:16px; background:#faf9f8;">
                        <h3 class="type-section-title" style="margin:0; font-size:1.12rem;">Advanced Agent Mapping</h3>
                        <div class="hint" style="margin-top:8px;">
                            This optional field controls which raw skill IDs are written into the default agent skill list in <code>openclaw.json</code>.
                        </div>

                        <label style="display:block; margin-top:16px;">
                            Default Skill IDs
                            <input
                                type="text"
                                name="agent_defaults[default_skill_ids]"
                                value="{{ implode(', ', $agentDefaults['default_skill_ids'] ?? []) }}"
                                placeholder="hello-world, follow-up-skill"
                                data-skill-ids-input
                            >
                        </label>
                        <div class="hint" style="margin-top:8px;">Leave this blank unless you need to explicitly expose extra skill IDs to the default agent.</div>
                    </div>
                </section>
            </div>

            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:18px;">
                <button type="submit">Save Skill Draft</button>
            </div>
        </form>

        <section style="margin-top:18px;">
            <h3 class="type-section-title" style="margin-top:0; font-size:1.12rem;">Deployment</h3>
            <div class="hint" style="margin-bottom:12px;">Save Draft updates assignment state in Sync360. Apply pushes the current tenant draft into runtime using the versions already assigned above.</div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <form method="POST" action="{{ route('admin.tenants.agent-customization.apply', $tenant) }}" class="inline">
                    @csrf
                    <input type="hidden" name="return_tab" value="skills">
                    <input type="hidden" name="customization_scope" value="skills">
                    <button type="submit" {{ $canApplyAgentCustomization ? '' : 'disabled' }}>Apply</button>
                </form>
                <form method="POST" action="{{ route('admin.tenants.agent-customization.revert', $tenant) }}" class="inline">
                    @csrf
                    <input type="hidden" name="return_tab" value="skills">
                    <input type="hidden" name="customization_scope" value="skills">
                    <button type="submit" class="button button--secondary" {{ $canApplyAgentCustomization && $agentCustomization?->last_applied_input_snapshot_json ? '' : 'disabled' }}>Revert</button>
                </form>
            </div>
        </section>

        <section style="margin-top:18px;">
            <div style="display:flex; justify-content:space-between; gap:12px; align-items:start; flex-wrap:wrap;">
                <div>
                    <h3 class="type-section-title" style="margin-top:0; font-size:1.12rem;">Runtime Apply Progress</h3>
                    <div class="hint" style="margin-top:6px;">Apply runs as a background job. We refresh this panel automatically while the tenant runtime job is queued or running.</div>
                </div>
            </div>

            <div
                style="margin-top:14px; padding:16px; border:1px solid var(--stroke); border-radius:16px; background:#faf9f8;"
                data-tenant-skill-progress
                data-progress-url="{{ route('admin.tenants.skills.progress', $tenant) }}"
                data-should-poll="{{ ($tenantSkillProgress['should_poll'] ?? false) ? 'true' : 'false' }}"
            >
                @if (($tenantSkillProgress['job'] ?? null) !== null)
                    @php
                        $progressJob = $tenantSkillProgress['job'];
                    @endphp
                    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                        <span class="badge badge--technical {{ $progressJob['status'] }}" data-role="status-badge">{{ $progressJob['status'] }}</span>
                        <span class="hint type-tech" data-role="status-detail">
                            {{ ($progressJob['action'] ?? 'apply') === 'revert' ? 'Revert' : 'Apply' }} job #{{ $progressJob['id'] ?? '—' }}
                        </span>
                    </div>
                    <div class="hint" style="margin-top:10px;" data-role="timing">
                        @if ($progressJob['completed_at'])
                            Completed {{ $progressJob['completed_at'] }}
                        @elseif ($progressJob['started_at'])
                            Started {{ $progressJob['started_at'] }}
                        @else
                            Waiting for the worker to start this runtime job.
                        @endif
                    </div>
                    <div class="hint" style="margin-top:10px; color:#b45309; {{ $progressJob['error_message'] ? '' : 'display:none;' }}" data-role="error">
                        {{ $progressJob['error_message'] ?? '' }}
                    </div>
                @else
                    <div class="hint" data-role="empty-state">No tenant runtime apply job has been queued yet from this screen.</div>
                @endif
            </div>
        </section>

        <section style="margin-top:18px;">
            <div style="display:flex; justify-content:space-between; gap:12px; align-items:start; flex-wrap:wrap;">
                <div>
                    <h3 class="type-section-title" style="margin-top:0; font-size:1.12rem;">Runtime Available Skills</h3>
                    <div class="hint" style="margin-top:6px;">Diagnostic runtime visibility from <code>openclaw skills list --eligible</code>. Use this to confirm what the tenant runtime can currently expose.</div>
                </div>
                <form method="POST" action="{{ route('admin.tenants.skills.runtime-refresh', $tenant) }}" class="inline">
                    @csrf
                    <input type="hidden" name="return_tab" value="skills">
                    <button type="submit" class="button button--secondary">Refresh Runtime Skills</button>
                </form>
            </div>

            <div style="margin-top:14px; padding:16px; border:1px solid var(--stroke); border-radius:16px; background:#faf9f8;">
                @if ($runtimeSkillInspection)
                    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
                        <span class="badge badge--technical pending">Workspace state: {{ $runtimeSkillInspection['workspace_state'] }}</span>
                        <span class="hint type-tech">Last refreshed: {{ $runtimeSkillInspection['refreshed_at'] }}</span>
                    </div>

                    @if ($runtimeSkillInspection['skills'] !== [])
                        <div style="display:grid; gap:8px; margin-top:14px;">
                            @foreach ($runtimeSkillInspection['skills'] as $runtimeSkill)
                                <div class="type-value type-value--technical" style="padding:10px 12px; border:1px solid var(--stroke); border-radius:12px; background:white;">
                                    {{ $runtimeSkill }}
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="hint" style="margin-top:14px;">No eligible runtime skills were reported by the tenant workspace.</div>
                    @endif

                    @if ($runtimeSkillInspection['raw_output'] !== '')
                        <div style="margin-top:16px;">
                            <div class="hint" style="margin-bottom:6px;">Raw command output</div>
                            <pre class="type-tech type-tech--wrap" style="margin:0; padding:12px; background:white; border:1px solid var(--stroke); border-radius:12px; overflow:auto;">{{ $runtimeSkillInspection['raw_output'] }}</pre>
                        </div>
                    @endif
                @else
                    <div class="hint">Refresh runtime skills to inspect the tenant workspace directly. This does not change assignment or runtime state.</div>
                @endif
            </div>
        </section>

        <section style="margin-top:18px;">
            <h3 class="type-section-title" style="margin-top:0; font-size:1.12rem;">Skill Change History</h3>
            <div style="display:grid; gap:10px;">
                @forelse ($skillChangeHistory as $historyEntry)
                    @php
                        $entry = $historyEntry['entry'];
                    @endphp
                    <div style="padding:12px; border:1px solid rgba(255,255,255,0.08); border-radius:12px;">
                        <strong>{{ $entry->action === 'revert' ? 'Revert applied' : 'Skill update applied' }}</strong>
                        <span class="badge badge--technical {{ $entry->status === 'reverted' ? 'pending' : 'ready' }}">{{ $entry->status }}</span>
                        <div class="hint" style="margin-top:6px;">{{ $entry->created_at?->toDateTimeString() ?? 'Pending timestamp' }}</div>
                        <div style="display:grid; gap:6px; margin-top:10px;">
                            @foreach ($historyEntry['changes'] as $change)
                                <div>{{ $change }}</div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <div class="hint">No skill enable, disable, or default-skill changes recorded yet.</div>
                @endforelse
            </div>
        </section>
    </section>

    <script>
        (() => {
            const progressEl = document.querySelector('[data-tenant-skill-progress]');

            if (!progressEl) {
                return;
            }

            const progressUrl = progressEl.dataset.progressUrl;
            let shouldPoll = progressEl.dataset.shouldPoll === 'true';
            let timeoutId = null;

            const renderProgress = (payload) => {
                const job = payload?.job ?? null;
                const emptyState = progressEl.querySelector('[data-role="empty-state"]');
                let badge = progressEl.querySelector('[data-role="status-badge"]');
                let detail = progressEl.querySelector('[data-role="status-detail"]');
                let timing = progressEl.querySelector('[data-role="timing"]');
                let error = progressEl.querySelector('[data-role="error"]');

                if (!job) {
                    if (badge) badge.remove();
                    if (detail) detail.remove();
                    if (timing) timing.remove();
                    if (error) error.remove();

                    if (!emptyState) {
                        const node = document.createElement('div');
                        node.className = 'hint';
                        node.dataset.role = 'empty-state';
                        node.textContent = 'No tenant runtime apply job has been queued yet from this screen.';
                        progressEl.appendChild(node);
                    }

                    shouldPoll = false;
                    return;
                }

                if (emptyState) {
                    emptyState.remove();
                }

                if (!badge || !detail) {
                    const header = document.createElement('div');
                    header.style.display = 'flex';
                    header.style.gap = '10px';
                    header.style.flexWrap = 'wrap';
                    header.style.alignItems = 'center';

                    badge = document.createElement('span');
                    badge.dataset.role = 'status-badge';
                    badge.className = 'badge badge--technical';
                    header.appendChild(badge);

                    detail = document.createElement('span');
                    detail.dataset.role = 'status-detail';
                    detail.className = 'hint type-tech';
                    header.appendChild(detail);

                    progressEl.prepend(header);
                }

                badge.className = `badge badge--technical ${job.status}`;
                badge.textContent = job.status;
                detail.textContent = `${job.action === 'revert' ? 'Revert' : 'Apply'} job #${job.id ?? '—'}`;

                if (!timing) {
                    timing = document.createElement('div');
                    timing.className = 'hint';
                    timing.style.marginTop = '10px';
                    timing.dataset.role = 'timing';
                    progressEl.appendChild(timing);
                }

                if (job.completed_at) {
                    timing.textContent = `Completed ${job.completed_at}`;
                } else if (job.started_at) {
                    timing.textContent = `Started ${job.started_at}`;
                } else {
                    timing.textContent = 'Waiting for the worker to start this runtime job.';
                }

                if (!error) {
                    error = document.createElement('div');
                    error.className = 'hint';
                    error.style.marginTop = '10px';
                    error.style.color = '#b45309';
                    error.dataset.role = 'error';
                    progressEl.appendChild(error);
                }

                error.textContent = job.error_message ?? '';
                error.style.display = job.error_message ? '' : 'none';
                shouldPoll = Boolean(payload?.should_poll);
            };

            const poll = async () => {
                if (!shouldPoll || !progressUrl) {
                    return;
                }

                try {
                    const response = await fetch(progressUrl, { headers: { 'Accept': 'application/json' } });

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
@endif
