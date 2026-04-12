<x-layouts.app title="Conversations — Sync360">
    <div class="topbar">
        <div>
            <span class="eyebrow">Conversations</span>
            <h2>See what customers are asking your digital employee.</h2>
            <p>Search, filter, and review recent inbound messages and the replies your assistant sent back.</p>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="{{ route('dashboard') }}" class="button button--secondary">Back to Dashboard</a>
            <a href="{{ route('profile.show') }}" class="button button--secondary">Update Business Details</a>
        </div>
    </div>

    <section class="stats" style="margin-bottom: 20px;">
        <div class="stat">
            <div class="hint">Matched</div>
            <strong>{{ $conversationStats['matched'] }}</strong>
            <p>Conversation records that match the current filters.</p>
        </div>
        <div class="stat">
            <div class="hint">Replied</div>
            <strong>{{ $conversationStats['replied'] }}</strong>
            <p>Messages that received a reply from the assistant.</p>
        </div>
        <div class="stat">
            <div class="hint">WhatsApp</div>
            <strong>{{ $conversationStats['whatsapp'] }}</strong>
            <p>Messages delivered through your WhatsApp connection.</p>
        </div>
        <div class="stat">
            <div class="hint">Telegram</div>
            <strong>{{ $conversationStats['telegram'] }}</strong>
            <p>Messages delivered through your Telegram connection.</p>
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
                        placeholder="Customer number, incoming message, reply text, or message ID"
                    >
                </label>
                <label>
                    Channel
                    <select name="channel">
                        <option value="">All channels</option>
                        <option value="whatsapp" @selected($filters['channel'] === 'whatsapp')>WhatsApp</option>
                        <option value="telegram" @selected($filters['channel'] === 'telegram')>Telegram</option>
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
            <span class="eyebrow">History</span>
            <div class="hint">
                Showing {{ $conversations->firstItem() ?? 0 }}-{{ $conversations->lastItem() ?? 0 }} of {{ $conversations->total() }}
            </div>
        </div>

        @if ($conversations->isEmpty())
            <div class="note" style="margin-top: 18px;">
                No conversations matched the current filters yet. Once customer messages arrive through the connected channel, they will appear here.
            </div>
        @else
            <div style="margin-top: 18px; display: grid; gap: 14px;">
                @foreach ($conversations as $conversation)
                    <article class="meta-item">
                        <div style="display: flex; justify-content: space-between; gap: 12px; align-items: center; flex-wrap: wrap;">
                            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                                <strong>{{ ucfirst($conversation->channel) }}</strong>
                                <span class="hint">{{ $conversation->from_identifier }}</span>
                                @if (filled($conversation->message_out))
                                    <span class="badge ready">Replied</span>
                                @else
                                    <span class="badge pending">No reply</span>
                                @endif
                            </div>
                            <small class="hint">{{ $conversation->created_at?->format('D, j M Y g:i A') }}</small>
                        </div>

                        <div class="meta" style="margin-top: 14px;">
                            <div>
                                <small class="hint">Incoming</small>
                                <div style="margin-top: 6px; line-height: 1.6;">{{ $conversation->message_in }}</div>
                            </div>
                            <div>
                                <small class="hint">Reply</small>
                                <div style="margin-top: 6px; line-height: 1.6;">
                                    {{ $conversation->message_out ?: 'No reply was sent for this message.' }}
                                </div>
                            </div>
                        </div>

                        <div style="margin-top: 14px; display: flex; gap: 18px; flex-wrap: wrap;">
                            <div class="hint">Message ID: {{ $conversation->external_message_id ?: 'Not captured' }}</div>
                            @if ($conversation->responded_at)
                                <div class="hint">Responded {{ $conversation->responded_at->diffForHumans() }}</div>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>

            <div style="margin-top: 20px;">
                {{ $conversations->links() }}
            </div>
        @endif
    </section>
</x-layouts.app>
