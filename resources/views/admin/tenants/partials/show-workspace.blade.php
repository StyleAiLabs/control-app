<div class="sync-poc-detail-grid sync-poc-detail-grid--wide">
    <x-ui.panel title="Server & Workspace" description="Runtime placement, host identity, workspace path, and the latest health signal.">
        <x-slot:actions>
            <x-ui.badge :status="$workspaceState">{{ str_replace('_', ' ', $workspaceState) }}</x-ui.badge>
        </x-slot:actions>

        <div class="sync-poc-detail-grid">
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Client VPS</span>
                <strong class="sync-poc-field__value">{{ $tenant->server?->name ?? 'Unassigned' }}</strong>
            </div>
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Host</span>
                <strong class="sync-poc-field__value sync-poc-field__value--technical">{{ $tenant->server?->host ?? '—' }}</strong>
            </div>
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Workspace URL</span>
                <strong class="sync-poc-field__value sync-poc-field__value--technical">{{ $tenant->workspace_url ?? 'Pending' }}</strong>
            </div>
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Assigned Port</span>
                <strong class="sync-poc-field__value sync-poc-field__value--technical">{{ $tenant->assigned_port ?? 'Pending' }}</strong>
            </div>
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Runtime Path</span>
                <strong class="sync-poc-field__value sync-poc-field__value--technical">{{ $tenant->runtime_path ?? 'Pending' }}</strong>
            </div>
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Last Health Message</span>
                <strong class="sync-poc-field__value">{{ $tenant->health_check_message ?? 'No health check run yet.' }}</strong>
            </div>
        </div>
    </x-ui.panel>

    <x-ui.panel title="Profile & Channel" description="Onboarding progress and the saved customer-facing channel configuration.">
        <x-slot:actions>
            <x-ui.badge :status="$tenant->onboarding_status ?? 'pending'">
                step {{ $tenant->onboarding_step ?? 0 }}
            </x-ui.badge>
        </x-slot:actions>

        <div class="sync-poc-detail-grid">
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Onboarding Status</span>
                <strong class="sync-poc-field__value">{{ $tenant->onboarding_status ?? 'not_started' }}</strong>
            </div>
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Tone</span>
                <strong class="sync-poc-field__value">{{ $tenant->tone ?? 'Not set' }}</strong>
            </div>
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Capabilities</span>
                <strong class="sync-poc-field__value">{{ is_array($tenant->capabilities) && $tenant->capabilities !== [] ? implode(', ', $tenant->capabilities) : 'None saved yet' }}</strong>
            </div>
            <div class="sync-poc-field">
                <span class="sync-poc-field__label">Channel</span>
                <strong class="sync-poc-field__value">{{ $tenant->channel ?? 'Not connected' }}</strong>
                @if ($tenant->channel === 'telegram')
                    <div class="hint" style="margin-top: 6px;">Bot token saved: {{ filled($channelConfig['telegram_bot_token'] ?? null) ? 'Yes' : 'No' }}</div>
                @elseif ($tenant->channel === 'whatsapp')
                    <div class="hint" style="margin-top: 6px;">Phone ID saved: {{ filled($channelConfig['whatsapp_phone_number_id'] ?? null) ? 'Yes' : 'No' }}</div>
                @endif
            </div>
        </div>
    </x-ui.panel>
</div>
