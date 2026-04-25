<x-ui.panel title="Inbox Monitor" description="Operator view for Inbox Triage polling health, recent detections, and runtime readiness.">
    @php
        $monitor = is_array($inboxMonitorSummary ?? null) ? $inboxMonitorSummary : [];
        $counts = is_array($monitor['message_counts'] ?? null) ? $monitor['message_counts'] : [];
        $recentMessages = is_array($monitor['recent_messages'] ?? null) ? $monitor['recent_messages'] : [];
    @endphp

    <x-slot:actions>
        <x-ui.badge :status="$monitor['status'] ?? 'pending'" technical>
            {{ $monitor['status_label'] ?? 'Pending' }}
        </x-ui.badge>
    </x-slot:actions>

    <section class="sync-poc-subpanel" style="margin-bottom: 18px;">
        <span class="eyebrow">Monitor State</span>
        <div class="sync-poc-detail-grid" style="margin-top: 14px;">
            <div class="sync-poc-field">
                <strong>Enabled</strong>
                <div class="hint" style="margin-top: 6px;">{{ ($monitor['enabled'] ?? false) ? 'yes' : 'no' }}</div>
            </div>
            <div class="sync-poc-field">
                <strong>Assigned skill version</strong>
                <div class="hint" style="margin-top: 6px;">{{ $monitor['assigned_skill_version'] ?? 'not assigned' }}</div>
            </div>
            <div class="sync-poc-field">
                <strong>Last poll</strong>
                <div class="hint" style="margin-top: 6px;">{{ $monitor['last_checked_at'] ?? 'never' }}</div>
            </div>
            <div class="sync-poc-field">
                <strong>Backoff until</strong>
                <div class="hint" style="margin-top: 6px;">{{ $monitor['backoff_until'] ?? 'not in backoff' }}</div>
            </div>
            <div class="sync-poc-field">
                <strong>Google runtime state</strong>
                <div class="hint" style="margin-top: 6px;">{{ $monitor['google_runtime_label'] ?? 'not connected' }}</div>
            </div>
            <div class="sync-poc-field">
                <strong>Consecutive failures</strong>
                <div class="hint" style="margin-top: 6px;">{{ $monitor['consecutive_failures'] ?? 0 }}</div>
            </div>
        </div>

        @if (! empty($monitor['last_error']))
            <div class="note error" style="margin-top: 14px;">
                {{ $monitor['last_error'] }}
            </div>
        @endif
    </section>

    <section class="sync-poc-subpanel" style="margin-bottom: 18px;">
        <span class="eyebrow">Recent Totals</span>
        <div class="sync-poc-detail-grid" style="margin-top: 14px;">
            <div class="sync-poc-field">
                <strong>Sent to agent</strong>
                <div class="hint" style="margin-top: 6px;">{{ $counts['sent_to_agent'] ?? 0 }}</div>
            </div>
            <div class="sync-poc-field">
                <strong>Skipped</strong>
                <div class="hint" style="margin-top: 6px;">{{ $counts['skipped'] ?? 0 }}</div>
            </div>
            <div class="sync-poc-field">
                <strong>Failed</strong>
                <div class="hint" style="margin-top: 6px;">{{ $counts['failed'] ?? 0 }}</div>
            </div>
            <div class="sync-poc-field">
                <strong>Last failure</strong>
                <div class="hint" style="margin-top: 6px;">{{ $monitor['last_failed_at'] ?? 'none' }}</div>
            </div>
        </div>
    </section>

    <section>
        <h3 class="type-section-title" style="margin-top:0; font-size:1.12rem;">Recent Inbox Events</h3>
        <div class="hint" style="margin-top:6px; margin-bottom:12px;">Operational message history only. Customer-facing auto-reply visibility stays in normal conversation logs.</div>

        <div style="display:grid; gap:10px;">
            @forelse ($recentMessages as $message)
                <div class="sync-poc-field">
                    <div style="display:flex; justify-content:space-between; gap:12px; align-items:start; flex-wrap:wrap;">
                        <div style="min-width:0;">
                            <strong style="display:block;">{{ $message['subject_preview'] ?: 'No subject preview' }}</strong>
                            <div class="hint" style="margin-top:6px;">
                                {{ $message['gmail_message_id'] ?? 'unknown message id' }}
                                @if (! empty($message['sender_domain']))
                                    · {{ $message['sender_domain'] }}
                                @endif
                            </div>
                        </div>
                        <x-ui.badge :status="match($message['status'] ?? null) {
                            'sent_to_agent' => 'ready',
                            'skipped' => 'pending',
                            'failed' => 'failed',
                            default => 'pending',
                        }" technical>
                            {{ $message['status'] ?? 'unknown' }}
                        </x-ui.badge>
                    </div>
                    <div class="hint" style="margin-top:8px;">
                        Detected {{ $message['detected_at'] ?? 'unknown' }}
                        @if (! empty($message['delivered_to_agent_at']))
                            · delivered {{ $message['delivered_to_agent_at'] }}
                        @endif
                        @if (! empty($message['attempts']))
                            · attempts {{ $message['attempts'] }}
                        @endif
                    </div>
                    @if (! empty($message['skip_reason']))
                        <div class="hint" style="margin-top:8px;">Skip reason: {{ $message['skip_reason'] }}</div>
                    @endif
                    @if (! empty($message['last_error']))
                        <div class="hint" style="margin-top:8px; color:#b45309;">{{ $message['last_error'] }}</div>
                    @endif
                </div>
            @empty
                <div class="hint">No inbox monitor events have been recorded yet.</div>
            @endforelse
        </div>
    </section>
</x-ui.panel>
