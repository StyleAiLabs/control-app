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
                <p class="type-body">Assign published Sync360 skills to this tenant, save the draft, then apply when you want the runtime updated.</p>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                @if (! empty($tenantSkillsStatus['summary_label']) && ($tenantSkillsStatus['summary_label'] ?? null) !== ($tenantSkillsStatus['label'] ?? null))
                    <span class="badge badge--technical {{ $tenantSkillsStatus['summary_class'] ?? 'pending' }}">
                        {{ $tenantSkillsStatus['summary_label'] }}
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
                    <div class="hint" style="margin-top: 6px;">Save Draft updates Sync360 state only. Nothing changes inside the tenant runtime yet.</div>
                </div>
                <div style="padding:14px; border:1px solid var(--stroke); border-radius:14px; background:white;">
                    <strong>3. Apply when ready</strong>
                    <div class="hint" style="margin-top: 6px;">Apply pushes the current draft into the tenant workspace and runtime config.</div>
                </div>
                <div style="padding:14px; border:1px solid var(--stroke); border-radius:14px; background:white;">
                    <strong>Advanced agent mapping</strong>
                    <div class="hint" style="margin-top: 6px;">Use default skill IDs only when you need to explicitly expose additional skill IDs to the default agent.</div>
                </div>
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
                                        @if ($publishedVersion)
                                            <div class="hint" style="margin-top:8px; font-size:0.84rem;">Saving this draft will mark {{ $skillPack->label }} for tenant rollout.</div>
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
                                placeholder="appointment-booking, follow-up-skill"
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
            <div class="hint" style="margin-bottom:12px;">Save Draft updates Sync360 only. Apply pushes the current skill draft into the tenant runtime.</div>
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
@endif
