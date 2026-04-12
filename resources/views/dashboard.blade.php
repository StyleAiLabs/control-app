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
            <a href="{{ $agentContent['primary_cta_route'] }}" class="button button--primary" @if ($tenant->agent_status === 'live' && filled($tenant->workspace_url)) target="_blank" rel="noreferrer" @endif>
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
                            WhatsApp
                        @elseif ($tenant->channel === 'telegram')
                            Telegram
                        @else
                            Not connected yet
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
                <a href="{{ $agentContent['primary_cta_route'] }}" class="button button--primary" style="align-self: start;" @if ($tenant->agent_status === 'live' && filled($tenant->workspace_url)) target="_blank" rel="noreferrer" @endif>
                    {{ $agentContent['primary_cta_label'] }}
                </a>
                <a href="{{ route('profile.show') }}" class="button button--secondary" style="align-self: start;">
                    Edit Business Profile
                </a>
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
                                <span class="badge {{ $step['status'] === 'complete' ? 'ready' : 'pending' }}">
                                    {{ $step['status'] === 'complete' ? 'Done' : 'Next up' }}
                                </span>
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
