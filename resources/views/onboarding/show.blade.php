<x-layouts.app title="Guided Setup — Sync360">
    @php
        $initialStep = request()->integer('step', (int) ($state['resume_from_step'] ?? 1));
        $totalSteps = count($state['steps'] ?? []);
        $currentStepMeta = $state['steps'][$initialStep] ?? ['label' => 'Setup'];
        $completedSteps = collect($state['steps'] ?? [])->where('status', 'complete')->count();
        $progressPercent = (int) round(($initialStep / max($totalSteps, 1)) * 100);
        $progressNote = $completedSteps > 0
            ? $completedSteps.' of '.$totalSteps.' steps are complete. We will carry the technical setup in the background while you finish the remaining details.'
            : 'Start with the basics and we will keep the technical setup moving behind the scenes.';
        $statusTone = match (true) {
            ($state['provisioning_status'] ?? null) === 'failed' => 'error',
            default => 'warning',
        };
        $statusLabel = match (true) {
            ($state['provisioning_status'] ?? null) === 'failed' => 'Workspace setup needs attention',
            default => 'Workspace setup in progress',
        };
        $statusNote = match (true) {
            ($state['provisioning_status'] ?? null) === 'failed' => 'The background workspace setup hit an issue. You can keep filling in your details while support checks the runtime setup.',
            default => 'We are preparing the workspace in the background. Moving around this wizard will not restart that setup.',
        };
        $showSetupStatus = ($state['agent_status'] ?? null) !== 'live'
            && ($state['provisioning_status'] ?? null) !== 'ready';
    @endphp

    <div class="wizard-wrapper sync-onboarding-shell">
        <header class="sync-onboarding-hero">
            <div class="sync-onboarding-hero__copy">
                <span class="eyebrow">Guided Setup</span>
                <h2>Set up your digital employee</h2>
                <p>Finish the essentials and we will handle the technical setup in the background.</p>
            </div>

            <div class="sync-onboarding-hero__actions">
                <x-ui.button :href="route('dashboard')" variant="secondary" icon="arrow-left">
                    Back to Dashboard
                </x-ui.button>
                @if (($state['workspace']['ready'] ?? false) === false)
                    <x-ui.button :href="route('tenant.setup')" variant="secondary" icon="activity">
                        Watch Workspace Setup
                    </x-ui.button>
                @elseif (! empty($state['workspace']['url']))
                    <x-ui.button :href="$state['workspace']['url']" icon="external-link" icon-position="after" rel="noreferrer">
                        Open Sync360 Workspace
                    </x-ui.button>
                @endif
            </div>
        </header>

        <x-ui.step-progress
            :steps="$state['steps']"
            :current-step="$initialStep"
            :current-label="$currentStepMeta['label']"
            :progress-percent="$progressPercent"
            :progress-note="$progressNote"
            :completed-steps="$completedSteps"
            :total-steps="$totalSteps"
        />

        <x-ui.setup-status
            id="workspace-setup-strip"
            :status="$statusLabel"
            :note="$statusNote"
            :tone="$statusTone"
            :visible="$showSetupStatus"
            status-id="workspace-setup-badge"
            note-id="workspace-setup-note"
        />

        {{-- ═══════════════════ STEP 1 — Read Website ═══════════════════ --}}
        <x-ui.panel variant="subtle" class="wizard-panel sync-onboarding-step" data-wizard-step="1" id="wizard-step-1" style="display: none;">
            <div class="sync-onboarding-step__intro">
                <span class="eyebrow">Step 1</span>
                <h3 class="type-section-title">Read your business website</h3>
                <p>Start with your website and we will pull in the basics for you. If you would rather type things in yourself, you can move straight to the next step.</p>
            </div>

            <div class="sync-onboarding-primary-surface">
                <form id="website-form" class="sync-onboarding-section-stack sync-onboarding-form-cluster">
                    @csrf
                    <div class="sync-onboarding-surface-head">
                        <div>
                            <span class="sync-onboarding-surface-kicker">Primary path</span>
                            <h4 class="sync-onboarding-surface-title">Read my website</h4>
                        </div>
                        <p class="sync-onboarding-surface-note">Paste your website and we will prefill what we can so you spend less time typing later.</p>
                    </div>

                    <div class="field-single">
                        <label>
                            Business Website
                            <input id="website-url" type="url" name="url" placeholder="https://yourbusiness.com" value="{{ $state['business']['website_url'] ?? '' }}">
                        </label>
                    </div>
                    <div class="sync-onboarding-primary-actions">
                        <x-ui.button type="submit" id="read-website-btn">
                            Read My Website
                        </x-ui.button>
                    </div>
                </form>

                <div id="website-progress" class="sync-onboarding-inline-progress" style="display: none;">
                    <div class="sync-onboarding-inline-progress__head">
                        <svg id="progress-spinner" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" style="flex-shrink: 0; animation: spin 1s linear infinite;">
                        <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2" stroke-dasharray="40" stroke-dashoffset="15" opacity="0.25"/>
                        <path d="M10 2a8 8 0 0 1 8 8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        <span id="progress-message">Reading your website pages…</span>
                    </div>
                    <div id="progress-steps" class="sync-onboarding-inline-progress__steps">
                        <div class="progress-step" data-step="scraping">
                            <span class="step-icon">⏳</span>
                        <span>Reading your website pages</span>
                        </div>
                        <div class="progress-step" data-step="analysing" style="opacity: 0.4;">
                            <span class="step-icon">⏳</span>
                        <span>Analysing business information</span>
                        </div>
                        <div class="progress-step" data-step="writing" style="opacity: 0.4;">
                            <span class="step-icon">⏳</span>
                        <span>Filling in your business details</span>
                        </div>
                    </div>
                </div>

                <div class="sync-onboarding-secondary-path">
                    <p class="sync-onboarding-secondary-path__copy">Prefer to type the details yourself? You can skip the website scan and start filling in your business information now.</p>
                    <x-ui.button type="button" variant="secondary" id="manual-focus-button" icon="chevron-right" icon-position="after">
                        Continue Manually
                    </x-ui.button>
                </div>
            </div>

            <div class="note" style="display: none;" id="website-success"></div>
            <div class="note error" style="display: none;" id="website-error"></div>

            <div class="wizard-nav">
                <span class="spacer"></span>
            </div>
        </x-ui.panel>

        {{-- ═══════════════════ STEP 2 — Business Details ═══════════════════ --}}
        <x-ui.panel variant="subtle" class="wizard-panel sync-onboarding-step" data-wizard-step="2" id="wizard-step-2" style="display: none;">
            <div class="sync-onboarding-step__intro">
                <span class="eyebrow">Step 2</span>
                <h3 class="type-section-title">Confirm your business details</h3>
                <p>Check what we know so far and adjust anything that needs fixing. These details shape how your digital employee introduces your business and answers customers later.</p>
            </div>

            <form id="business-form" class="sync-onboarding-section-stack">
                @csrf
                <div class="sync-onboarding-field-group">
                    <div class="sync-onboarding-field-group__header">
                        <h4 class="sync-onboarding-field-group__title">Business identity</h4>
                        <p class="sync-onboarding-field-group__note">The essentials customers should recognise right away.</p>
                    </div>
                    <div class="field-grid">
                        <label>
                            Business Name
                            <input id="business-name-input" type="text" name="business_name" value="{{ $state['business']['business_name'] ?? $tenant->business_name }}">
                        </label>
                        <label>
                            Trading Name <span class="hint">(optional)</span>
                            <input id="trading-name-input" type="text" name="trading_name" value="{{ $state['business']['trading_name'] ?? '' }}">
                        </label>
                    </div>

                    <div class="field-grid">
                        <label>
                            Industry
                            <input id="industry-input" type="text" name="industry" value="{{ $state['business']['industry'] ?? $tenant->industry }}">
                        </label>
                        <label>
                            Tagline <span class="hint">(optional)</span>
                            <input id="tagline-input" type="text" name="tagline" value="{{ $state['business']['tagline'] ?? '' }}">
                        </label>
                    </div>

                    <div class="field-single">
                        <label>
                            What your business does
                            <textarea id="description-input" name="description" placeholder="Describe what you do, who you help, and the kinds of work you handle.">{{ $state['business']['description'] ?? '' }}</textarea>
                        </label>
                    </div>
                </div>

                <div class="sync-onboarding-field-group">
                    <div class="sync-onboarding-field-group__header">
                        <h4 class="sync-onboarding-field-group__title">Contact details</h4>
                        <p class="sync-onboarding-field-group__note">How customers and staff should recognise the right contact points.</p>
                    </div>
                    <div class="field-grid">
                        <label>
                            Contact Email
                            <input id="contact-email-input" type="email" name="contact_email" value="{{ $state['business']['contact_email'] ?? '' }}">
                        </label>
                        <label>
                            Contact Phone
                            <input id="contact-phone-input" type="text" name="contact_phone" value="{{ $state['business']['contact_phone'] ?? '' }}">
                        </label>
                    </div>

                    <div class="field-grid">
                        <label>
                            Business Website <span class="hint">(optional)</span>
                            <input id="website-url-input" type="url" name="website_url" value="{{ $state['business']['website_url'] ?? '' }}">
                        </label>
                        <label>
                            Owner Name <span class="hint">(optional)</span>
                            <input id="owner-name-input" type="text" name="owner_name" value="{{ $state['business']['owner_name'] ?? '' }}">
                        </label>
                    </div>
                </div>

                <div class="sync-onboarding-field-group">
                    <div class="sync-onboarding-field-group__header">
                        <h4 class="sync-onboarding-field-group__title">Location and services</h4>
                        <p class="sync-onboarding-field-group__note">Give the assistant enough context to explain where you work and what jobs you handle.</p>
                    </div>
                    <div class="field-grid">
                        <label>
                            Address <span class="hint">(optional)</span>
                            <input id="physical-address-input" type="text" name="physical_address" value="{{ $state['business']['physical_address'] ?? '' }}">
                        </label>
                        <label>
                            City <span class="hint">(optional)</span>
                            <input id="city-input" type="text" name="city" value="{{ $state['business']['city'] ?? '' }}">
                        </label>
                    </div>

                    <div class="field-single">
                        <label>
                            Services
                            <textarea id="services-input" name="services_text" placeholder="Add one service per line">{{ $state['business']['services'] !== [] ? implode("\n", $state['business']['services']) : '' }}</textarea>
                        </label>
                    </div>
                </div>

                <x-ui.button type="submit">
                    Save Business Details
                </x-ui.button>
                <div class="wizard-auto-note">We’ll move you straight to the next step after saving.</div>
            </form>
            <div class="note" style="margin-top: 18px; display: none;" id="business-success"></div>
            <div class="note error" style="margin-top: 18px; display: none;" id="business-error"></div>

            <div class="wizard-nav">
                <x-ui.button type="button" variant="secondary" data-wizard-prev icon="arrow-left">
                    Back
                </x-ui.button>
                <span class="spacer"></span>
            </div>
        </x-ui.panel>

        {{-- ═══════════════════ STEP 3 — Personality ═══════════════════ --}}
        <x-ui.panel variant="subtle" class="wizard-panel sync-onboarding-step" data-wizard-step="3" id="wizard-step-3" style="display: none;">
            <div class="sync-onboarding-step__intro">
                <span class="eyebrow">Step 3</span>
                <h3 class="type-section-title">Choose the communication style</h3>
                <p>Pick the tone that feels most like your business. You can change this later as you learn what feels right.</p>
            </div>

            <form id="personality-form" class="sync-onboarding-section-stack">
                @csrf
                <div class="sync-onboarding-choice-grid">
                    @php
                        $toneOptions = [
                            'friendly' => ['label' => 'Friendly & Warm', 'description' => 'Approachable, warm, and easy to talk to.'],
                            'professional' => ['label' => 'Professional', 'description' => 'Clear, polished, and business-ready.'],
                            'formal' => ['label' => 'Formal', 'description' => 'Precise, reserved, and traditional.'],
                            'casual' => ['label' => 'Casual & Fun', 'description' => 'Relaxed, upbeat, and conversational.'],
                        ];
                    @endphp
                    @foreach ($toneOptions as $value => $toneOption)
                        <label class="sync-onboarding-choice-card">
                            <input class="sync-onboarding-choice-card__input" type="radio" name="tone" value="{{ $value }}" @checked(($state['tone'] ?? $state['business']['tone_hint'] ?? null) === $value)>
                            <span class="sync-onboarding-choice-card__panel">
                                <span class="sync-onboarding-choice-card__head">
                                    <span class="sync-onboarding-choice-card__title">{{ $toneOption['label'] }}</span>
                                    <span class="sync-onboarding-choice-card__badge">Tone</span>
                                </span>
                                <span class="sync-onboarding-choice-card__description">{{ $toneOption['description'] }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
                <x-ui.button type="submit">
                    Save Communication Style
                </x-ui.button>
                <div class="wizard-auto-note">We’ll move you straight to the next step after saving.</div>
            </form>
            <div class="note" style="margin-top: 18px; display: none;" id="personality-success"></div>
            <div class="note error" style="margin-top: 18px; display: none;" id="personality-error"></div>

            <div class="wizard-nav">
                <x-ui.button type="button" variant="secondary" data-wizard-prev icon="arrow-left">
                    Back
                </x-ui.button>
                <span class="spacer"></span>
            </div>
        </x-ui.panel>

        {{-- ═══════════════════ STEP 4 — Modules ═══════════════════ --}}
        <x-ui.panel variant="subtle" class="wizard-panel sync-onboarding-step" data-wizard-step="4" id="wizard-step-4" style="display: none;">
            <div class="sync-onboarding-step__intro">
                <span class="eyebrow">Step 4</span>
                <h3 class="type-section-title">Choose your modules</h3>
                <p>Core modules are already included. Add any featured modules you want your digital employee to support from day one.</p>
            </div>

            <form id="capabilities-form" class="sync-onboarding-section-stack">
                @csrf
                <div class="sync-onboarding-guidance">
                    <h4 class="sync-onboarding-guidance__title">Included Core Modules</h4>
                    <p class="sync-onboarding-guidance__body">These are already part of your setup, so you can focus on choosing any optional extras below.</p>
                    <div class="sync-onboarding-choice-grid" id="core-modules-list">
                        @foreach (($state['modules']['core'] ?? []) as $module)
                            <div class="sync-onboarding-choice-card__panel">
                                <span class="sync-onboarding-choice-card__head">
                                    <span class="sync-onboarding-choice-card__title">{{ $module['label'] }}</span>
                                    <span class="sync-onboarding-choice-card__badge">Included</span>
                                </span>
                                @if (! empty($module['description']))
                                    <span class="sync-onboarding-choice-card__description">{{ $module['description'] }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="sync-onboarding-field-group">
                    <div class="sync-onboarding-field-group__header">
                        <h4 class="sync-onboarding-field-group__title">Featured modules</h4>
                        <p class="sync-onboarding-field-group__note">Choose any extras you want enabled from the start.</p>
                    </div>
                    <div class="sync-onboarding-choice-grid" id="featured-modules-list">
                        @forelse (($state['modules']['featured'] ?? []) as $module)
                            <label class="sync-onboarding-choice-card">
                                <input class="sync-onboarding-choice-card__input" type="checkbox" name="featured_skill_keys[]" value="{{ $module['skill_key'] }}" @checked(in_array($module['skill_key'], $state['modules']['selected_featured_skill_keys'] ?? [], true))>
                                <span class="sync-onboarding-choice-card__panel">
                                    <span class="sync-onboarding-choice-card__head">
                                        <span class="sync-onboarding-choice-card__title">{{ $module['label'] }}</span>
                                        <span class="sync-onboarding-choice-card__badge">Optional</span>
                                    </span>
                                    @if (! empty($module['description']))
                                        <span class="sync-onboarding-choice-card__description">{{ $module['description'] }}</span>
                                    @endif
                                </span>
                            </label>
                        @empty
                            <div class="sync-onboarding-guidance">
                                <p class="sync-onboarding-guidance__body">No extra featured modules are available yet. Your included core modules are ready to go.</p>
                            </div>
                        @endforelse
                    </div>
                </div>

                <x-ui.button type="submit">
                    Save &amp; Prepare Files
                </x-ui.button>
                <div class="wizard-auto-note">We’ll move you straight to the next step after saving.</div>
            </form>
            <div class="note" style="margin-top: 18px; display: none;" id="capabilities-success"></div>
            <div class="note error" style="margin-top: 18px; display: none;" id="capabilities-error"></div>
            <div class="sync-onboarding-summary-note" id="files-note">
                @if (! empty($state['files']['generated_at']))
                    Your internal setup files were generated on {{ $state['files']['generated_at'] }}.
                @else
                    Once you save your modules, we will prepare the internal setup files behind the scenes.
                @endif
            </div>

            <div class="wizard-nav">
                <x-ui.button type="button" variant="secondary" data-wizard-prev icon="arrow-left">
                    Back
                </x-ui.button>
                <span class="spacer"></span>
            </div>
        </x-ui.panel>

        {{-- ═══════════════════ STEP 5 — Channel ═══════════════════ --}}
        <x-ui.panel variant="subtle" class="wizard-panel sync-onboarding-step" data-wizard-step="5" id="wizard-step-5" style="display: none;">
            <div class="sync-onboarding-step__intro">
                <span class="eyebrow">Step 5</span>
                <h3 class="type-section-title">Connect your messaging channel</h3>
                <p>Telegram is the live channel today. Connect it here so your digital employee can reach you with updates and replies.</p>
            </div>

            <div id="channel-connected-panel" style="display: {{ ($state['channel_setup']['status'] ?? '') === 'connected' ? 'block' : 'none' }};">
                <div class="sync-onboarding-channel-status">
                    <div class="sync-onboarding-channel-status__copy">
                        <span id="connected-channel-icon">
                            @if(($state['channel'] ?? '') === 'telegram')
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-.98-.19-1.46-.35-.59-.2-1.06-.3-1.02-.64.02-.18.27-.36.74-.55 2.92-1.27 4.86-2.11 5.83-2.51 2.78-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z" fill="#229ED9"/></svg>
                            @else
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z" fill="#25D366"/><path d="M12.004 2C6.489 2 2 6.489 2 12.004c0 1.762.46 3.476 1.333 4.99L2 22l5.233-1.237A9.956 9.956 0 0012.004 22C17.52 22 22 17.52 22 12.004 22 6.489 17.52 2 12.004 2zm0 18.15A8.14 8.14 0 017.55 18.8l-.35-.21-3.1.73.82-3-.23-.36a8.108 8.108 0 01-1.24-4.35C3.45 7.29 7.29 3.45 12 3.45c2.27 0 4.4.88 6.01 2.49a8.453 8.453 0 012.49 6.01c.01 4.72-3.84 8.56-8.5 8.56v-.01z" fill="#25D366"/></svg>
                            @endif
                        </span>
                        <div class="sync-onboarding-channel-status__meta">
                            <p class="sync-onboarding-channel-status__title">
                                <span id="connected-channel-name">{{ ucfirst($state['channel'] ?? '') }}</span>
                                <span class="sync-onboarding-chip sync-onboarding-chip--success">Connected</span>
                            </p>
                            <p class="sync-onboarding-channel-status__note">Your digital employee is connected and ready to respond through this channel.</p>
                        </div>
                    </div>
                    <x-ui.button type="button" id="disconnect-channel-btn" variant="secondary" icon="trash-2">
                        Disconnect
                    </x-ui.button>
                </div>
            </div>

            <form id="channel-form" class="sync-onboarding-section-stack" style="{{ ($state['channel_setup']['status'] ?? '') === 'connected' ? 'display: none;' : '' }}">
                @csrf
                <label class="sync-onboarding-choice-card sync-onboarding-choice-card--primary">
                    <input class="sync-onboarding-choice-card__input" type="radio" name="channel" value="telegram" @checked(($state['channel'] ?? 'telegram') === 'telegram')>
                    <span class="sync-onboarding-choice-card__panel">
                        <span class="sync-onboarding-choice-card__head">
                            <span class="sync-onboarding-choice-card__title">Telegram</span>
                            <span class="sync-onboarding-choice-card__badge">Available now</span>
                        </span>
                        <span class="sync-onboarding-choice-card__description">Use Telegram to chat with your digital employee directly and receive live status updates.</span>
                    </span>
                </label>

                <div id="telegram-fields" style="display: none;">
                    <div class="sync-onboarding-primary-surface sync-onboarding-primary-surface--compact">
                        <div class="sync-onboarding-surface-head">
                            <div>
                                <span class="sync-onboarding-surface-kicker">Primary path</span>
                                <h4 class="sync-onboarding-surface-title">Connect Telegram</h4>
                            </div>
                            <p class="sync-onboarding-surface-note">You only need a BotFather token. Paste it below and we will save the connection for you.</p>
                        </div>

                        <div class="field-single">
                            <label>
                                Bot Token
                                <textarea name="telegram_bot_token" placeholder="Paste the bot token from @BotFather"></textarea>
                            </label>
                        </div>

                        <div class="sync-onboarding-primary-actions">
                            <x-ui.button type="submit">
                                Connect Channel
                            </x-ui.button>
                        </div>

                        <details class="sync-onboarding-disclosure">
                            <summary>How to get your Telegram bot token</summary>
                            <ol class="sync-onboarding-guidance__list">
                                <li>Open Telegram and search for <strong>@BotFather</strong>.</li>
                                <li>Send <strong>/newbot</strong> and follow the prompts to name your bot.</li>
                                <li>Copy the token BotFather sends back.</li>
                                <li>Paste it here and connect the channel.</li>
                            </ol>
                            <p class="sync-onboarding-guidance__footnote">After connecting, open a chat with your bot in Telegram and send your first message there.</p>
                        </details>
                    </div>
                </div>

                <div class="sync-onboarding-secondary-note" id="whatsapp-coming-soon">
                    <p class="sync-onboarding-secondary-note__title">WhatsApp is coming later.</p>
                    <p class="sync-onboarding-secondary-note__body">Telegram is the live path today, and more customer channels will follow once they are ready.</p>
                </div>
                <div class="wizard-auto-note">We’ll move you straight to the next step after saving.</div>
            </form>
            <div class="note" style="margin-top: 18px; display: none;" id="channel-success"></div>
            <div class="note error" style="margin-top: 18px; display: none;" id="channel-error"></div>

            <div class="note" style="margin-top: 18px; display: none;" id="channel-status-note"></div>

            <div id="telegram-setup-summary" style="margin-top: 18px; display: none;">
                <div class="meta">
                    <div class="meta-item">
                        <small>Bot Token</small>
                        <span id="telegram-bot-token-status">{{ ! empty($state['channel_setup']['telegram']['bot_token_saved']) ? 'Saved' : 'Not saved yet' }}</span>
                    </div>
                </div>
                <div class="note" style="margin-top: 18px;">
                    Your digital employee is connected through this bot and will poll Telegram directly once the workspace is live.
                </div>
            </div>

            <div class="wizard-nav">
                <x-ui.button type="button" variant="secondary" data-wizard-prev icon="arrow-left">
                    Back
                </x-ui.button>
                <span class="spacer"></span>
                <x-ui.button type="button" variant="secondary" data-wizard-next id="channel-step-next" icon="chevron-right" icon-position="after">
                    Continue To Google Workspace
                </x-ui.button>
            </div>
        </x-ui.panel>

        {{-- ═══════════════════ STEP 6 — Google Workspace ═══════════════════ --}}
        <x-ui.panel variant="subtle" class="wizard-panel sync-onboarding-step" data-wizard-step="6" id="wizard-step-6" style="display: none;">
            <div class="sync-onboarding-step__intro">
                <span class="eyebrow">Step 6</span>
                <h3 class="type-section-title">Connect Google Workspace</h3>
                <p>Connect Google Workspace to finish preparing Gmail, Calendar, Drive, Contacts, Sheets, and Docs for your live workspace.</p>
            </div>

            <div class="sync-onboarding-section-stack">
                <div class="sync-onboarding-field-group">
                    <div class="sync-onboarding-field-group__header">
                        <h4 class="sync-onboarding-field-group__title">Current state</h4>
                        <p class="sync-onboarding-field-group__note">These are the three signals that matter most before you go live.</p>
                    </div>

                    <div class="sync-onboarding-meta-grid">
                        <div class="meta-item">
                            <small>Connection</small>
                            <span id="google-workspace-status">
                                {{ $state['google_workspace']['health_label'] ?? ucfirst($state['google_workspace']['status'] ?? 'pending') }}
                            </span>
                        </div>
                        <div class="meta-item">
                            <small>Connected Account</small>
                            <span id="google-workspace-email">
                                {{ $state['google_workspace']['connected_email'] ?? 'Not connected yet' }}
                            </span>
                        </div>
                        <div class="meta-item">
                            <small>Health</small>
                            <span id="google-workspace-runtime-sync">
                                {{ $state['google_workspace']['health_label'] ?? ($state['google_workspace']['runtime_sync_label'] ?? ucfirst($state['google_workspace']['runtime_sync_status'] ?? 'pending')) }}
                            </span>
                        </div>
                    </div>
                </div>

                <div class="sync-onboarding-field-group">
                    <div class="sync-onboarding-field-group__header">
                        <h4 class="sync-onboarding-field-group__title">What this means</h4>
                    </div>
                    <div class="note" id="google-workspace-note">
                        {{ $state['google_workspace']['health_note'] ?? 'Connect Google Workspace to finish preparing your customer-ready workspace.' }}
                    </div>
                    <div class="note error" style="display: {{ filled($state['google_workspace']['last_error'] ?? null) ? 'block' : 'none' }};" id="google-workspace-error">
                        {{ $state['google_workspace']['last_error'] ?? '' }}
                    </div>
                    <p class="sync-onboarding-summary-note" id="google-workspace-scopes-note">
                        Requested access includes Gmail, Calendar, Drive file access, Contacts read-only, Sheets, and Docs.
                    </p>
                </div>
                <div class="sync-onboarding-field-group">
                    <div class="sync-onboarding-field-group__header">
                        <h4 class="sync-onboarding-field-group__title">Next action</h4>
                    </div>
                    <div class="sync-onboarding-action-row" id="google-workspace-actions">
                        <div id="google-workspace-connect-wrapper" style="display: {{ (($state['google_workspace']['connected'] ?? false) && !($state['google_workspace']['requires_reconnect'] ?? false)) ? 'none' : 'block' }};">
                            <x-ui.button
                                :href="($state['google_workspace']['can_connect'] ?? false) ? route('onboarding.google.connect') : '#'"
                                id="google-workspace-connect-link"
                                icon="external-link"
                                icon-position="after"
                                style="{{ ($state['google_workspace']['can_connect'] ?? false) ? '' : 'pointer-events:none; opacity:0.45;' }}"
                            >
                                {{ ($state['google_workspace']['can_reconnect'] ?? false) ? 'Reconnect Google Workspace' : 'Connect Google Workspace' }}
                            </x-ui.button>
                        </div>

                        <form method="POST" action="{{ route('onboarding.google.skip') }}" id="google-workspace-skip-form" style="display: none;">
                            @csrf
                            <x-ui.button type="submit" variant="secondary">Skip For Now</x-ui.button>
                        </form>

                        <form method="POST" action="{{ route('onboarding.google.disconnect') }}" id="google-workspace-disconnect-form" style="display: {{ ((($state['google_workspace']['status'] ?? 'pending') === 'connected') && !($state['google_workspace']['requires_reconnect'] ?? false)) ? 'block' : 'none' }};">
                            @csrf
                            <x-ui.button type="submit" variant="secondary" icon="trash-2">Disconnect</x-ui.button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="wizard-nav">
                <x-ui.button type="button" variant="secondary" data-wizard-prev icon="arrow-left">
                    Back
                </x-ui.button>
                <x-ui.button type="button" data-wizard-next icon="chevron-right" icon-position="after">
                    Continue To Go Live
                </x-ui.button>
            </div>
        </x-ui.panel>

        {{-- ═══════════════════ STEP 7 — Go Live ═══════════════════ --}}
        <x-ui.panel variant="subtle" class="wizard-panel sync-onboarding-step" data-wizard-step="7" id="wizard-step-7" style="display: none;">
            <div class="sync-onboarding-step__intro">
                <span class="eyebrow">Step 7</span>
                <h3 class="type-section-title">Bring it live</h3>
                <p>Once the essentials are ready, this is where you activate or resync your digital employee.</p>
            </div>

            <div class="sync-onboarding-section-stack">
                <div class="sync-onboarding-field-group">
                    <div class="sync-onboarding-field-group__header">
                        <h4 class="sync-onboarding-field-group__title">Current state</h4>
                        <p class="sync-onboarding-field-group__note">These signals tell you whether you can go live now or what still needs attention.</p>
                    </div>
                    <div class="sync-onboarding-meta-grid">
                        <div class="meta-item">
                            <small>Workspace Status</small>
                            <span id="go-live-workspace-status">{{ ($state['workspace']['ready'] ?? false) ? 'Ready' : 'Setting up…' }}</span>
                        </div>
                        <div class="meta-item">
                            <small>Selected Channel</small>
                            <span id="go-live-channel-status">
                                @if (($state['channel'] ?? null) === 'telegram')
                                    Telegram
                                @else
                                    Not connected yet
                                @endif
                            </span>
                        </div>
                        <div class="meta-item">
                            <small>Agent Status</small>
                            <span id="go-live-agent-status">{{ ucfirst($state['agent_status'] ?? 'offline') }}</span>
                        </div>
                    </div>
                </div>

                <div class="sync-onboarding-field-group">
                    <div class="sync-onboarding-field-group__header">
                        <h4 class="sync-onboarding-field-group__title">What happens next</h4>
                    </div>
                    <div class="note" id="go-live-note">
                        @if (($state['agent_status'] ?? null) === 'live')
                            Your digital employee is already active.
                        @elseif (($state['workspace']['go_live_ready'] ?? false) === true)
                            Everything is ready. Click below to activate your digital employee.
                        @else
                            {{ $state['workspace']['blocking_message'] ?? 'Finish the remaining setup steps before going live.' }}
                        @endif
                    </div>

                    @if ($tenant->isTrialExpired())
                        <div class="note error">
                            Your trial has ended — activating your digital employee is not available.
                            <a href="mailto:hello@sync360.co.nz">Contact us</a> to continue.
                        </div>
                        <x-ui.button type="button" disabled>Go Live</x-ui.button>
                    @else
                        <form id="go-live-form">
                            @csrf
                            <x-ui.button type="submit" :disabled="!(($state['workspace']['go_live_ready'] ?? false) || (($state['agent_status'] ?? null) === 'live'))">
                                {{ ($state['agent_status'] ?? null) === 'live' ? 'Resync Assistant' : 'Go Live' }}
                            </x-ui.button>
                        </form>
                    @endif
                </div>
            </div>
            <div class="note" style="display: none;" id="go-live-success"></div>
            <div class="note error" style="display: none;" id="go-live-error"></div>

            <div class="wizard-nav">
                <x-ui.button type="button" variant="secondary" data-wizard-prev icon="arrow-left">
                    Back
                </x-ui.button>
                <span class="spacer"></span>
            </div>
        </x-ui.panel>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════════
         JavaScript — ALL existing functionality preserved, wizard nav added
         ═══════════════════════════════════════════════════════════════════ --}}
    <script>
        /* ── endpoints (unchanged) ── */
        const onboardingStateEndpoint = @json(route('onboarding.state'));
        const onboardingExtractEndpoint = @json(route('onboarding.extract-business'));
        const onboardingBusinessInfoEndpoint = @json(route('onboarding.business-info'));
        const onboardingPersonalityEndpoint = @json(route('onboarding.personality'));
        const onboardingCapabilitiesEndpoint = @json(route('onboarding.modules'));
        const onboardingChannelEndpoint = @json(route('onboarding.channel'));
        const onboardingChannelDisconnectEndpoint = @json(route('onboarding.channel.disconnect'));
        const onboardingGoLiveEndpoint = @json(route('onboarding.go-live'));
        const csrfToken = @json(csrf_token());

        /* ── DOM refs (unchanged IDs) ── */
        const websiteForm = document.getElementById('website-form');
        const businessForm = document.getElementById('business-form');
        const websiteSuccess = document.getElementById('website-success');
        const websiteError = document.getElementById('website-error');
        const businessSuccess = document.getElementById('business-success');
        const businessError = document.getElementById('business-error');
        const personalityForm = document.getElementById('personality-form');
        const personalitySuccess = document.getElementById('personality-success');
        const personalityError = document.getElementById('personality-error');
        const capabilitiesForm = document.getElementById('capabilities-form');
        const capabilitiesSuccess = document.getElementById('capabilities-success');
        const capabilitiesError = document.getElementById('capabilities-error');
        const channelForm = document.getElementById('channel-form');
        const channelConnectedPanel = document.getElementById('channel-connected-panel');
        const connectedChannelName = document.getElementById('connected-channel-name');
        const connectedChannelIcon = document.getElementById('connected-channel-icon');
        const disconnectChannelBtn = document.getElementById('disconnect-channel-btn');
        const channelSuccess = document.getElementById('channel-success');
        const channelError = document.getElementById('channel-error');
        const telegramFields = document.getElementById('telegram-fields');
        const telegramSetupSummary = document.getElementById('telegram-setup-summary');
        const channelStatusNote = document.getElementById('channel-status-note');
        const telegramBotTokenStatus = document.getElementById('telegram-bot-token-status');
        const goLiveForm = document.getElementById('go-live-form');
        const goLiveSubmitButton = goLiveForm ? goLiveForm.querySelector('button[type="submit"]') : null;
        const goLiveSuccess = document.getElementById('go-live-success');
        const goLiveError = document.getElementById('go-live-error');
        const googleWorkspaceStatus = document.getElementById('google-workspace-status');
        const googleWorkspaceEmail = document.getElementById('google-workspace-email');
        const googleWorkspaceRuntimeSync = document.getElementById('google-workspace-runtime-sync');
        const googleWorkspaceNote = document.getElementById('google-workspace-note');
        const googleWorkspaceError = document.getElementById('google-workspace-error');
        const googleWorkspaceConnectLink = document.getElementById('google-workspace-connect-link');
        const googleWorkspaceConnectWrapper = document.getElementById('google-workspace-connect-wrapper');
        const googleWorkspaceSkipForm = document.getElementById('google-workspace-skip-form');
        const googleWorkspaceDisconnectForm = document.getElementById('google-workspace-disconnect-form');
        const goLiveWorkspaceStatus = document.getElementById('go-live-workspace-status');
        const goLiveChannelStatus = document.getElementById('go-live-channel-status');
        const goLiveAgentStatus = document.getElementById('go-live-agent-status');
        const goLiveNote = document.getElementById('go-live-note');
        const manualFocusButton = document.getElementById('manual-focus-button');
        const filesNote = document.getElementById('files-note');
        const wizardStepCounter = document.getElementById('wizard-step-counter');
        const wizardStepName = document.getElementById('wizard-step-name');
        const wizardProgressPercent = document.getElementById('wizard-progress-percent');
        const wizardProgressFill = document.getElementById('wizard-progress-fill');
        const wizardProgressNote = document.getElementById('wizard-progress-note');
        const wizardOperationNote = document.getElementById('wizard-operation-note');
        const workspaceSetupStrip = document.getElementById('workspace-setup-strip');
        const workspaceSetupBadge = document.getElementById('workspace-setup-badge');
        const workspaceSetupNote = document.getElementById('workspace-setup-note');

        const businessNameInput = document.getElementById('business-name-input');
        const tradingNameInput = document.getElementById('trading-name-input');
        const industryInput = document.getElementById('industry-input');
        const taglineInput = document.getElementById('tagline-input');
        const descriptionInput = document.getElementById('description-input');
        const contactEmailInput = document.getElementById('contact-email-input');
        const contactPhoneInput = document.getElementById('contact-phone-input');
        const websiteUrlInput = document.getElementById('website-url-input');
        const ownerNameInput = document.getElementById('owner-name-input');
        const physicalAddressInput = document.getElementById('physical-address-input');
        const cityInput = document.getElementById('city-input');
        const servicesInput = document.getElementById('services-input');
        const websiteUrlFormInput = document.getElementById('website-url');
        /* ══════════════════════════════════════════════════════════════════
           WIZARD NAVIGATION
           ══════════════════════════════════════════════════════════════════ */
        const wizardPanels = document.querySelectorAll('.wizard-panel');
        const wizardBarItems = document.querySelectorAll('#wizard-steps-bar li');
        let currentStep = {{ request()->integer('step', (int) ($state['resume_from_step'] ?? 1)) }};
        let latestOnboardingState = @json($state);
        const draftState = {
            website: false,
            business: false,
            personality: false,
            capabilities: false,
            channel: false,
        };
        const wizardOperation = {
            active: false,
            type: 'idle',
            label: '',
        };

        function showWizardStep(step, options = {}) {
            if (wizardOperation.active && options.force !== true) {
                return false;
            }

            currentStep = step;
            wizardPanels.forEach(p => {
                const isActive = parseInt(p.dataset.wizardStep) === step;
                p.style.display = isActive ? 'block' : 'none';
            });
            wizardBarItems.forEach(li => {
                li.classList.remove('active');
                if (parseInt(li.dataset.step) === step) li.classList.add('active');
            });
            updateWizardStatus(latestOnboardingState || {});
            window.scrollTo({ top: 0, behavior: 'smooth' });
            return true;
        }

        /* next / prev buttons */
        document.querySelectorAll('[data-wizard-next]').forEach(btn => {
            btn.addEventListener('click', () => {
                if (currentStep < 7) showWizardStep(currentStep + 1);
            });
        });
        document.querySelectorAll('[data-wizard-prev]').forEach(btn => {
            btn.addEventListener('click', () => {
                if (currentStep > 1) showWizardStep(currentStep - 1);
            });
        });

        /* clickable step bar */
        wizardBarItems.forEach(li => {
            li.addEventListener('click', () => {
                showWizardStep(parseInt(li.dataset.step));
            });
        });

        /* ══════════════════════════════════════════════════════════════════
           EXISTING UTILITY FUNCTIONS (unchanged)
           ══════════════════════════════════════════════════════════════════ */
        function selectedChannelValue() {
            return channelForm.querySelector('input[name="channel"]:checked')?.value || '';
        }

        function updateChannelFields() {
            const channel = selectedChannelValue();
            telegramFields.style.display = channel === 'telegram' ? 'block' : 'none';
        }

        function resetChannelDraft() {
            channelForm.querySelectorAll('input[name="channel"]').forEach((input) => {
                input.checked = false;
            });

            const telegramBotTokenInput = channelForm.querySelector('textarea[name="telegram_bot_token"]');
            if (telegramBotTokenInput) {
                telegramBotTokenInput.value = '';
            }
        }

        function updateChannelStatusNote(state) {
            const selectedChannel = selectedChannelValue();
            const channelStatus = state?.channel_setup?.status || 'pending';
            const isConnected = channelStatus === 'connected';

            if (isConnected) {
                channelStatusNote.textContent = '';
                channelStatusNote.style.display = 'none';

                return;
            }

            if (channelStatus === 'saved') {
                channelStatusNote.textContent = 'Telegram is saved. We will apply it automatically as soon as the workspace runtime is ready.';
                channelStatusNote.style.display = 'block';

                return;
            }

            if (selectedChannel === 'telegram') {
                channelStatusNote.textContent = 'Paste your Telegram bot token above to connect your digital employee.';
                channelStatusNote.style.display = 'block';

                return;
            }

            channelStatusNote.textContent = '';
            channelStatusNote.style.display = 'none';
        }

        function updateChannelSummaries(state) {
            const channel = state?.channel || '';
            const setup = state?.channel_setup || {};
            const tgHasSaved = !!setup.telegram?.bot_token_saved;
            telegramSetupSummary.style.display = (channel === 'telegram' && tgHasSaved) ? 'block' : 'none';
        }

        function markDraft(stepKey, isDirty = true) {
            draftState[stepKey] = isDirty;
        }

        function advanceAfterSave(nextStep) {
            showWizardStep(nextStep, { force: true });
        }

        function setWizardControlsLocked(locked) {
            document.querySelectorAll('[data-wizard-next], [data-wizard-prev], button[type="submit"], button[type="button"]').forEach((button) => {
                if (locked) {
                    button.dataset.lockedBefore = button.disabled ? 'true' : 'false';
                }

                if (button.id === 'manual-focus-button') {
                    button.disabled = locked || button.dataset.lockedBefore === 'true';
                    if (!locked) {
                        delete button.dataset.lockedBefore;
                    }
                    return;
                }

                button.disabled = locked || button.dataset.lockedBefore === 'true';

                if (!locked) {
                    delete button.dataset.lockedBefore;
                }
            });

            wizardBarItems.forEach((li) => {
                li.setAttribute('aria-disabled', locked ? 'true' : 'false');
            });

            document.getElementById('wizard-steps-bar')?.classList.toggle('is-locked', locked);

            [googleWorkspaceSkipForm, googleWorkspaceDisconnectForm].forEach((form) => {
                form?.querySelectorAll('button, input').forEach((control) => {
                    if (locked) {
                        control.dataset.lockedBefore = control.disabled ? 'true' : 'false';
                    }
                    control.disabled = locked || control.dataset.lockedBefore === 'true';
                    if (!locked) {
                        delete control.dataset.lockedBefore;
                    }
                });
            });

            if (googleWorkspaceConnectLink) {
                googleWorkspaceConnectLink.style.pointerEvents = locked ? 'none' : '';
                googleWorkspaceConnectLink.style.opacity = locked ? '0.45' : '';
                googleWorkspaceConnectLink.setAttribute('aria-disabled', locked ? 'true' : 'false');
            }
        }

        function startWizardOperation(type, label, button = null) {
            wizardOperation.active = true;
            wizardOperation.type = type;
            wizardOperation.label = label;

            if (wizardOperationNote) {
                wizardOperationNote.textContent = label;
                wizardOperationNote.style.display = 'block';
            }

            setWizardControlsLocked(true);

            if (button) {
                button.disabled = true;
                button.textContent = label;
            }
        }

        function finishWizardOperation() {
            wizardOperation.active = false;
            wizardOperation.type = 'idle';
            wizardOperation.label = '';

            if (wizardOperationNote) {
                wizardOperationNote.textContent = '';
                wizardOperationNote.style.display = 'none';
            }

            setWizardControlsLocked(false);
            applyState(latestOnboardingState || {});
        }

        function withButtonBusy(button, busyLabel, operationType = 'working') {
            const originalLabel = button?.dataset.originalLabel || button?.textContent || '';

            if (button) {
                button.dataset.originalLabel = originalLabel;
            }

            startWizardOperation(operationType, busyLabel, button);

            return () => {
                finishWizardOperation();

                if (!button) {
                    return;
                }

                button.textContent = button.dataset.originalLabel || originalLabel;
            };
        }

        function updateWizardStatus(state) {
            const steps = state?.steps || {};
            const totalSteps = Object.keys(steps).length || 7;
            const currentStepMeta = steps[String(currentStep)] || steps[currentStep] || {};
            const progressPercent = Math.round((currentStep / totalSteps) * 100);
            const completedCount = Object.values(steps).filter((step) => step?.status === 'complete').length;

            wizardStepCounter.textContent = `Step ${currentStep} of ${totalSteps}`;
            wizardStepName.textContent = currentStepMeta.label || 'Setup';
            wizardProgressPercent.textContent = `${progressPercent}%`;
            wizardProgressFill.style.width = `${progressPercent}%`;
            wizardProgressNote.textContent = completedCount > 0
                ? `${completedCount} of ${totalSteps} steps completed. We’ll keep the technical setup moving while you finish the remaining details.`
                : `Start with the basics and we’ll keep the technical setup moving in the background.`;

            if (state?.agent_status === 'live') {
                workspaceSetupStrip?.classList.add('is-hidden');
                return;
            }

            if (state?.provisioning_status === 'ready') {
                workspaceSetupStrip?.classList.add('is-hidden');
                return;
            }

            if (state?.provisioning_status === 'failed') {
                workspaceSetupStrip?.classList.remove('is-hidden');
                workspaceSetupBadge.textContent = 'Workspace setup needs attention';
                workspaceSetupNote.textContent = 'The background workspace setup hit an issue. You can still review your details here while support checks the runtime setup.';
                return;
            }

            workspaceSetupStrip?.classList.remove('is-hidden');
            workspaceSetupBadge.textContent = 'Workspace setup in progress';
            workspaceSetupNote.textContent = 'Your workspace is being prepared in the background. Moving around this wizard will not restart that setup.';
        }

        function showMessage(element, message) {
            element.textContent = message;
            element.style.display = 'block';
        }

        function hideMessage(element) {
            element.textContent = '';
            element.style.display = 'none';
        }

        function normalizeServices(text) {
            return text
                .split(/\r?\n|,/)
                .map((value) => value.trim())
                .filter((value) => value.length > 0);
        }

        function fillBusinessForm(state) {
            if (!draftState.business) {
                businessNameInput.value = state.business?.business_name || state.tenant?.business_name || '';
                tradingNameInput.value = state.business?.trading_name || '';
                industryInput.value = state.business?.industry || state.tenant?.industry || '';
                taglineInput.value = state.business?.tagline || '';
                descriptionInput.value = state.business?.description || '';
                contactEmailInput.value = state.business?.contact_email || '';
                contactPhoneInput.value = state.business?.contact_phone || '';
                websiteUrlInput.value = state.business?.website_url || '';
                ownerNameInput.value = state.business?.owner_name || '';
                physicalAddressInput.value = state.business?.physical_address || '';
                cityInput.value = state.business?.city || '';
                servicesInput.value = Array.isArray(state.business?.services) ? state.business.services.join('\n') : '';
                if (!draftState.website) {
                    websiteUrlFormInput.value = state.business?.website_url || websiteUrlFormInput.value;
                }
            }

            if (!draftState.personality) {
                const toneInput = personalityForm.querySelector(`input[name="tone"][value="${state.tone || state.business?.tone_hint || ''}"]`);
                if (toneInput) {
                    toneInput.checked = true;
                } else {
                    personalityForm.querySelectorAll('input[name="tone"]').forEach((input) => {
                        input.checked = false;
                    });
                }
            }

            if (!draftState.capabilities) {
                const selectedModules = Array.isArray(state.modules?.selected_featured_skill_keys) ? state.modules.selected_featured_skill_keys : [];
                capabilitiesForm.querySelectorAll('input[name="featured_skill_keys[]"]').forEach((input) => {
                    input.checked = selectedModules.includes(input.value);
                });
            }

            /* Only overwrite channel selection from server if a channel has
               actually been saved. Otherwise the 5-second poll must preserve
               whatever the user has locally selected / typed. */
            const serverChannel = state.channel || '';
            if (serverChannel === 'telegram' && !draftState.channel) {
                channelForm.querySelectorAll('input[name="channel"]').forEach((input) => {
                    input.checked = input.value === serverChannel;
                });
            }

            telegramBotTokenStatus.textContent = state.channel_setup?.telegram?.bot_token_saved ? 'Saved' : 'Not saved yet';
            if (state.channel_setup?.telegram?.bot_token_saved && !state.channel_setup?.telegram?.runtime_configured) {
                telegramBotTokenStatus.textContent = 'Saved, waiting for workspace';
            }

            updateChannelFields();
            updateChannelStatusNote(state);
        }

        function formatStatus(value) {
            if (!value) return 'Pending';
            return value
                .replace(/_/g, ' ')
                .replace(/\b\w/g, (char) => char.toUpperCase());
        }

        function applyState(state) {
            latestOnboardingState = state;

            /* update step bar */
            Object.entries(state.steps ?? {}).forEach(([stepNumber, step]) => {
                const li = document.querySelector(`#wizard-steps-bar li[data-step="${stepNumber}"]`);
                if (!li) return;
                const complete = step.status === 'complete';
                li.classList.toggle('done', complete);
            });

            /* update go-live meta */
            filesNote.textContent = state.files?.generated_at
                ? `Your internal setup files were generated on ${state.files.generated_at}.`
                : `Once you save your modules, we'll prepare the internal setup files behind the scenes.`;
            goLiveWorkspaceStatus.textContent = state.workspace?.ready ? 'Ready' : 'Setting up…';
            goLiveChannelStatus.textContent = state.channel === 'telegram'
                ? (state.channel_setup?.status === 'connected' ? 'Telegram' : 'Telegram saved')
                : 'Not connected yet';
            googleWorkspaceStatus.textContent = state.google_workspace?.health_label || formatStatus(state.google_workspace?.status || 'pending');
            googleWorkspaceEmail.textContent = state.google_workspace?.connected_email || 'Not connected yet';
            googleWorkspaceRuntimeSync.textContent = state.google_workspace?.health_label || state.google_workspace?.runtime_sync_label || formatStatus(state.google_workspace?.runtime_sync_status || 'pending');
            googleWorkspaceConnectLink.textContent = state.google_workspace?.can_reconnect
                ? 'Reconnect Google Workspace'
                : 'Connect Google Workspace';
            const googleStatus = state.google_workspace?.status || 'pending';
            const googleIsConnected = googleStatus === 'connected';
            const googleRequiresReconnect = !!state.google_workspace?.requires_reconnect;
            const googleIsDisconnected = googleStatus === 'disconnected';

            googleWorkspaceConnectWrapper.style.display = (googleIsConnected && !googleRequiresReconnect) ? 'none' : 'block';
            googleWorkspaceSkipForm.style.display = 'none';
            googleWorkspaceDisconnectForm.style.display = (googleIsConnected && !googleRequiresReconnect) ? 'block' : 'none';

            if (state.google_workspace?.can_connect) {
                googleWorkspaceConnectLink.style.pointerEvents = '';
                googleWorkspaceConnectLink.style.opacity = '';
            } else {
                googleWorkspaceConnectLink.style.pointerEvents = 'none';
                googleWorkspaceConnectLink.style.opacity = '0.45';
            }
            googleWorkspaceNote.textContent = state.google_workspace?.health_note
                || (googleIsDisconnected
                    ? 'Google Workspace was disconnected. You can reconnect this account at any time.'
                    : 'Connect Google Workspace to finish preparing your customer-ready workspace.');
            googleWorkspaceError.textContent = state.google_workspace?.last_error || '';
            googleWorkspaceError.style.display = state.google_workspace?.last_error ? 'block' : 'none';
            goLiveAgentStatus.textContent = state.agent_status
                ? `${state.agent_status.charAt(0).toUpperCase()}${state.agent_status.slice(1)}`
                : 'Offline';
            goLiveNote.textContent = state.agent_status === 'live'
                ? `Your digital employee is active${state.files?.synced_at ? ` — last synced at ${state.files.synced_at}.` : '.'}`
                : state.workspace?.go_live_ready
                    ? `Everything is ready. Click Go Live above to activate your digital employee.`
                    : (state.workspace?.blocking_message || 'Finish the remaining setup steps before going live.');

            if (goLiveSubmitButton) {
                goLiveSubmitButton.disabled = state.agent_status !== 'live' && !state.workspace?.go_live_ready;
                goLiveSubmitButton.textContent = state.agent_status === 'live' ? 'Resync Assistant' : 'Go Live';
            }

            /* Toggle connected panel vs form */
            const isConnected = state.channel_setup?.status === 'connected';
            channelConnectedPanel.style.display = isConnected ? 'block' : 'none';
            channelForm.style.display = isConnected ? 'none' : 'block';
            if (isConnected && state.channel) {
                connectedChannelName.textContent = state.channel.charAt(0).toUpperCase() + state.channel.slice(1);
                const telegramSvg = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-.98-.19-1.46-.35-.59-.2-1.06-.3-1.02-.64.02-.18.27-.36.74-.55 2.92-1.27 4.86-2.11 5.83-2.51 2.78-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z" fill="#229ED9"/></svg>';
                connectedChannelIcon.innerHTML = telegramSvg;
            }

            fillBusinessForm(state);
            updateChannelSummaries(state);
        }

        /* ══════════════════════════════════════════════════════════════════
           FETCH HELPER (unchanged)
           ══════════════════════════════════════════════════════════════════ */
        async function fetchJson(url, payload) {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            });

            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                const message = data.message || Object.values(data.errors || {}).flat().join(' ') || 'Something went wrong. Please try again.';
                throw new Error(message);
            }

            return data;
        }

        async function refreshOnboardingState() {
            if (wizardOperation.active) {
                return;
            }

            const response = await fetch(onboardingStateEndpoint, {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                return;
            }

            applyState(await response.json());
        }

        /* ══════════════════════════════════════════════════════════════════
           STEP 1 — WEBSITE EXTRACTION (with progress indicator)
           ══════════════════════════════════════════════════════════════════ */
        const readWebsiteBtn = document.getElementById('read-website-btn');
        const websiteProgress = document.getElementById('website-progress');
        const progressMessage = document.getElementById('progress-message');
        const progressSteps = document.querySelectorAll('#progress-steps .progress-step');
        let progressTimers = [];

        function showWebsiteProgress() {
            readWebsiteBtn.disabled = true;
            readWebsiteBtn.textContent = 'Reading…';
            websiteProgress.style.display = 'block';
            hideMessage(websiteSuccess);
            hideMessage(websiteError);

            const timings = [
                { step: 'scraping',  delay: 0,     message: 'Reading your website pages…' },
                { step: 'analysing', delay: 18000,  message: 'Analysing business information…' },
                { step: 'writing',   delay: 32000,  message: 'Filling in your business details…' },
            ];

            timings.forEach(({ step, delay, message }) => {
                const t = setTimeout(() => {
                    progressMessage.textContent = message;
                    const el = websiteProgress.querySelector(`[data-step="${step}"]`);
                    if (el) {
                        el.style.opacity = '1';
                        el.querySelector('.step-icon').textContent = '🔄';
                    }
                    progressSteps.forEach((s) => {
                        if (s !== el && s.style.opacity === '1' && !s.hasAttribute('data-done')) {
                            s.setAttribute('data-done', '');
                            s.querySelector('.step-icon').textContent = '✓';
                        }
                    });
                }, delay);
                progressTimers.push(t);
            });
        }

        function hideWebsiteProgress(allDone = false) {
            progressTimers.forEach(clearTimeout);
            progressTimers = [];
            if (allDone) {
                progressSteps.forEach((s) => {
                    s.style.opacity = '1';
                    s.setAttribute('data-done', '');
                    s.querySelector('.step-icon').textContent = '✓';
                });
                setTimeout(() => {
                    websiteProgress.style.display = 'none';
                    resetProgressSteps();
                }, 800);
            } else {
                websiteProgress.style.display = 'none';
                resetProgressSteps();
            }
            readWebsiteBtn.disabled = false;
            readWebsiteBtn.textContent = 'Read My Website';
        }

        function resetProgressSteps() {
            progressSteps.forEach((s, i) => {
                s.removeAttribute('data-done');
                s.style.opacity = i === 0 ? '1' : '0.4';
                s.querySelector('.step-icon').textContent = '⏳';
            });
            progressMessage.textContent = 'Reading your website pages…';
        }

        websiteForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const releaseBusy = withButtonBusy(readWebsiteBtn, 'Reading Website…', 'reading_website');
            showWebsiteProgress();

            try {
                const data = await fetchJson(onboardingExtractEndpoint, {
                    url: websiteUrlFormInput.value,
                });

                hideWebsiteProgress(true);
                markDraft('website', false);
                applyState(data.state);
                showMessage(websiteSuccess, data.message || `We've pulled in your website details.`);
                advanceAfterSave(2);
            } catch (error) {
                hideWebsiteProgress(false);
                showMessage(websiteError, error.message);
            } finally {
                releaseBusy();
            }
        });

        /* ══════════════════════════════════════════════════════════════════
           STEP 2 — BUSINESS INFO
           ══════════════════════════════════════════════════════════════════ */
        businessForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            hideMessage(businessSuccess);
            hideMessage(businessError);
            const submitButton = businessForm.querySelector('button[type="submit"]');
            const releaseBusy = withButtonBusy(submitButton, 'Saving Details…', 'saving_business');

            try {
                const data = await fetchJson(onboardingBusinessInfoEndpoint, {
                    business_name: businessNameInput.value,
                    trading_name: tradingNameInput.value || null,
                    description: descriptionInput.value,
                    industry: industryInput.value,
                    services: normalizeServices(servicesInput.value),
                    contact_email: contactEmailInput.value,
                    contact_phone: contactPhoneInput.value || null,
                    physical_address: physicalAddressInput.value || null,
                    city: cityInput.value || null,
                    tagline: taglineInput.value || null,
                    website_url: websiteUrlInput.value || null,
                    owner_name: ownerNameInput.value || null,
                });

                markDraft('business', false);
                applyState(data.state);
                showMessage(businessSuccess, data.message || 'Your business details are saved.');
                advanceAfterSave(3);
            } catch (error) {
                showMessage(businessError, error.message);
            } finally {
                releaseBusy();
            }
        });

        /* ══════════════════════════════════════════════════════════════════
           STEP 3 — PERSONALITY
           ══════════════════════════════════════════════════════════════════ */
        personalityForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            hideMessage(personalitySuccess);
            hideMessage(personalityError);
            const submitButton = personalityForm.querySelector('button[type="submit"]');
            const releaseBusy = withButtonBusy(submitButton, 'Saving Style…', 'saving_tone');

            const selectedTone = personalityForm.querySelector('input[name="tone"]:checked');

            try {
                const data = await fetchJson(onboardingPersonalityEndpoint, {
                    tone: selectedTone ? selectedTone.value : null,
                });

                markDraft('personality', false);
                applyState(data.state);
                showMessage(personalitySuccess, data.message || 'Your communication style is saved.');
                advanceAfterSave(4);
            } catch (error) {
                showMessage(personalityError, error.message);
            } finally {
                releaseBusy();
            }
        });

        /* ══════════════════════════════════════════════════════════════════
           STEP 4 — MODULES
           ══════════════════════════════════════════════════════════════════ */
        capabilitiesForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            hideMessage(capabilitiesSuccess);
            hideMessage(capabilitiesError);
            const submitButton = capabilitiesForm.querySelector('button[type="submit"]');
            const releaseBusy = withButtonBusy(submitButton, 'Preparing Modules…', 'preparing_files');

            const selectedCapabilities = Array.from(capabilitiesForm.querySelectorAll('input[name="featured_skill_keys[]"]:checked'))
                .map((input) => input.value);

            try {
                const data = await fetchJson(onboardingCapabilitiesEndpoint, {
                    featured_skill_keys: selectedCapabilities,
                });

                markDraft('capabilities', false);
                applyState(data.state);
                showMessage(capabilitiesSuccess, data.message || 'Your modules are saved.');
                advanceAfterSave(5);
            } catch (error) {
                showMessage(capabilitiesError, error.message);
            } finally {
                releaseBusy();
            }
        });

        /* ══════════════════════════════════════════════════════════════════
           STEP 5 — CHANNEL
           ══════════════════════════════════════════════════════════════════ */
        channelForm.querySelectorAll('input[name="channel"]').forEach((input) => {
            input.addEventListener('change', updateChannelFields);
            input.addEventListener('change', () => updateChannelStatusNote(latestOnboardingState || {}));
        });

        channelForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            hideMessage(channelSuccess);
            hideMessage(channelError);
            const submitButton = channelForm.querySelector('button[type="submit"]');
            const releaseBusy = withButtonBusy(submitButton, 'Connecting Channel…', 'connecting_channel');

            const payload = {
                channel: selectedChannelValue(),
                telegram_bot_token: channelForm.querySelector('textarea[name="telegram_bot_token"]')?.value || null,
            };

            try {
                const data = await fetchJson(onboardingChannelEndpoint, payload);

                markDraft('channel', false);
                applyState(data.state);
                showMessage(channelSuccess, data.message || 'Your channel connection is saved.');
                advanceAfterSave(6);
            } catch (error) {
                showMessage(channelError, error.message);
            } finally {
                releaseBusy();
            }
        });

        disconnectChannelBtn.addEventListener('click', async () => {
            if (!confirm('Are you sure you want to disconnect this channel? Your assistant will stop receiving messages.')) return;

            const releaseBusy = withButtonBusy(disconnectChannelBtn, 'Disconnecting…', 'disconnecting_channel');

            try {
                const data = await fetchJson(onboardingChannelDisconnectEndpoint, {});
                resetChannelDraft();
                markDraft('channel', false);
                applyState(data.state);
                hideMessage(channelSuccess);
                hideMessage(channelError);
            } catch (error) {
                showMessage(channelError, error.message);
            } finally {
                releaseBusy();
            }
        });

        /* ══════════════════════════════════════════════════════════════════
           STEP 7 — GO LIVE
           ══════════════════════════════════════════════════════════════════ */
        goLiveForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            hideMessage(goLiveSuccess);
            hideMessage(goLiveError);
            const submitButton = goLiveForm.querySelector('button[type="submit"]');
            const isResync = latestOnboardingState?.agent_status === 'live';
            const releaseBusy = withButtonBusy(submitButton, isResync ? 'Resyncing Assistant…' : 'Going Live…', 'going_live');

            try {
                const data = await fetchJson(onboardingGoLiveEndpoint, {});

                applyState(data.state);
                showMessage(goLiveSuccess, data.message || (isResync ? 'Your digital employee has been resynced.' : 'Your digital employee is now live.'));
            } catch (error) {
                showMessage(goLiveError, error.message);
            } finally {
                releaseBusy();
            }
        });

        /* ══════════════════════════════════════════════════════════════════
           CLIPBOARD HELPER
           ══════════════════════════════════════════════════════════════════ */
        function copyField(inputId, btn) {
            const input = document.getElementById(inputId);
            if (!input) return;
            const original = btn.textContent;
            navigator.clipboard.writeText(input.value).then(() => {
                btn.textContent = 'Copied ✓';
                setTimeout(() => { btn.textContent = original; }, 2000);
            }).catch(() => {
                input.select();
                document.execCommand('copy');
                btn.textContent = 'Copied ✓';
                setTimeout(() => { btn.textContent = original; }, 2000);
            });
        }

        /* ══════════════════════════════════════════════════════════════════
           INITIALISE
           ══════════════════════════════════════════════════════════════════ */
        manualFocusButton.addEventListener('click', () => {
            showWizardStep(2);
            businessNameInput.focus();
        });

        websiteUrlFormInput?.addEventListener('input', () => markDraft('website'));

        [
            businessNameInput,
            tradingNameInput,
            industryInput,
            taglineInput,
            descriptionInput,
            contactEmailInput,
            contactPhoneInput,
            websiteUrlInput,
            ownerNameInput,
            physicalAddressInput,
            cityInput,
            servicesInput,
        ].forEach((input) => {
            input?.addEventListener('input', () => markDraft('business'));
        });

        personalityForm.querySelectorAll('input[name="tone"]').forEach((input) => {
            input.addEventListener('change', () => markDraft('personality'));
        });

        capabilitiesForm.querySelectorAll('input[name="featured_skill_keys[]"]').forEach((input) => {
            input.addEventListener('change', () => markDraft('capabilities'));
        });

        channelForm.querySelectorAll('input, textarea').forEach((input) => {
            const eventName = input.tagName === 'TEXTAREA' ? 'input' : 'change';
            input.addEventListener(eventName, () => markDraft('channel'));
        });

        updateChannelFields();
        applyState(@json($state));
        showWizardStep(currentStep);
        window.setInterval(refreshOnboardingState, 5000);
    </script>
</x-layouts.app>
