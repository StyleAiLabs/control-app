<x-layouts.app title="Dashboard — Sync360">
    <div class="topbar">
        <div>
            <span class="eyebrow">Dashboard</span>
            <h2>Welcome back, {{ $firstName }}.</h2>
            <p>
                @if ($tenant->onboarding_status === 'complete')
                    {{ $tenant->business_name }} is {{ strtolower($agentContent['label']) }} today.
                @else
                    {{ $tenant->business_name }} is {{ strtolower($provisioningContent['label']) }} and step {{ $onboardingSummary['resume_from_step'] }} is next.
                @endif
            </p>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="{{ $agentContent['primary_cta_route'] }}" class="button button--primary" @if ($tenant->agent_status === 'live' && filled($tenant->workspace_url)) rel="noreferrer" @endif>
                {{ $agentContent['primary_cta_label'] }}
            </a>
            <a href="{{ route('profile.show') }}" class="button button--secondary">Update Business Details</a>
        </div>
    </div>

    <section class="stats" style="margin-bottom: 20px;">
        <div class="stat">
            <div class="hint">Trial</div>
            <strong>{{ $trialContent['label'] }}</strong>
            <p>{{ $trialContent['description'] }}</p>
        </div>
        <div class="stat">
            <div class="hint">Workspace</div>
            <strong>{{ $provisioningContent['label'] }}</strong>
            <p>{{ $provisioningContent['description'] }}</p>
        </div>
        <div class="stat">
            <div class="hint">Skill Pack</div>
            <strong style="font-size: 1.05rem;">{{ $tenant->skill_pack }}</strong>
            <p>What your digital employee is configured to support.</p>
        </div>
        <div class="stat">
            <div class="hint">Industry</div>
            <strong style="font-size: 1.05rem;">{{ $tenant->industry }}</strong>
            <p>Grounded in your business sector.</p>
        </div>
    </section>

    <section class="grid grid-2">
        <div class="panel">
            <span class="eyebrow">Your Business</span>
            <div class="meta" style="margin-top: 18px;">
                <div class="meta-item">
                    <small>Business Name</small>
                    {{ $businessProfile?->business_name ?: $tenant->business_name }}
                </div>
                <div class="meta-item">
                    <small>Industry</small>
                    {{ $businessProfile?->industry ?: $tenant->industry }}
                </div>
                <div class="meta-item">
                    <small>Skill Pack</small>
                    {{ $tenant->skill_pack }}
                </div>
                <div class="meta-item">
                    <small>Contact Details</small>
                    {{ $businessProfile?->contact_email ?: 'No email yet' }}{{ $businessProfile?->contact_phone ? ' • '.$businessProfile->contact_phone : '' }}
                </div>
                <div class="meta-item">
                    <small>Trial Status</small>
                    <span class="badge {{ $tenant->trial_status->value }}">
                        {{ $trialContent['label'] }}
                    </span>
                </div>
            </div>
            <div class="note" style="margin-top: 18px;">
                Keep business details current and we’ll use them the next time the assistant is synced.
            </div>
        </div>

        <div class="panel">
            <span class="eyebrow">Digital Employee Status</span>
            <div style="margin-top: 18px; display: grid; gap: 14px;">
                <div>
                    <small class="hint">Current status</small><br>
                    <span class="badge {{ $agentContent['badge'] }}" style="margin-top: 6px; display: inline-flex;">
                        {{ $agentContent['label'] }}
                    </span>
                </div>
                <div>
                    <small class="hint">What this means</small>
                    <div style="margin-top: 6px; font-weight: 600;">{{ $agentContent['description'] }}</div>
                </div>
                <div class="meta" style="margin-top: 4px;">
                    <div class="meta-item">
                        <small>Channel</small>
                        @if ($tenant->channel === 'whatsapp')
                            <span style="display: inline-flex; align-items: center; gap: 6px;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z" fill="#25D366"/><path d="M12.004 2C6.489 2 2 6.489 2 12.004c0 1.762.46 3.476 1.333 4.99L2 22l5.233-1.237A9.956 9.956 0 0012.004 22C17.52 22 22 17.52 22 12.004 22 6.489 17.52 2 12.004 2zm0 18.15A8.14 8.14 0 017.55 18.8l-.35-.21-3.1.73.82-3-.23-.36a8.108 8.108 0 01-1.24-4.35C3.45 7.29 7.29 3.45 12 3.45c2.27 0 4.4.88 6.01 2.49a8.453 8.453 0 012.49 6.01c.01 4.72-3.84 8.56-8.5 8.56v-.01z" fill="#25D366"/></svg>
                                WhatsApp
                            </span>
                        @elseif ($tenant->channel === 'telegram')
                            <span style="display: inline-flex; align-items: center; gap: 6px;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-.98-.19-1.46-.35-.59-.2-1.06-.3-1.02-.64.02-.18.27-.36.74-.55 2.92-1.27 4.86-2.11 5.83-2.51 2.78-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z" fill="#229ED9"/></svg>
                                Telegram
                            </span>
                        @else
                            <span style="color: var(--text-muted, #9ca3af);">Not connected yet</span>
                        @endif
                    </div>
                    <div class="meta-item">
                        <small>Tone</small>
                        {{ $tenant->tone ? ucfirst($tenant->tone) : 'Not set yet' }}
                    </div>
                    <div class="meta-item">
                        <small>Capabilities</small>
                        {{ is_array($tenant->capabilities) && $tenant->capabilities !== [] ? implode(', ', $tenant->capabilities) : 'Not selected yet' }}
                    </div>
                    <div class="meta-item">
                        <small>Last synced</small>
                        {{ $businessProfile?->last_synced_to_agent?->diffForHumans() ?: 'Not synced yet' }}
                    </div>
                </div>
                <a href="{{ $agentContent['primary_cta_route'] }}" class="button button--primary" style="align-self: start;" @if ($tenant->agent_status === 'live' && filled($tenant->workspace_url)) rel="noreferrer" @endif>
                    {{ $agentContent['primary_cta_label'] }}
                </a>
                @if ($tenant->agent_status === 'live')
                    <a href="{{ route('conversations.index') }}" class="button button--secondary" style="align-self: start;">View Messages</a>
                @else
                    <a href="{{ route('profile.show') }}" class="button button--secondary" style="align-self: start;">Edit Business Profile</a>
                @endif
            </div>
        </div>
    </section>

    <section class="grid grid-2" style="margin-top: 18px;">
        <div class="panel">
            <span class="eyebrow">Setup Progress</span>
            @if ($tenant->onboarding_status === 'complete')
                <div class="note" style="margin-top: 18px;">
                    Guided setup is complete. You can still return to the setup flow any time to change your business details, channel, or live assistant configuration.
                </div>
                <div style="margin-top: 18px; display: grid; gap: 14px;">
                    @foreach ($onboardingSummary['steps'] as $number => $step)
                        <div class="meta-item">
                            <small>Step {{ $number }}</small>
                            <div style="display: flex; justify-content: space-between; gap: 12px; align-items: center;">
                                <strong>{{ $step['label'] }}</strong>
                                <span class="badge ready">Done</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="note" style="margin-top: 18px;">
                    {{ $onboardingSummary['completed_steps'] }} of {{ $onboardingSummary['total_steps'] }} setup steps are complete. Your next step is <strong>{{ $onboardingSummary['steps'][$onboardingSummary['resume_from_step']]['label'] }}</strong>.
                </div>
                <div style="margin-top: 18px; display: grid; gap: 14px;">
                    @foreach ($onboardingSummary['steps'] as $number => $step)
                        <div class="meta-item">
                            <small>Step {{ $number }}</small>
                            <div style="display: flex; justify-content: space-between; gap: 12px; align-items: center;">
                                <strong>{{ $step['label'] }}</strong>
                                @if ($step['status'] === 'complete')
                                    <span class="badge ready">Done ✓</span>
                                @elseif ($number === $onboardingSummary['resume_from_step'])
                                    <span class="badge pending" style="background: #FF6B35; color: #fff;">Next →</span>
                                @else
                                    <span class="badge" style="background: #f3f4f6; color: #6b7280;">Pending</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
                <a href="{{ route('onboarding.show') }}" class="button button--primary" style="margin-top: 18px;">Continue Setup</a>
            @endif
        </div>

        <div class="panel">
            <span class="eyebrow">Conversation Activity</span>
            <section class="stats" style="margin-top: 18px; margin-bottom: 0;">
                <div class="stat">
                    <div class="hint">Total</div>
                    <strong>{{ $conversationStats['total'] }}</strong>
                    <p>All customer conversations logged so far.</p>
                </div>
                <div class="stat">
                    <div class="hint">Today</div>
                    <strong>{{ $conversationStats['today'] }}</strong>
                    <p>Messages handled since midnight.</p>
                </div>
                <div class="stat">
                    <div class="hint">This Week</div>
                    <strong>{{ $conversationStats['week'] }}</strong>
                    <p>Conversations in the current week.</p>
                </div>
            </section>

            <div style="margin-top: 18px; display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="{{ route('conversations.index') }}" class="button button--secondary">View All Conversations</a>
            </div>

            @if ($tenant->agent_status === 'live' && $recentConversations->isNotEmpty())
                <div style="margin-top: 18px; display: grid; gap: 14px;">
                    @foreach ($recentConversations as $conversation)
                        <div class="meta-item">
                            <div style="display: flex; justify-content: space-between; gap: 12px; align-items: center;">
                                <div>
                                    <strong>{{ ucfirst($conversation->channel) }}</strong>
                                    <span class="hint"> • {{ $conversation->from_identifier }}</span>
                                </div>
                                <small class="hint">{{ $conversation->created_at?->diffForHumans() }}</small>
                            </div>
                            <div style="margin-top: 10px; display: grid; gap: 8px;">
                                <div>
                                    <small class="hint">Incoming</small>
                                    <div>{{ \Illuminate\Support\Str::limit($conversation->message_in, 140) }}</div>
                                </div>
                                <div>
                                    <small class="hint">Reply</small>
                                    <div>{{ $conversation->message_out ? \Illuminate\Support\Str::limit($conversation->message_out, 140) : 'No reply was sent.' }}</div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @elseif ($tenant->agent_status === 'live')
                <div class="note" style="margin-top: 18px;">
                    Your assistant is live, but no customer conversations have been logged yet. Once messages start coming in through the connected channel, they’ll show up here.
                </div>
            @else
                <div class="note" style="margin-top: 18px;">
                    Conversation history will appear here after the assistant is live and customer messages start arriving.
                </div>
            @endif
        </div>
    </section>
</x-layouts.app>
