<x-layouts.app title="Conversations — Sync360">
    <div class="topbar">
        <div>
            <span class="eyebrow">Conversations</span>
            <h2>Messages with Your Digital Employee</h2>
            <p>Session-level log of customer conversations across your connected channels.</p>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="{{ route('dashboard') }}" class="button button--secondary">Back to Dashboard</a>
            <a href="{{ route('profile.show') }}" class="button button--secondary">Update Business Details</a>
        </div>
    </div>

    <section class="stats" style="margin-bottom: 20px;">
        <div class="stat">
            <div class="hint">Messages</div>
            <strong>{{ $conversationStats['matched'] }}</strong>
            <p>Messages matching the current filters.</p>
        </div>
        <div class="stat">
            <div class="hint">Responded</div>
            <strong>{{ $conversationStats['replied'] }}</strong>
            <p>Messages where your digital employee replied.</p>
        </div>
        <div class="stat">
            <div class="hint">Telegram</div>
            @if ($tenant->channel === 'telegram')
                <strong>{{ $conversationStats['telegram'] }}</strong>
                <p>Messages through your Telegram connection.</p>
            @else
                <strong style="color: var(--text-muted, #9ca3af); font-size: 0.95rem;">Not connected</strong>
                <p>Telegram is not connected for this workspace.</p>
            @endif
        </div>
        <div class="stat">
            <div class="hint">WhatsApp</div>
            @if ($tenant->channel === 'whatsapp')
                <strong>{{ $conversationStats['whatsapp'] }}</strong>
                <p>Messages through your WhatsApp connection.</p>
            @else
                <strong style="color: var(--text-muted, #9ca3af); font-size: 0.95rem;">Not connected</strong>
                <p>WhatsApp is not connected for this workspace.</p>
            @endif
        </div>
    </section>

    <section class="panel">
        <span class="eyebrow">Filters</span>
        <form method="GET" action="{{ route('conversations.index') }}" style="margin-top: 18px;">
            <div class="field-grid">
                <label>
                    Search
                    <input
                        type="text"
                        name="search"
                        value="{{ $filters['search'] }}"
                        placeholder="Customer number, message text, session ID"
                    >
                </label>
                <label>
                    Channel
                    <select name="channel">
                        <option value="">All channels</option>
                        @if ($tenant->channel === 'telegram')
                            <option value="telegram" @selected($filters['channel'] === 'telegram')>Telegram</option>
                        @elseif ($tenant->channel === 'whatsapp')
                            <option value="whatsapp" @selected($filters['channel'] === 'whatsapp')>WhatsApp</option>
                        @else
                            <option value="" disabled>No channel connected yet</option>
                        @endif
                    </select>
                </label>
            </div>

            <div class="field-grid">
                <label>
                    Reply Status
                    <select name="reply_status">
                        <option value="">All messages</option>
                        <option value="replied" @selected($filters['reply_status'] === 'replied')>Replied</option>
                        <option value="unreplied" @selected($filters['reply_status'] === 'unreplied')>No reply sent</option>
                    </select>
                </label>
                <div class="field-grid" style="grid-template-columns: repeat(2, minmax(0, 1fr));">
                    <label>
                        From
                        <input type="date" name="date_from" value="{{ $filters['date_from'] }}">
                    </label>
                    <label>
                        To
                        <input type="date" name="date_to" value="{{ $filters['date_to'] }}">
                    </label>
                </div>
            </div>

            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <button type="submit">Apply Filters</button>
                <a href="{{ route('conversations.index') }}" class="button button--secondary">Clear</a>
            </div>
        </form>
    </section>

    <section class="panel" style="margin-top: 18px;">
        <div style="display: flex; justify-content: space-between; gap: 12px; align-items: center; flex-wrap: wrap;">
            <span class="eyebrow">Session History</span>
            <div class="hint">
                Showing {{ $sessionPage->firstItem() ?? 0 }}–{{ $sessionPage->lastItem() ?? 0 }} of {{ $sessionPage->total() }} sessions
            </div>
        </div>

        @if ($sessions->isEmpty())
            <div class="note" style="margin-top: 18px;">
                No sessions recorded yet. Once your customers start a conversation through your connected channel, the history will appear here.
            </div>
        @else
            <div style="margin-top: 18px; display: grid; gap: 12px;">
                @foreach ($sessions as $session)
                    @php $firstMsg = $session['messages']->first(); $senderName = $firstMsg?->meta_json['sender_name'] ?? null; @endphp
                    <article class="meta-item">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap;">
                            {{-- Left: sender + status + summary --}}
                            <div style="flex: 1; min-width: 0;">
                                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                    <strong>{{ ucfirst($session['channel'] ?? 'Unknown') }}</strong>
                                    <span class="hint">{{ $session['from_identifier'] ?? '—' }}</span>
                                    @if ($senderName)
                                        <span class="hint">· {{ $senderName }}</span>
                                    @endif
                                    @if ($session['has_reply'])
                                        <span class="badge ready">Replied</span>
                                    @else
                                        <span class="badge pending">No reply</span>
                                    @endif
                                </div>

                                {{-- AI Summary --}}
                                @if ($session['ai_summary'])
                                    <p style="margin: 10px 0 0; font-size: 0.9rem; color: var(--text-secondary, #6b7280); line-height: 1.55;">
                                        {{ $session['ai_summary'] }}
                                    </p>
                                @else
                                    <p style="margin: 10px 0 0; font-size: 0.86rem; color: var(--text-muted, #9ca3af); font-style: italic;">
                                        Summary not yet generated.
                                    </p>
                                @endif
                            </div>

                            {{-- Right: date + message count --}}
                            <div style="text-align: right; flex-shrink: 0;">
                                <small class="hint">{{ \Illuminate\Support\Carbon::parse($session['last_activity'])?->format('D, j M Y g:i A') }}</small>
                                <div class="hint" style="margin-top: 4px;">
                                    {{ $session['message_count'] }} {{ Str::plural('message', $session['message_count']) }}
                                </div>
                                @if ($session['session_id'])
                                    <div class="hint" style="margin-top: 4px; font-size: 0.72rem; font-family: monospace;">
                                        {{ substr($session['session_id'], 0, 8) }}…
                                    </div>
                                @endif
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>

            <div style="margin-top: 20px;">
                {{ $sessionPage->links() }}
            </div>
        @endif
    </section>
</x-layouts.app>
