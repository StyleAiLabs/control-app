@if (! $agentCustomizationAvailable)
    <section class="panel">
        <div class="topbar" style="margin-bottom: 16px;">
            <div>
                <span class="eyebrow">Tenant Skills</span>
                <h2 style="font-size: 1.2rem;">Tenant Skills</h2>
            </div>
        </div>
        <div class="note error">
            Tenant skills are unavailable until the tenant customization migrations are applied locally.
        </div>
        <div class="hint" style="margin-top: 14px;">
            Run the new tenant customization migrations locally, then refresh this page to enable skill pack drafts, apply, and history.
        </div>
    </section>
@else
    <section class="panel">
        <div class="topbar" style="margin-bottom: 16px;">
            <div>
                <span class="eyebrow">Tenant Skills</span>
                <h2 style="font-size: 1.2rem;">Tenant Skills</h2>
                <p>Manage Sync360 skill packs, default skill IDs, and tenant skill deployment state without mixing them into prompt editing.</p>
            </div>
            <div style="display:flex; gap:10px; flex-wrap:wrap;">
                <span class="badge {{ $agentCustomization?->last_apply_status === 'failed' ? 'failed' : ($agentCustomization?->applied_snapshot_hash ? 'ready' : 'pending') }}">
                    {{ $agentCustomization?->last_apply_status ?? 'draft only' }}
                </span>
                <span class="badge pending">
                    draft v{{ $agentCustomization?->draft_version ?? 0 }}
                </span>
            </div>
        </div>

        <form method="POST" action="{{ route('admin.tenants.agent-customization.update', $tenant) }}" class="field-single">
            @csrf
            @method('PATCH')
            <input type="hidden" name="return_tab" value="skills">
            <input type="hidden" name="customization_scope" value="skills">

            <div class="grid grid-2">
                <section>
                    <h3 style="margin-top:0;">Skill Packs</h3>
                    <div style="display:grid; gap:12px;">
                        @forelse ($skillRegistry as $skillPack)
                            <label style="display:block; padding:12px; border:1px solid rgba(255,255,255,0.08); border-radius:12px;">
                                <div style="display:flex; gap:10px; align-items:flex-start;">
                                    <input
                                        type="checkbox"
                                        name="assigned_skill_pack_ids[]"
                                        value="{{ $skillPack['id'] }}"
                                        {{ in_array($skillPack['id'], $assignedSkillPackIds, true) ? 'checked' : '' }}
                                    >
                                    <div>
                                        <strong>{{ $skillPack['label'] }}</strong>
                                        <div class="hint">Version {{ $skillPack['version'] }}</div>
                                        <div style="margin-top:6px;">{{ $skillPack['description'] }}</div>
                                    </div>
                                </div>
                            </label>
                        @empty
                            <div class="hint">No Sync360 skill packs are registered yet.</div>
                        @endforelse
                    </div>
                </section>

                <section>
                    <h3 style="margin-top:0;">Default Skill IDs</h3>
                    <label>
                        Default Skill IDs
                        <input
                            type="text"
                            name="agent_defaults[default_skill_ids]"
                            value="{{ implode(', ', $agentDefaults['default_skill_ids'] ?? []) }}"
                            placeholder="custom-default-skill"
                            data-skill-ids-input
                        >
                    </label>
                    <div class="hint" style="margin-top:8px;">Comma-separated skill IDs to add to the default agent skill list.</div>
                </section>
            </div>

            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:18px;">
                <button type="submit">Save Draft</button>
            </div>
        </form>

        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:18px;">
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

        <div class="hint" style="margin-top: 14px;">
            Apply and revert queue a tenant-scoped background job. Use Agent Runtime for model and prompt behavior changes.
        </div>

        <section style="margin-top:18px;">
            <h3 style="margin-top:0;">Apply History</h3>
            <div style="display:grid; gap:10px;">
                @forelse ($tenant->agentCustomizationApplies->sortByDesc('id') as $entry)
                    <div style="padding:12px; border:1px solid rgba(255,255,255,0.08); border-radius:12px;">
                        <strong>{{ $entry->action }}</strong>
                        <span class="badge {{ $entry->status === 'failed' ? 'failed' : 'ready' }}">{{ $entry->status }}</span>
                        <div class="hint" style="margin-top:6px;">{{ $entry->created_at?->toDateTimeString() ?? 'Pending timestamp' }}</div>
                        <div class="hint">Before: {{ $entry->before_output_hash ?? '—' }}</div>
                        <div class="hint">After: {{ $entry->after_output_hash ?? '—' }}</div>
                        @if ($entry->error)
                            <div class="note error" style="margin-top:8px;">{{ $entry->error }}</div>
                        @endif
                    </div>
                @empty
                    <div class="hint">No apply history yet.</div>
                @endforelse
            </div>
        </section>
    </section>
@endif
