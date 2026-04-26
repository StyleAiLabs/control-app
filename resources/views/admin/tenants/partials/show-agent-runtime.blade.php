@if (! $runtimeCustomizationAvailable)
    <x-ui.panel title="Agent Runtime" description="Prompt drafts, previews, and apply controls are unavailable in this local database state.">
        <div class="note error">
            Agent runtime customization is unavailable until the required tenant customization and skill catalog migrations are applied locally.
        </div>
        <div class="hint" style="margin-top: 14px;">
            Run the required migrations locally, then refresh this page to enable prompt drafts, previews, and apply controls.
        </div>
    </x-ui.panel>
@else
    <x-ui.panel title="Agent Runtime" description="Manage model defaults, prompt overrides, current markdown previews, and preview output for this tenant.">
        <x-slot:actions>
            <x-ui.badge :status="$agentCustomization?->last_apply_status === 'failed' ? 'failed' : ($agentCustomization?->applied_snapshot_hash ? 'ready' : 'pending')" technical>
                {{ $agentCustomization?->last_apply_status ?? 'draft only' }}
            </x-ui.badge>
            <x-ui.badge status="pending" technical>draft v{{ $agentCustomization?->draft_version ?? 0 }}</x-ui.badge>
        </x-slot:actions>

        <form method="POST" action="{{ route('admin.tenants.agent-customization.update', $tenant) }}" class="field-single">
            @csrf
            @method('PATCH')
            <input type="hidden" name="return_tab" value="agent-runtime">
            <input type="hidden" name="customization_scope" value="agent-runtime">

            <section class="sync-poc-subpanel">
                <h3 class="type-section-title" style="margin-top:0; font-size:1.12rem;">Runtime Defaults</h3>
                <label>
                    Model
                    <input type="text" name="agent_defaults[model]" value="{{ $agentDefaults['model'] ?? '' }}" placeholder="gpt-4o">
                </label>
                <label style="margin-top: 14px;">
                    Tenant API Key Override
                    <input type="password" name="agent_defaults[api_key_override]" value="" placeholder="Leave blank to keep the platform-managed tenant key">
                </label>
                <div class="hint" style="margin-top: 8px;">Blank means use the platform-managed tenant LiteLLM key. Filling this field makes the tenant runtime use a tenant-specific key on the next Apply.</div>
                @if ($agentCustomization?->hasRuntimeApiKeyOverride())
                    <div class="hint" style="margin-top: 8px;">{{ $agentCustomization->maskedRuntimeApiKeyOverride() }}</div>
                    <label class="inline" style="margin-top: 10px;">
                        <input type="checkbox" name="agent_defaults[clear_api_key_override]" value="1">
                        Clear saved API key override and return to the platform-managed tenant key
                    </label>
                @endif
            </section>

            <div class="sync-poc-detail-grid sync-poc-detail-grid--wide" style="margin-top: 18px;">
                @foreach (['identity' => 'IDENTITY.md', 'soul' => 'SOUL.md', 'user' => 'USER.md', 'bootstrap' => 'BOOTSTRAP.md', 'agents' => 'AGENTS.md'] as $key => $label)
                    @php
                        $override = $promptOverrides[$key] ?? [];
                    @endphp
                    <section class="sync-poc-subpanel">
                        <label>
                            {{ $label }}
                            <div class="hint" style="margin:8px 0 6px;">Current {{ $label }}</div>
                            <pre class="type-tech type-tech--wrap sync-poc-pre" style="max-height:220px; margin-bottom:10px;">{{ $currentCustomizationPreview[$label] ?? 'Current file content is not available yet.' }}</pre>
                            @if ($key === 'agents')
                                <div class="hint">Generated from current enabled tenant skill assignments. This file updates automatically when skills are assigned or unassigned.</div>
                            @else
                                <div style="display:flex; gap:10px; margin:8px 0;">
                                    <label class="inline"><input type="radio" name="prompt_overrides[{{ $key }}][mode]" value="append" {{ ($override['mode'] ?? 'append') === 'append' ? 'checked' : '' }}> Append</label>
                                    <label class="inline"><input type="radio" name="prompt_overrides[{{ $key }}][mode]" value="replace" {{ ($override['mode'] ?? null) === 'replace' ? 'checked' : '' }}> Replace</label>
                                </div>
                                <textarea name="prompt_overrides[{{ $key }}][content]" rows="8" placeholder="No override saved">{{ $override['content'] ?? '' }}</textarea>
                            @endif
                        </label>
                    </section>
                @endforeach
            </div>

            <div class="sync-poc-panel-actions" style="margin-top:18px;">
                <x-ui.button type="submit" icon="save">Save Draft</x-ui.button>
                <x-ui.button type="button" variant="secondary" icon="eye" data-preview-customization>Preview</x-ui.button>
            </div>
        </form>

        <div class="sync-poc-panel-actions" style="margin-top:18px;">
            <form method="POST" action="{{ route('admin.tenants.agent-customization.apply', $tenant) }}" class="inline">
                @csrf
                <input type="hidden" name="return_tab" value="agent-runtime">
                <input type="hidden" name="customization_scope" value="agent-runtime">
                <x-ui.button type="submit" icon="play" :disabled="! $canApplyAgentCustomization">Apply</x-ui.button>
            </form>
            <form method="POST" action="{{ route('admin.tenants.agent-customization.revert', $tenant) }}" class="inline">
                @csrf
                <input type="hidden" name="return_tab" value="agent-runtime">
                <input type="hidden" name="customization_scope" value="agent-runtime">
                <x-ui.button type="submit" variant="secondary" icon="rotate-ccw" :disabled="! ($canApplyAgentCustomization && $agentCustomization?->last_applied_input_snapshot_json)">Revert</x-ui.button>
            </form>
        </div>

        <div class="hint" style="margin-top: 14px;">
            Apply and revert queue a tenant-scoped background job. Skill packs and default skill IDs are managed from the Skills tab.
        </div>
    </x-ui.panel>

    <x-ui.panel title="Preview" description="Generated runtime customization preview output.">
        <pre data-customization-preview class="type-tech type-tech--wrap sync-poc-pre" style="max-height:420px;">Preview output will appear here.</pre>
    </x-ui.panel>

    <x-ui.panel title="Apply History" description="Recent runtime customization apply and revert jobs.">
        @if ($tenant->agentCustomizationApplies->isEmpty())
            <x-ui.empty-state title="No apply history yet" description="Apply or revert jobs will appear here after they are queued." />
        @else
            <x-ui.table fit>
                <colgroup>
                    <col style="width: 14%;">
                    <col style="width: 14%;">
                    <col style="width: 22%;">
                    <col style="width: 25%;">
                    <col style="width: 25%;">
                </colgroup>
                <thead>
                    <tr>
                        <th>Action</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Before</th>
                        <th>After</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($tenant->agentCustomizationApplies->sortByDesc('id') as $entry)
                        <tr>
                            <td>{{ $entry->action }}</td>
                            <td><x-ui.badge :status="$entry->status === 'failed' ? 'failed' : 'ready'" technical>{{ $entry->status }}</x-ui.badge></td>
                            <td><span class="sync-poc-truncate" title="{{ $entry->created_at?->toDateTimeString() ?? 'Pending timestamp' }}">{{ $entry->created_at?->toDateTimeString() ?? 'Pending timestamp' }}</span></td>
                            <td><span class="sync-poc-truncate type-tech" title="Before: {{ $entry->before_output_hash ?? '—' }}">Before: {{ $entry->before_output_hash ?? '—' }}</span></td>
                            <td><span class="sync-poc-truncate type-tech" title="After: {{ $entry->after_output_hash ?? '—' }}">After: {{ $entry->after_output_hash ?? '—' }}</span></td>
                        </tr>
                        @if ($entry->error)
                            <tr>
                                <td colspan="5">
                                    <div class="note error">{{ $entry->error }}</div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </x-ui.table>
        @endif
    </x-ui.panel>
@endif
