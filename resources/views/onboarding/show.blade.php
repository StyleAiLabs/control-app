<x-layouts.app title="Guided Setup — Sync360">

    {{-- ───────────────────────── Wizard chrome ───────────────────────── --}}
    <style>
        /* ── wizard layout ── */
        .wizard-wrapper { }

        /* ── step indicator bar ── */
        .wizard-steps-bar {
            display: flex; gap: 4px; margin-bottom: 32px;
            padding: 0; list-style: none;
        }
        .wizard-steps-bar li {
            flex: 1; display: flex; flex-direction: column; align-items: center; gap: 6px;
            font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.04em;
            color: var(--text-muted, #9ca3af); cursor: pointer; position: relative;
            transition: color 0.25s;
        }
        .wizard-steps-bar li::before {
            content: ''; display: block; width: 100%; height: 4px; border-radius: 2px;
            background: var(--border, #e5e7eb); transition: background 0.3s;
        }
        .wizard-steps-bar li.done::before { background: var(--success, #22c55e); }
        .wizard-steps-bar li.active::before { background: var(--primary, #ef4444); }
        .wizard-steps-bar li.active { color: var(--text, #111827); font-weight: 600; }
        .wizard-steps-bar li.done { color: var(--success, #22c55e); }

        /* ── panels ── */
        .wizard-panel {
            animation: wizardFadeIn 0.3s ease;
        }
        @keyframes wizardFadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }

        /* ── nav row ── */
        .wizard-nav {
            display: flex; justify-content: space-between; gap: 12px;
            margin-top: 28px; padding-top: 20px;
            border-top: 1px solid var(--border, #e5e7eb);
        }
        .wizard-nav .spacer { flex: 1; }

        /* ── progress spinner from Step 1 ── */
        @keyframes spin { to { transform: rotate(360deg); } }
        .progress-step { transition: opacity 0.4s ease; }
    </style>

    <div class="wizard-wrapper">
        {{-- ── top bar ── --}}
        <div class="topbar" style="margin-bottom: 24px;">
            <div>
                <span class="eyebrow">Guided Setup</span>
                <h2>Set up your digital employee</h2>
                <p style="margin-top: 4px;">Tell us about your business, choose how it should respond, and connect the channel your customers already use.</p>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="{{ route('dashboard') }}" class="button button--secondary">Back to Dashboard</a>
                @if (($state['workspace']['ready'] ?? false) === false)
                    <a href="{{ route('tenant.setup') }}" class="button button--primary">Watch Workspace Setup</a>
                @elseif (! empty($state['workspace']['url']))
                    <a href="{{ $state['workspace']['url'] }}" class="button button--primary" target="_blank" rel="noreferrer">Open Workspace</a>
                @endif
            </div>
        </div>

        {{-- ── step indicator ── --}}
        <ul class="wizard-steps-bar" id="wizard-steps-bar">
            @foreach ($state['steps'] as $number => $step)
                <li data-step="{{ $number }}" class="{{ $step['status'] === 'complete' ? 'done' : '' }}">
                    <span></span>{{ $step['label'] }}
                </li>
            @endforeach
        </ul>

        {{-- ═══════════════════ STEP 1 — Read Website ═══════════════════ --}}
        <div class="wizard-panel panel" data-wizard-step="1" id="wizard-step-1" style="display: none;">
            <span class="eyebrow">Step 1</span>
            <h3 style="margin-top: 16px; font-size: 1.35rem;">Read your business website</h3>
            <p style="margin-top: 8px;">
                Add your website and we'll pull in the basics for you. If website reading isn't available or your site is sparse, you can fill everything in manually in the next step.
            </p>

            <form id="website-form" style="margin-top: 18px;">
                @csrf
                <div class="field-single">
                    <label>
                        Business Website
                        <input id="website-url" type="url" name="url" placeholder="https://yourbusiness.com" value="{{ $state['business']['website_url'] ?? '' }}">
                    </label>
                </div>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button type="submit" id="read-website-btn">Read My Website</button>
                    <button type="button" class="button button--secondary" id="manual-focus-button">Skip — Fill In Manually</button>
                </div>
            </form>

            <div id="website-progress" style="margin-top: 18px; display: none;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <svg id="progress-spinner" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" style="flex-shrink: 0; animation: spin 1s linear infinite;">
                        <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="2" stroke-dasharray="40" stroke-dashoffset="15" opacity="0.25"/>
                        <path d="M10 2a8 8 0 0 1 8 8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <span id="progress-message" style="font-size: 0.875rem; color: var(--text-muted, #6b7280);">Reading your website pages…</span>
                </div>
                <div id="progress-steps" style="margin-top: 12px; display: grid; gap: 6px;">
                    <div class="progress-step" data-step="scraping" style="display: flex; align-items: center; gap: 8px; font-size: 0.8rem;">
                        <span class="step-icon" style="width: 16px; text-align: center;">⏳</span>
                        <span>Reading your website pages</span>
                    </div>
                    <div class="progress-step" data-step="analysing" style="display: flex; align-items: center; gap: 8px; font-size: 0.8rem; opacity: 0.4;">
                        <span class="step-icon" style="width: 16px; text-align: center;">⏳</span>
                        <span>Analysing business information</span>
                    </div>
                    <div class="progress-step" data-step="writing" style="display: flex; align-items: center; gap: 8px; font-size: 0.8rem; opacity: 0.4;">
                        <span class="step-icon" style="width: 16px; text-align: center;">⏳</span>
                        <span>Filling in your business details</span>
                    </div>
                </div>
            </div>

            <div class="note" style="margin-top: 18px; display: none;" id="website-success"></div>
            <div class="note error" style="margin-top: 18px; display: none;" id="website-error"></div>

            <div class="wizard-nav">
                <span class="spacer"></span>
                <button type="button" class="button button--primary" data-wizard-next>Next →</button>
            </div>
        </div>

        {{-- ═══════════════════ STEP 2 — Business Details ═══════════════════ --}}
        <div class="wizard-panel panel" data-wizard-step="2" id="wizard-step-2" style="display: none;">
            <span class="eyebrow">Step 2</span>
            <h3 style="margin-top: 16px; font-size: 1.35rem;">Confirm your business details</h3>
            <p style="margin-top: 8px;">
                Check what we know so far and adjust anything that needs fixing. These details will shape how your digital employee talks about your business later.
            </p>

            <form id="business-form" style="margin-top: 18px;">
                @csrf
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

                <button type="submit">Save Business Details</button>
            </form>
            <div class="note" style="margin-top: 18px; display: none;" id="business-success"></div>
            <div class="note error" style="margin-top: 18px; display: none;" id="business-error"></div>

            <div class="wizard-nav">
                <button type="button" class="button button--secondary" data-wizard-prev>← Back</button>
                <button type="button" class="button button--primary" data-wizard-next>Next →</button>
            </div>
        </div>

        {{-- ═══════════════════ STEP 3 — Personality ═══════════════════ --}}
        <div class="wizard-panel panel" data-wizard-step="3" id="wizard-step-3" style="display: none;">
            <span class="eyebrow">Step 3</span>
            <h3 style="margin-top: 16px; font-size: 1.35rem;">Choose the communication style</h3>
            <p style="margin-top: 8px;">
                Pick the style that feels most like your business. You can change this later.
            </p>

            <form id="personality-form" style="margin-top: 18px;">
                @csrf
                <div style="display: grid; gap: 12px;">
                    @php
                        $toneOptions = [
                            'friendly' => ['label' => 'Friendly & Warm', 'description' => 'Approachable, warm, and easy to talk to.'],
                            'professional' => ['label' => 'Professional', 'description' => 'Clear, polished, and business-ready.'],
                            'formal' => ['label' => 'Formal', 'description' => 'Precise, reserved, and traditional.'],
                            'casual' => ['label' => 'Casual & Fun', 'description' => 'Relaxed, upbeat, and conversational.'],
                        ];
                    @endphp
                    @foreach ($toneOptions as $value => $toneOption)
                        <label class="meta-item" style="cursor: pointer;">
                            <span style="display: flex; gap: 12px; align-items: flex-start;">
                                <input type="radio" name="tone" value="{{ $value }}" style="width: auto; margin-top: 2px;" @checked(($state['tone'] ?? $state['business']['tone_hint'] ?? null) === $value)>
                                <span>
                                    <strong>{{ $toneOption['label'] }}</strong>
                                    <span class="hint" style="display: block; margin-top: 4px;">{{ $toneOption['description'] }}</span>
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
                <button type="submit">Save Communication Style</button>
            </form>
            <div class="note" style="margin-top: 18px; display: none;" id="personality-success"></div>
            <div class="note error" style="margin-top: 18px; display: none;" id="personality-error"></div>

            <div class="wizard-nav">
                <button type="button" class="button button--secondary" data-wizard-prev>← Back</button>
                <button type="button" class="button button--primary" data-wizard-next>Next →</button>
            </div>
        </div>

        {{-- ═══════════════════ STEP 4 — Capabilities ═══════════════════ --}}
        <div class="wizard-panel panel" data-wizard-step="4" id="wizard-step-4" style="display: none;">
            <span class="eyebrow">Step 4</span>
            <h3 style="margin-top: 16px; font-size: 1.35rem;">Choose what it should handle</h3>
            <p style="margin-top: 8px;">
                Turn on the kinds of customer requests your digital employee should help with first.
            </p>

            <form id="capabilities-form" style="margin-top: 18px;">
                @csrf
                @php
                    $capabilityOptions = [
                        'faqs' => ['label' => 'Answer common questions', 'description' => 'Handle FAQs about services, business details, and how to get help.'],
                        'messages' => ['label' => 'Take messages', 'description' => 'Collect contact details and pass through follow-up requests.'],
                        'complaints' => ['label' => 'Handle complaints', 'description' => 'Acknowledge issues clearly and guide customers toward the next step.'],
                        'after_hours' => ['label' => 'Reply after hours', 'description' => 'Let customers know what happens when the business is currently unavailable.'],
                        'appointments' => ['label' => 'Help with bookings', 'description' => 'Support questions about booking and appointment next steps.'],
                        'pricing' => ['label' => 'Discuss pricing', 'description' => 'Share pricing guidance when the business has enough information to do so.'],
                    ];
                @endphp
                <div style="display: grid; gap: 12px;">
                    @foreach ($capabilityOptions as $value => $capability)
                        <label class="meta-item" style="cursor: pointer;">
                            <span style="display: flex; gap: 12px; align-items: flex-start;">
                                <input type="checkbox" name="capabilities[]" value="{{ $value }}" style="width: auto; margin-top: 2px;" @checked(in_array($value, $state['capabilities'] ?? [], true))>
                                <span>
                                    <strong>{{ $capability['label'] }}</strong>
                                    <span class="hint" style="display: block; margin-top: 4px;">{{ $capability['description'] }}</span>
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
                <button type="submit">Save Capabilities</button>
            </form>
            <div class="note" style="margin-top: 18px; display: none;" id="capabilities-success"></div>
            <div class="note error" style="margin-top: 18px; display: none;" id="capabilities-error"></div>
            <div class="note" style="margin-top: 18px;" id="files-note">
                @if (! empty($state['files']['generated_at']))
                    Your internal setup files were generated on {{ $state['files']['generated_at'] }}.
                @else
                    Once you save the capabilities, we'll prepare the internal setup files behind the scenes.
                @endif
            </div>

            <div class="wizard-nav">
                <button type="button" class="button button--secondary" data-wizard-prev>← Back</button>
                <button type="button" class="button button--primary" data-wizard-next>Next →</button>
            </div>
        </div>

        {{-- ═══════════════════ STEP 5 — Channel ═══════════════════ --}}
        <div class="wizard-panel panel" data-wizard-step="5" id="wizard-step-5" style="display: none;">
            <span class="eyebrow">Step 5</span>
            <h3 style="margin-top: 16px; font-size: 1.35rem;">Connect your customer channel</h3>
            <p style="margin-top: 8px;">
                Choose where customers will message you first. We'll keep the technical setup behind the scenes and just store what's needed to connect it.
            </p>

            {{-- Connected status panel — shown when a channel is already connected --}}
            <div id="channel-connected-panel" style="margin-top: 18px; display: {{ ($state['channel_setup']['status'] ?? '') === 'connected' ? 'block' : 'none' }};">
                <div class="meta-item" style="display: flex; align-items: center; justify-content: space-between; padding: 16px 20px;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <span id="connected-channel-icon">
                            @if(($state['channel'] ?? '') === 'telegram')
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-.98-.19-1.46-.35-.59-.2-1.06-.3-1.02-.64.02-.18.27-.36.74-.55 2.92-1.27 4.86-2.11 5.83-2.51 2.78-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z" fill="#229ED9"/></svg>
                            @else
                                <svg width="28" height="28" viewBox="0 0 24 24" fill="none"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z" fill="#25D366"/><path d="M12.004 2C6.489 2 2 6.489 2 12.004c0 1.762.46 3.476 1.333 4.99L2 22l5.233-1.237A9.956 9.956 0 0012.004 22C17.52 22 22 17.52 22 12.004 22 6.489 17.52 2 12.004 2zm0 18.15A8.14 8.14 0 017.55 18.8l-.35-.21-3.1.73.82-3-.23-.36a8.108 8.108 0 01-1.24-4.35C3.45 7.29 7.29 3.45 12 3.45c2.27 0 4.4.88 6.01 2.49a8.453 8.453 0 012.49 6.01c.01 4.72-3.84 8.56-8.5 8.56v-.01z" fill="#25D366"/></svg>
                            @endif
                        </span>
                        <div>
                            <strong id="connected-channel-name" style="font-size: 1.05rem;">{{ ucfirst($state['channel'] ?? '') }}</strong>
                            <span style="display: inline-block; margin-left: 10px; background: #22c55e; color: #fff; font-size: 0.72rem; font-weight: 600; padding: 2px 10px; border-radius: 6px; vertical-align: middle;">Connected</span>
                            <span class="hint" style="display: block; margin-top: 4px;">Your assistant is receiving messages on this channel.</span>
                        </div>
                    </div>
                    <button type="button" id="disconnect-channel-btn" style="background: transparent; color: #ef4444; border: 1px solid #ef4444; padding: 6px 16px; border-radius: 8px; font-size: 0.85rem; font-weight: 500; cursor: pointer; white-space: nowrap;">
                        Disconnect
                    </button>
                </div>
            </div>

            {{-- WhatsApp Coming Soon — always visible so customers know it's planned --}}
            <div id="whatsapp-coming-soon" class="meta-item" style="margin-top: 12px; cursor: not-allowed; opacity: 0.55; display: {{ ($state['channel_setup']['status'] ?? '') === 'connected' ? 'block' : 'none' }};">
                <span style="display: flex; gap: 12px; align-items: center; padding: 4px 0;">
                    <svg width="26" height="26" viewBox="0 0 24 24" fill="none" style="flex-shrink: 0;"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z" fill="#25D366"/><path d="M12.004 2C6.489 2 2 6.489 2 12.004c0 1.762.46 3.476 1.333 4.99L2 22l5.233-1.237A9.956 9.956 0 0012.004 22C17.52 22 22 17.52 22 12.004 22 6.489 17.52 2 12.004 2zm0 18.15A8.14 8.14 0 017.55 18.8l-.35-.21-3.1.73.82-3-.23-.36a8.108 8.108 0 01-1.24-4.35C3.45 7.29 7.29 3.45 12 3.45c2.27 0 4.4.88 6.01 2.49a8.453 8.453 0 012.49 6.01c.01 4.72-3.84 8.56-8.5 8.56v-.01z" fill="#25D366"/></svg>
                    <span>
                        <strong>WhatsApp</strong>
                        <span style="display: inline-block; margin-left: 8px; background: var(--accent, #FF6B35); color: #fff; font-size: 0.7rem; font-weight: 600; padding: 2px 8px; border-radius: 6px; vertical-align: middle;">Coming Soon</span>
                    </span>
                </span>
            </div>

            <form id="channel-form" style="margin-top: 18px; {{ ($state['channel_setup']['status'] ?? '') === 'connected' ? 'display: none;' : '' }}">
                @csrf
                <div style="display: grid; gap: 12px;">
                    <label class="meta-item" style="cursor: not-allowed; opacity: 0.55; position: relative;">
                        <span style="display: flex; gap: 12px; align-items: flex-start;">
                            <input type="radio" name="channel" value="whatsapp" style="width: auto; margin-top: 2px;" disabled>
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" style="flex-shrink: 0; margin-top: 1px;"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z" fill="#25D366"/><path d="M12.004 2C6.489 2 2 6.489 2 12.004c0 1.762.46 3.476 1.333 4.99L2 22l5.233-1.237A9.956 9.956 0 0012.004 22C17.52 22 22 17.52 22 12.004 22 6.489 17.52 2 12.004 2zm0 18.15A8.14 8.14 0 017.55 18.8l-.35-.21-3.1.73.82-3-.23-.36a8.108 8.108 0 01-1.24-4.35C3.45 7.29 7.29 3.45 12 3.45c2.27 0 4.4.88 6.01 2.49a8.453 8.453 0 012.49 6.01c.01 4.72-3.84 8.56-8.5 8.56v-.01z" fill="#25D366"/></svg>
                            <span>
                                <strong>WhatsApp</strong>
                                <span style="display: inline-block; margin-left: 8px; background: var(--accent, #FF6B35); color: #fff; font-size: 0.7rem; font-weight: 600; padding: 2px 8px; border-radius: 6px; vertical-align: middle;">Coming Soon</span>
                                <span class="hint" style="display: block; margin-top: 4px;">Connect your WhatsApp Business line so customer messages can be handled there.</span>
                            </span>
                        </span>
                    </label>
                    <label class="meta-item" style="cursor: pointer;">
                        <span style="display: flex; gap: 12px; align-items: flex-start;">
                            <input type="radio" name="channel" value="telegram" style="width: auto; margin-top: 2px;" @checked(($state['channel'] ?? null) === 'telegram' || ($state['channel'] ?? null) !== 'whatsapp')>
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" style="flex-shrink: 0; margin-top: 1px;"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-.98-.19-1.46-.35-.59-.2-1.06-.3-1.02-.64.02-.18.27-.36.74-.55 2.92-1.27 4.86-2.11 5.83-2.51 2.78-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z" fill="#229ED9"/></svg>
                            <span>
                                <strong>Telegram</strong>
                                <span class="hint" style="display: block; margin-top: 4px;">Connect your Telegram bot so customers can start messaging it right away.</span>
                            </span>
                        </span>
                    </label>
                </div>

                <div id="whatsapp-fields" style="margin-top: 18px; display: none;">
                    <div style="background: #faf9f8; border: 1px solid var(--stroke, #e5e7eb); border-radius: 14px; padding: 18px 20px; margin-bottom: 18px;">
                        <strong style="display: block; margin-bottom: 10px; font-size: 0.95rem;">Getting your WhatsApp details</strong>
                        <p style="margin: 0 0 12px; font-size: 0.88rem; color: var(--muted, #6b7280); line-height: 1.6;">
                            We need a few details from your <strong>WhatsApp Business</strong> account so your digital employee can send and receive messages on your behalf. Here's how to find them:
                        </p>
                        <ol style="margin: 0; padding-left: 20px; font-size: 0.88rem; color: var(--muted, #6b7280); line-height: 1.7;">
                            <li>Log in to <a href="https://business.facebook.com" target="_blank" rel="noopener" style="color: var(--accent, #FF6B35); text-decoration: underline;">Meta Business Suite</a> and go to your WhatsApp account.</li>
                            <li>Under <strong>Phone Numbers</strong>, find your business number and copy the <strong>Phone Number ID</strong> shown next to it.</li>
                            <li>Under <strong>API Setup</strong>, generate an <strong>Access Token</strong> and copy it. <span class="hint">(Use a permanent token for uninterrupted service.)</span></li>
                            <li>Paste both values into the fields below and click <strong>Save Channel Connection</strong>.</li>
                        </ol>
                        <p style="margin: 12px 0 0; font-size: 0.82rem; color: var(--muted, #6b7280);">
                            Once saved, we handle everything else &mdash; your assistant will be ready to reply on WhatsApp automatically.
                        </p>
                    </div>
                    <div class="field-grid">
                        <label>
                            Phone Number ID
                            <input type="text" name="whatsapp_phone_number_id" placeholder="e.g. 106540321987654">
                        </label>
                        <label>
                            Verify Token <span class="hint">(optional)</span>
                            <input type="text" id="whatsapp-verify-token-input" name="whatsapp_verify_token" placeholder="Leave blank and we'll generate one for you">
                        </label>
                    </div>
                    <div class="field-single">
                        <label>
                            Access Token
                            <textarea name="whatsapp_access_token" placeholder="Paste the access token from Meta Business Suite"></textarea>
                        </label>
                    </div>
                </div>

                <div id="telegram-fields" style="margin-top: 18px; display: none;">
                    <div style="background: #faf9f8; border: 1px solid var(--stroke, #e5e7eb); border-radius: 14px; padding: 18px 20px; margin-bottom: 18px;">
                        <strong style="display: block; margin-bottom: 10px; font-size: 0.95rem;">Creating your Telegram bot</strong>
                        <p style="margin: 0 0 12px; font-size: 0.88rem; color: var(--muted, #6b7280); line-height: 1.6;">
                            Your digital employee needs a Telegram bot to chat with your customers. It only takes a minute to set one up:
                        </p>
                        <ol style="margin: 0; padding-left: 20px; font-size: 0.88rem; color: var(--muted, #6b7280); line-height: 1.7;">
                            <li>Open Telegram on your phone or desktop and search for <strong>@BotFather</strong>.</li>
                            <li>Send the message <strong>/newbot</strong> and follow the prompts to pick a name and username for your bot.</li>
                            <li>BotFather will reply with a <strong>bot token</strong> &mdash; it looks something like <code style="background: #eee; padding: 2px 6px; border-radius: 4px;">123456:ABC-DEF1234</code>.</li>
                            <li>Copy the token, paste it below, and click <strong>Save Channel Connection</strong>.</li>
                        </ol>
                        <p style="margin: 12px 0 0; font-size: 0.82rem; color: var(--muted, #6b7280);">
                            That's it! We take care of the rest &mdash; your assistant will start replying to messages sent to your bot automatically.
                        </p>
                    </div>
                    <div class="field-single">
                        <label>
                            Bot Token
                            <textarea name="telegram_bot_token" placeholder="Paste the bot token from @BotFather"></textarea>
                        </label>
                    </div>
                </div>

                <button type="submit">Save Channel Connection</button>
            </form>
            <div class="note" style="margin-top: 18px; display: none;" id="channel-success"></div>
            <div class="note error" style="margin-top: 18px; display: none;" id="channel-error"></div>

            <div class="note" style="margin-top: 18px;" id="channel-status-note">
                Pick a channel above and fill in the details &mdash; once saved, your assistant will be ready to receive messages.
            </div>

            <div id="whatsapp-setup-summary" style="margin-top: 18px; display: none;">
                <div class="meta">
                    <div class="meta-item">
                        <small>WhatsApp Webhook URL</small>
                        <input id="whatsapp-webhook-url" type="text" readonly value="{{ $state['channel_setup']['whatsapp']['webhook_url'] ?? '' }}">
                    </div>
                    <div class="meta-item">
                        <small>Verify Token</small>
                        <input id="whatsapp-verify-token-preview" type="text" readonly value="{{ $state['channel_setup']['whatsapp']['verify_token'] ?? 'Generated after save' }}">
                    </div>
                    <div class="meta-item">
                        <small>Phone Number ID</small>
                        <span id="whatsapp-phone-id-status">{{ $state['channel_setup']['whatsapp']['phone_number_id'] ?? 'Not saved yet' }}</span>
                    </div>
                    <div class="meta-item">
                        <small>Access Token</small>
                        <span id="whatsapp-access-token-status">{{ ! empty($state['channel_setup']['whatsapp']['access_token_saved']) ? 'Saved' : 'Not saved yet' }}</span>
                    </div>
                </div>
                <div class="note" style="margin-top: 18px;">
                    In Meta, use the webhook URL above as the callback URL, paste the verify token exactly as shown, then subscribe message events for this phone number.
                </div>
            </div>

            <div id="telegram-setup-summary" style="margin-top: 18px; display: none;">
                <div class="meta">
                    <div class="meta-item">
                        <small>Telegram Webhook URL</small>
                        <input id="telegram-webhook-url" type="text" readonly value="{{ $state['channel_setup']['telegram']['webhook_url'] ?? '' }}">
                    </div>
                    <div class="meta-item">
                        <small>Bot Token</small>
                        <span id="telegram-bot-token-status">{{ ! empty($state['channel_setup']['telegram']['bot_token_saved']) ? 'Saved' : 'Not saved yet' }}</span>
                    </div>
                </div>
                <div class="note" style="margin-top: 18px;">
                    If you are setting Telegram manually, point your bot webhook at the URL above after saving the bot token.
                </div>
            </div>

            <div class="wizard-nav">
                <button type="button" class="button button--secondary" data-wizard-prev>← Back</button>
                <button type="button" class="button button--primary" data-wizard-next>Next →</button>
            </div>
        </div>

        {{-- ═══════════════════ STEP 6 — Go Live ═══════════════════ --}}
        <div class="wizard-panel panel" data-wizard-step="6" id="wizard-step-6" style="display: none;">
            <span class="eyebrow">Step 6</span>
            <h3 style="margin-top: 16px; font-size: 1.35rem;">Bring it live</h3>
            <p style="margin-top: 8px;">
                Once your business details, response style, capabilities, and channel are in place, we'll sync everything to your live workspace and switch the assistant on.
            </p>

            <div class="meta" style="margin-top: 18px;">
                <div class="meta-item">
                    <small>Workspace Status</small>
                    <span id="go-live-workspace-status">{{ ($state['workspace']['ready'] ?? false) ? 'Ready' : 'Still preparing' }}</span>
                </div>
                <div class="meta-item">
                    <small>Selected Channel</small>
                    <span id="go-live-channel-status">
                        @if (($state['channel'] ?? null) === 'whatsapp')
                            WhatsApp
                        @elseif (($state['channel'] ?? null) === 'telegram')
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

            <div class="note" style="margin-top: 18px;" id="go-live-note">
                @if (($state['agent_status'] ?? null) === 'live')
                    Your digital employee is already live. If you update anything and run this again, we'll resync the latest files.
                @elseif (($state['workspace']['ready'] ?? false) === true)
                    Your workspace is ready. Once your channel is connected, you can bring the assistant live.
                @else
                    Your workspace is still being prepared in the background. We'll only go live once that setup is finished.
                @endif
            </div>

            <form id="go-live-form" style="margin-top: 18px;">
                @csrf
                <button type="submit">{{ ($state['agent_status'] ?? null) === 'live' ? 'Resync Live Assistant' : 'Bring My Assistant Live' }}</button>
            </form>
            <div class="note" style="margin-top: 18px; display: none;" id="go-live-success"></div>
            <div class="note error" style="margin-top: 18px; display: none;" id="go-live-error"></div>

            <div class="wizard-nav">
                <button type="button" class="button button--secondary" data-wizard-prev>← Back</button>
                <span class="spacer"></span>
            </div>
        </div>
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
        const onboardingCapabilitiesEndpoint = @json(route('onboarding.capabilities'));
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
        const whatsappFields = document.getElementById('whatsapp-fields');
        const telegramFields = document.getElementById('telegram-fields');
        const whatsappSetupSummary = document.getElementById('whatsapp-setup-summary');
        const telegramSetupSummary = document.getElementById('telegram-setup-summary');
        const channelStatusNote = document.getElementById('channel-status-note');
        const whatsappWebhookUrl = document.getElementById('whatsapp-webhook-url');
        const whatsappVerifyTokenPreview = document.getElementById('whatsapp-verify-token-preview');
        const whatsappPhoneIdStatus = document.getElementById('whatsapp-phone-id-status');
        const whatsappAccessTokenStatus = document.getElementById('whatsapp-access-token-status');
        const telegramWebhookUrl = document.getElementById('telegram-webhook-url');
        const telegramBotTokenStatus = document.getElementById('telegram-bot-token-status');
        const goLiveForm = document.getElementById('go-live-form');
        const goLiveSuccess = document.getElementById('go-live-success');
        const goLiveError = document.getElementById('go-live-error');
        const goLiveWorkspaceStatus = document.getElementById('go-live-workspace-status');
        const goLiveChannelStatus = document.getElementById('go-live-channel-status');
        const goLiveAgentStatus = document.getElementById('go-live-agent-status');
        const goLiveNote = document.getElementById('go-live-note');
        const manualFocusButton = document.getElementById('manual-focus-button');
        const filesNote = document.getElementById('files-note');

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
        const whatsappPhoneNumberIdInput = channelForm.querySelector('input[name="whatsapp_phone_number_id"]');
        const whatsappVerifyTokenInput = document.getElementById('whatsapp-verify-token-input');

        /* ══════════════════════════════════════════════════════════════════
           WIZARD NAVIGATION
           ══════════════════════════════════════════════════════════════════ */
        const wizardPanels = document.querySelectorAll('.wizard-panel');
        const wizardBarItems = document.querySelectorAll('#wizard-steps-bar li');
        let currentStep = {{ $state['resume_from_step'] ?? 1 }};

        function showWizardStep(step) {
            currentStep = step;
            wizardPanels.forEach(p => {
                const isActive = parseInt(p.dataset.wizardStep) === step;
                p.style.display = isActive ? 'block' : 'none';
            });
            wizardBarItems.forEach(li => {
                li.classList.remove('active');
                if (parseInt(li.dataset.step) === step) li.classList.add('active');
            });
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        /* next / prev buttons */
        document.querySelectorAll('[data-wizard-next]').forEach(btn => {
            btn.addEventListener('click', () => {
                if (currentStep < 6) showWizardStep(currentStep + 1);
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
            whatsappFields.style.display = channel === 'whatsapp' ? 'block' : 'none';
            telegramFields.style.display = channel === 'telegram' ? 'block' : 'none';
        }

        function updateChannelSummaries(state) {
            const channel = state?.channel || '';
            const setup = state?.channel_setup || {};
            const waHasSaved = !!(setup.whatsapp?.phone_number_id || setup.whatsapp?.access_token_saved);
            const tgHasSaved = !!setup.telegram?.bot_token_saved;
            whatsappSetupSummary.style.display = (channel === 'whatsapp' && waHasSaved) ? 'block' : 'none';
            telegramSetupSummary.style.display = (channel === 'telegram' && tgHasSaved) ? 'block' : 'none';
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
            websiteUrlFormInput.value = state.business?.website_url || websiteUrlFormInput.value;

            const toneInput = personalityForm.querySelector(`input[name="tone"][value="${state.tone || state.business?.tone_hint || ''}"]`);
            if (toneInput) {
                toneInput.checked = true;
            } else {
                personalityForm.querySelectorAll('input[name="tone"]').forEach((input) => {
                    input.checked = false;
                });
            }

            const selectedCapabilities = Array.isArray(state.capabilities) ? state.capabilities : [];
            capabilitiesForm.querySelectorAll('input[name="capabilities[]"]').forEach((input) => {
                input.checked = selectedCapabilities.includes(input.value);
            });

            /* Only overwrite channel selection from server if a channel has
               actually been saved. Otherwise the 5-second poll would reset
               whatever the user has locally selected / typed. */
            const serverChannel = state.channel || '';
            if (serverChannel) {
                channelForm.querySelectorAll('input[name="channel"]').forEach((input) => {
                    input.checked = input.value === serverChannel;
                });

                whatsappPhoneNumberIdInput.value = state.channel_setup?.whatsapp?.phone_number_id || '';
                whatsappVerifyTokenInput.value = state.channel_setup?.whatsapp?.verify_token || '';
                whatsappWebhookUrl.value = state.channel_setup?.whatsapp?.webhook_url || '';
                whatsappVerifyTokenPreview.value = state.channel_setup?.whatsapp?.verify_token || 'Generated after save';
                whatsappPhoneIdStatus.textContent = state.channel_setup?.whatsapp?.phone_number_id || 'Not saved yet';
                whatsappAccessTokenStatus.textContent = state.channel_setup?.whatsapp?.access_token_saved ? 'Saved' : 'Not saved yet';
                telegramWebhookUrl.value = state.channel_setup?.telegram?.webhook_url || '';
                telegramBotTokenStatus.textContent = state.channel_setup?.telegram?.bot_token_saved ? 'Saved' : 'Not saved yet';
            }

            updateChannelFields();
        }

        function applyState(state) {
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
                : `Once you save the capabilities, we'll prepare the internal setup files behind the scenes.`;
            goLiveWorkspaceStatus.textContent = state.workspace?.ready ? 'Ready' : 'Still preparing';
            goLiveChannelStatus.textContent = state.channel === 'whatsapp'
                ? 'WhatsApp'
                : state.channel === 'telegram'
                    ? 'Telegram'
                    : 'Not connected yet';
            goLiveAgentStatus.textContent = state.agent_status
                ? `${state.agent_status.charAt(0).toUpperCase()}${state.agent_status.slice(1)}`
                : 'Offline';
            channelStatusNote.textContent = state.channel === 'whatsapp'
                ? (state.channel_setup?.status === 'connected'
                    ? `WhatsApp is connected. You're all set — head to the final step to bring your assistant live.`
                    : `Fill in your WhatsApp details above and save to connect your assistant.`)
                : state.channel === 'telegram'
                    ? (state.channel_setup?.status === 'connected'
                        ? `Telegram is connected. You're all set — head to the final step to bring your assistant live.`
                        : `Paste your bot token above and save to connect your assistant.`)
                    : `Pick a channel above and fill in the details — once saved, your assistant will be ready to receive messages.`;
            goLiveNote.textContent = state.agent_status === 'live'
                ? `Your digital employee is live${state.files?.synced_at ? ` and was last synced at ${state.files.synced_at}.` : '.'}`
                : `Your assistant is ready to go. Click the button above to bring it live.`;

            /* Toggle connected panel vs form */
            const isConnected = state.channel_setup?.status === 'connected';
            channelConnectedPanel.style.display = isConnected ? 'block' : 'none';
            document.getElementById('whatsapp-coming-soon').style.display = isConnected ? 'block' : 'none';
            channelForm.style.display = isConnected ? 'none' : 'block';
            channelStatusNote.style.display = isConnected ? 'none' : 'block';
            if (isConnected && state.channel) {
                connectedChannelName.textContent = state.channel.charAt(0).toUpperCase() + state.channel.slice(1);
                const telegramSvg = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-.98-.19-1.46-.35-.59-.2-1.06-.3-1.02-.64.02-.18.27-.36.74-.55 2.92-1.27 4.86-2.11 5.83-2.51 2.78-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z" fill="#229ED9"/></svg>';
                const whatsappSvg = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z" fill="#25D366"/><path d="M12.004 2C6.489 2 2 6.489 2 12.004c0 1.762.46 3.476 1.333 4.99L2 22l5.233-1.237A9.956 9.956 0 0012.004 22C17.52 22 22 17.52 22 12.004 22 6.489 17.52 2 12.004 2zm0 18.15A8.14 8.14 0 017.55 18.8l-.35-.21-3.1.73.82-3-.23-.36a8.108 8.108 0 01-1.24-4.35C3.45 7.29 7.29 3.45 12 3.45c2.27 0 4.4.88 6.01 2.49a8.453 8.453 0 012.49 6.01c.01 4.72-3.84 8.56-8.5 8.56v-.01z" fill="#25D366"/></svg>';
                connectedChannelIcon.innerHTML = state.channel === 'telegram' ? telegramSvg : whatsappSvg;
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
            showWebsiteProgress();

            try {
                const data = await fetchJson(onboardingExtractEndpoint, {
                    url: websiteUrlFormInput.value,
                });

                hideWebsiteProgress(true);
                applyState(data.state);
                showMessage(websiteSuccess, data.message || `We've pulled in your website details.`);
            } catch (error) {
                hideWebsiteProgress(false);
                showMessage(websiteError, error.message);
            }
        });

        /* ══════════════════════════════════════════════════════════════════
           STEP 2 — BUSINESS INFO
           ══════════════════════════════════════════════════════════════════ */
        businessForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            hideMessage(businessSuccess);
            hideMessage(businessError);

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

                applyState(data.state);
                showMessage(businessSuccess, data.message || 'Your business details are saved.');
            } catch (error) {
                showMessage(businessError, error.message);
            }
        });

        /* ══════════════════════════════════════════════════════════════════
           STEP 3 — PERSONALITY
           ══════════════════════════════════════════════════════════════════ */
        personalityForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            hideMessage(personalitySuccess);
            hideMessage(personalityError);

            const selectedTone = personalityForm.querySelector('input[name="tone"]:checked');

            try {
                const data = await fetchJson(onboardingPersonalityEndpoint, {
                    tone: selectedTone ? selectedTone.value : null,
                });

                applyState(data.state);
                showMessage(personalitySuccess, data.message || 'Your communication style is saved.');
            } catch (error) {
                showMessage(personalityError, error.message);
            }
        });

        /* ══════════════════════════════════════════════════════════════════
           STEP 4 — CAPABILITIES
           ══════════════════════════════════════════════════════════════════ */
        capabilitiesForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            hideMessage(capabilitiesSuccess);
            hideMessage(capabilitiesError);

            const selectedCapabilities = Array.from(capabilitiesForm.querySelectorAll('input[name="capabilities[]"]:checked'))
                .map((input) => input.value);

            try {
                const data = await fetchJson(onboardingCapabilitiesEndpoint, {
                    capabilities: selectedCapabilities,
                });

                applyState(data.state);
                showMessage(capabilitiesSuccess, data.message || 'Your capabilities are saved.');
            } catch (error) {
                showMessage(capabilitiesError, error.message);
            }
        });

        /* ══════════════════════════════════════════════════════════════════
           STEP 5 — CHANNEL
           ══════════════════════════════════════════════════════════════════ */
        channelForm.querySelectorAll('input[name="channel"]').forEach((input) => {
            input.addEventListener('change', updateChannelFields);
        });

        channelForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            hideMessage(channelSuccess);
            hideMessage(channelError);

            const payload = {
                channel: selectedChannelValue(),
                whatsapp_phone_number_id: channelForm.querySelector('input[name="whatsapp_phone_number_id"]')?.value || null,
                whatsapp_access_token: channelForm.querySelector('textarea[name="whatsapp_access_token"]')?.value || null,
                whatsapp_verify_token: channelForm.querySelector('input[name="whatsapp_verify_token"]')?.value || null,
                telegram_bot_token: channelForm.querySelector('textarea[name="telegram_bot_token"]')?.value || null,
            };

            try {
                const data = await fetchJson(onboardingChannelEndpoint, payload);

                applyState(data.state);
                showMessage(channelSuccess, data.message || 'Your channel connection is saved.');
            } catch (error) {
                showMessage(channelError, error.message);
            }
        });

        disconnectChannelBtn.addEventListener('click', async () => {
            if (!confirm('Are you sure you want to disconnect this channel? Your assistant will stop receiving messages.')) return;

            disconnectChannelBtn.disabled = true;
            disconnectChannelBtn.textContent = 'Disconnecting…';

            try {
                const data = await fetchJson(onboardingChannelDisconnectEndpoint, {});
                applyState(data.state);
                hideMessage(channelSuccess);
                hideMessage(channelError);
            } catch (error) {
                showMessage(channelError, error.message);
            } finally {
                disconnectChannelBtn.disabled = false;
                disconnectChannelBtn.textContent = 'Disconnect';
            }
        });

        /* ══════════════════════════════════════════════════════════════════
           STEP 6 — GO LIVE
           ══════════════════════════════════════════════════════════════════ */
        goLiveForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            hideMessage(goLiveSuccess);
            hideMessage(goLiveError);

            try {
                const data = await fetchJson(onboardingGoLiveEndpoint, {});

                applyState(data.state);
                showMessage(goLiveSuccess, data.message || 'Your digital employee is now live.');
            } catch (error) {
                showMessage(goLiveError, error.message);
            }
        });

        /* ══════════════════════════════════════════════════════════════════
           INITIALISE
           ══════════════════════════════════════════════════════════════════ */
        manualFocusButton.addEventListener('click', () => {
            showWizardStep(2);
            businessNameInput.focus();
        });

        updateChannelFields();
        applyState(@json($state));
        showWizardStep(currentStep);
        window.setInterval(refreshOnboardingState, 5000);
    </script>
</x-layouts.app>
