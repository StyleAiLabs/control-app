@if (! $agentCustomizationAvailable)
    <section class="panel">
        <div class="topbar" style="margin-bottom: 16px;">
            <div>
                <span class="eyebrow">Agent Runtime</span>
                <h2 style="font-size: 1.2rem;">Agent Runtime</h2>
            </div>
        </div>
        <div class="note error">
            Agent runtime customization is unavailable until the tenant customization migrations are applied locally.
        </div>
        <div class="hint" style="margin-top: 14px;">
            Run the new tenant customization migrations locally, then refresh this page to enable prompt drafts, previews, and apply controls.
        </div>
    </section>
@else
    <section class="panel">
        <div class="topbar" style="margin-bottom: 16px;">
            <div>
                <span class="eyebrow">Agent Runtime</span>
                <h2 style="font-size: 1.2rem;">Agent Runtime</h2>
                <p>Manage model defaults, prompt overrides, current markdown previews, and preview output for this tenant.</p>
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
            <input type="hidden" name="return_tab" value="agent-runtime">
            <input type="hidden" name="customization_scope" value="agent-runtime">

            <section>
                <h3 style="margin-top:0;">Runtime Defaults</h3>
                <label>
                    Model
                    <input type="text" name="agent_defaults[model]" value="{{ $agentDefaults['model'] ?? '' }}" placeholder="gpt-4o">
                </label>
            </section>

            <div class="grid grid-2" style="margin-top: 18px;">
                @foreach (['identity' => 'IDENTITY.md', 'soul' => 'SOUL.md', 'user' => 'USER.md', 'bootstrap' => 'BOOTSTRAP.md'] as $key => $label)
                    @php
                        $override = $promptOverrides[$key] ?? [];
                    @endphp
                    <label>
                        {{ $label }}
                        <div class="hint" style="margin:8px 0 6px;">Current {{ $label }}</div>
                        <pre style="white-space:pre-wrap; max-height:220px; overflow:auto; background:rgba(0,0,0,0.16); padding:12px; border-radius:12px; margin-bottom:10px;">{{ $currentCustomizationPreview[$label] ?? 'Current file content is not available yet.' }}</pre>
                        <div style="display:flex; gap:10px; margin:8px 0;">
                            <label class="inline"><input type="radio" name="prompt_overrides[{{ $key }}][mode]" value="append" {{ ($override['mode'] ?? 'append') === 'append' ? 'checked' : '' }}> Append</label>
                            <label class="inline"><input type="radio" name="prompt_overrides[{{ $key }}][mode]" value="replace" {{ ($override['mode'] ?? null) === 'replace' ? 'checked' : '' }}> Replace</label>
                        </div>
                        <textarea name="prompt_overrides[{{ $key }}][content]" rows="8" placeholder="No override saved">{{ $override['content'] ?? '' }}</textarea>
                    </label>
                @endforeach
            </div>

            <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:18px;">
                <button type="submit">Save Draft</button>
                <button type="button" class="button button--secondary" data-preview-customization>Preview</button>
            </div>
        </form>

        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:18px;">
            <form method="POST" action="{{ route('admin.tenants.agent-customization.apply', $tenant) }}" class="inline">
                @csrf
                <input type="hidden" name="return_tab" value="agent-runtime">
                <input type="hidden" name="customization_scope" value="agent-runtime">
                <button type="submit" {{ $canApplyAgentCustomization ? '' : 'disabled' }}>Apply</button>
            </form>
            <form method="POST" action="{{ route('admin.tenants.agent-customization.revert', $tenant) }}" class="inline">
                @csrf
                <input type="hidden" name="return_tab" value="agent-runtime">
                <input type="hidden" name="customization_scope" value="agent-runtime">
                <button type="submit" class="button button--secondary" {{ $canApplyAgentCustomization && $agentCustomization?->last_applied_input_snapshot_json ? '' : 'disabled' }}>Revert</button>
            </form>
        </div>

        <div class="hint" style="margin-top: 14px;">
            Apply and revert queue a tenant-scoped background job. Skill packs and default skill IDs are now managed from the Skills tab.
        </div>

        <section style="margin-top:18px;">
            <h3 style="margin-top:0;">Preview</h3>
            <pre data-customization-preview style="white-space:pre-wrap; max-height:420px; overflow:auto; background:rgba(0,0,0,0.24); padding:14px; border-radius:12px;">Preview output will appear here.</pre>
        </section>
    </section>
@endif
