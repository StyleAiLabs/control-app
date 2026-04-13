<x-layouts.app title="Conversations — Sync360">
    <div class="topbar">
        <div>
            <span class="eyebrow">Conversations</span>
            <h2>Messages with Your Digital Employee</h2>
            <p>Conversation sessions between your customers and your digital employee.</p>
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
            <div style="margin-top: 18px; display: grid; gap: 20px;">
                @foreach ($sessions as $session)
                    @php
                        $firstMsg = $session['messages']->first();
                        $senderName = $firstMsg?->meta_json['sender_name'] ?? null;
                    @endphp
                    <article class="meta-item" style="padding: 0; overflow: hidden;">
                        {{-- Session header --}}
                        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border-color, #e5e7eb); display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap;">
                            <div>
                                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                    <strong>{{ ucfirst($session['channel'] ?? 'Unknown channel') }}</strong>
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

                                @if ($session['ai_summary'])
                                    <p style="margin: 8px 0 0; font-size: 0.92rem; color: var(--text-secondary, #6b7280); line-height: 1.5;">
                                        🤖 {{ $session['ai_summary'] }}
                                    </p>
                                @endif
                            </div>

                            <div style="text-align: right; flex-shrink: 0;">
                                <small class="hint">{{ \Illuminate\Support\Carbon::parse($session['last_activity'])?->format('D, j M Y g:i A') }}</small>
                                <div class="hint" style="margin-top: 4px;">{{ $session['message_count'] }} {{ Str::plural('message', $session['message_count']) }}</div>
                            </div>
                        </div>

                        {{-- Message thread --}}
                        <div style="padding: 16px 20px; display: grid; gap: 16px;">
                            @foreach ($session['messages'] as $message)
                                <div style="display: grid; gap: 10px;">
                                    {{-- Incoming --}}
                                    <div style="display: flex; gap: 12px; align-items: flex-start;">
                                        <div style="width: 28px; height: 28px; border-radius: 50%; background: var(--bg-alt, #f3f4f6); display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.8rem;">👤</div>
                                        <div style="flex: 1;">
                                            <div style="font-size: 0.75rem; color: var(--text-muted, #9ca3af); margin-bottom: 4px;">
                                                {{ $message->created_at?->format('g:i A') }}
                                            </div>
                                            <div style="background: var(--bg-alt, #f3f4f6); border-radius: 12px 12px 12px 2px; padding: 10px 14px; line-height: 1.55; font-size: 0.9rem;">{{ $message->message_in ?: '—' }}</div>
                                        </div>
                                    </div>

                                    {{-- Reply --}}
                                    @if ($message->message_out)
                                        <div style="display: flex; gap: 12px; align-items: flex-start; flex-direction: row-reverse;">
                                            <div style="width: 28px; height: 28px; border-radius: 50%; background: var(--accent, #6366f1); display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 0.8rem; color: #fff;">🤖</div>
                                            <div style="flex: 1; text-align: right;">
                                                <div style="font-size: 0.75rem; color: var(--text-muted, #9ca3af); margin-bottom: 4px;">
                                                    {{ $message->responded_at?->format('g:i A') ?? $message->created_at?->format('g:i A') }}
                                                </div>
                                                <div style="background: var(--accent, #6366f1); color: #fff; border-radius: 12px 12px 2px 12px; padding: 10px 14px; line-height: 1.55; font-size: 0.9rem; text-align: left; display: inline-block; max-width: 85%;">{{ $message->message_out }}</div>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        @if ($session['session_id'])
                            <div style="padding: 8px 20px 12px; border-top: 1px solid var(--border-color, #e5e7eb);">
                                <span class="hint" style="font-size: 0.75rem;">Session {{ $session['session_id'] }}</span>
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>

            <div style="margin-top: 20px;">
                {{ $sessionPage->links() }}
            </div>
        @endif
    </section>
</x-layouts.app>
