<x-layouts.app title="Guided Setup — Sync360">
    <div class="topbar">
        <div>
            <span class="eyebrow">Guided Setup</span>
            <h2>Set up your digital employee in one guided flow.</h2>
            <p>We’ll keep this simple: tell us about your business, choose how it should respond, and connect the channel your customers already use.</p>
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

    <section class="grid grid-2">
        <div class="panel">
            <span class="eyebrow">Setup Progress</span>
            <div style="margin-top: 18px; display: grid; gap: 14px;" id="onboarding-steps">
                @foreach ($state['steps'] as $number => $step)
                    <div class="meta-item onboarding-step" data-step="{{ $number }}">
                        <small>Step {{ $number }}</small>
                        <div style="display: flex; justify-content: space-between; gap: 12px; align-items: center;">
                            <strong>{{ $step['label'] }}</strong>
                            <span class="badge {{ $step['status'] === 'complete' ? 'ready' : 'pending' }}">
                                {{ $step['status'] === 'complete' ? 'Done' : 'Coming Up' }}
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="note" style="margin-top: 18px;" id="resume-note">
                Your next step is <strong id="resume-step-label">{{ $state['steps'][$state['resume_from_step']]['label'] ?? 'Business Website' }}</strong>.
            </div>
        </div>

        <div class="panel">
            <span class="eyebrow">What We Already Know</span>
            <div class="meta" style="margin-top: 18px;">
                <div class="meta-item">
                    <small>Business</small>
                    <span id="business-name">{{ $state['business']['business_name'] ?? $tenant->business_name }}</span>
                </div>
                <div class="meta-item">
                    <small>Industry</small>
                    <span id="industry">{{ $state['business']['industry'] ?? $tenant->industry }}</span>
                </div>
                <div class="meta-item">
                    <small>Contact Email</small>
                    <span id="contact-email">{{ $state['business']['contact_email'] ?? 'We’ll collect this during setup.' }}</span>
                </div>
                <div class="meta-item">
                    <small>Services</small>
                    <span id="services">{{ $state['business']['services'] !== [] ? implode(', ', $state['business']['services']) : 'We’ll collect your services during setup.' }}</span>
                </div>
            </div>

            <div class="note" style="margin-top: 18px;" id="workspace-note">
                @if (($state['workspace']['ready'] ?? false) === true)
                    Your workspace is ready in the background. The next step is adding your business details and response style.
                @else
                    Your workspace is still being prepared in the background. You don’t need to do anything while we finish that part.
                @endif
            </div>
        </div>
    </section>

    <section class="grid grid-2" style="margin-top: 18px;">
        <div class="panel">
            <span class="eyebrow">Step 1</span>
            <h3 style="margin-top: 16px; font-size: 1.35rem;">Read your business website</h3>
            <p style="margin-top: 8px;">
                Add your website and we’ll pull in the basics for you. If website reading isn’t available or your site is sparse, you can fill everything in manually below.
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
                    <button type="submit">Read My Website</button>
                    <button type="button" class="button button--secondary" id="manual-focus-button">Fill In Manually</button>
                </div>
            </form>
            <div class="note" style="margin-top: 18px; display: none;" id="website-success"></div>
            <div class="note error" style="margin-top: 18px; display: none;" id="website-error"></div>
        </div>

        <div class="panel" id="business-info-panel">
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
        </div>
    </section>

    <section class="grid grid-2" style="margin-top: 18px;">
        <div class="panel" id="personality-panel">
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
        </div>

        <div class="panel" id="capabilities-panel">
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
                    Once you save the capabilities, we’ll prepare the internal setup files behind the scenes.
                @endif
            </div>
        </div>
    </section>

    <section class="grid grid-2" style="margin-top: 18px;">
        <div class="panel" id="channel-panel">
            <span class="eyebrow">Step 5</span>
            <h3 style="margin-top: 16px; font-size: 1.35rem;">Connect your customer channel</h3>
            <p style="margin-top: 8px;">
                Choose where customers will message you first. We’ll keep the technical setup behind the scenes and just store what’s needed to connect it.
            </p>

            <form id="channel-form" style="margin-top: 18px;">
                @csrf
                <div style="display: grid; gap: 12px;">
                    <label class="meta-item" style="cursor: pointer;">
                        <span style="display: flex; gap: 12px; align-items: flex-start;">
                            <input type="radio" name="channel" value="whatsapp" style="width: auto; margin-top: 2px;" @checked(($state['channel'] ?? null) === 'whatsapp')>
                            <span>
                                <strong>WhatsApp</strong>
                                <span class="hint" style="display: block; margin-top: 4px;">Connect your WhatsApp Business line so customer messages can be handled there.</span>
                            </span>
                        </span>
                    </label>
                    <label class="meta-item" style="cursor: pointer;">
                        <span style="display: flex; gap: 12px; align-items: flex-start;">
                            <input type="radio" name="channel" value="telegram" style="width: auto; margin-top: 2px;" @checked(($state['channel'] ?? null) === 'telegram')>
                            <span>
                                <strong>Telegram</strong>
                                <span class="hint" style="display: block; margin-top: 4px;">Connect your Telegram bot so customers can start messaging it right away.</span>
                            </span>
                        </span>
                    </label>
                </div>

                <div id="whatsapp-fields" style="margin-top: 18px; display: none;">
                    <div class="field-grid">
                        <label>
                            Phone Number ID
                            <input type="text" name="whatsapp_phone_number_id" placeholder="Your WhatsApp phone number ID">
                        </label>
                        <label>
                            Verify Token <span class="hint">(optional)</span>
                            <input type="text" id="whatsapp-verify-token-input" name="whatsapp_verify_token" placeholder="Leave blank and we’ll generate one for you">
                        </label>
                    </div>
                    <div class="field-single">
                        <label>
                            Access Token
                            <textarea name="whatsapp_access_token" placeholder="Paste your WhatsApp access token"></textarea>
                        </label>
                    </div>
                </div>

                <div id="telegram-fields" style="margin-top: 18px; display: none;">
                    <div class="field-single">
                        <label>
                            Bot Token
                            <textarea name="telegram_bot_token" placeholder="Paste your Telegram bot token"></textarea>
                        </label>
                    </div>
                </div>

                <button type="submit">Save Channel Connection</button>
            </form>
            <div class="note" style="margin-top: 18px; display: none;" id="channel-success"></div>
            <div class="note error" style="margin-top: 18px; display: none;" id="channel-error"></div>

            <div class="note" style="margin-top: 18px;" id="channel-status-note">
                Save a channel first and we’ll show you the exact webhook details to paste into the provider.
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
        </div>

        <div class="panel" id="go-live-panel">
            <span class="eyebrow">Step 6</span>
            <h3 style="margin-top: 16px; font-size: 1.35rem;">Bring it live</h3>
            <p style="margin-top: 8px;">
                Once your business details, response style, capabilities, and channel are in place, we’ll sync everything to your live workspace and switch the assistant on.
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
                    Your digital employee is already live. If you update anything and run this again, we’ll resync the latest files.
                @elseif (($state['workspace']['ready'] ?? false) === true)
                    Your workspace is ready. Once your channel is connected, you can bring the assistant live.
                @else
                    Your workspace is still being prepared in the background. We’ll only go live once that setup is finished.
                @endif
            </div>

            <form id="go-live-form" style="margin-top: 18px;">
                @csrf
                <button type="submit">{{ ($state['agent_status'] ?? null) === 'live' ? 'Resync Live Assistant' : 'Bring My Assistant Live' }}</button>
            </form>
            <div class="note" style="margin-top: 18px; display: none;" id="go-live-success"></div>
            <div class="note error" style="margin-top: 18px; display: none;" id="go-live-error"></div>
        </div>
    </section>

    <script>
        const onboardingStateEndpoint = @json(route('onboarding.state'));
        const onboardingExtractEndpoint = @json(route('onboarding.extract-business'));
        const onboardingBusinessInfoEndpoint = @json(route('onboarding.business-info'));
        const onboardingPersonalityEndpoint = @json(route('onboarding.personality'));
        const onboardingCapabilitiesEndpoint = @json(route('onboarding.capabilities'));
        const onboardingChannelEndpoint = @json(route('onboarding.channel'));
        const onboardingGoLiveEndpoint = @json(route('onboarding.go-live'));
        const csrfToken = @json(csrf_token());

        const stepsContainer = document.getElementById('onboarding-steps');
        const resumeStepLabel = document.getElementById('resume-step-label');
        const businessName = document.getElementById('business-name');
        const industry = document.getElementById('industry');
        const contactEmail = document.getElementById('contact-email');
        const services = document.getElementById('services');
        const workspaceNote = document.getElementById('workspace-note');
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
        const businessInfoPanel = document.getElementById('business-info-panel');
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

        function selectedChannelValue() {
            return channelForm.querySelector('input[name="channel"]:checked')?.value || '';
        }

        function updateChannelFields() {
            const channel = selectedChannelValue();
            whatsappFields.style.display = channel === 'whatsapp' ? 'block' : 'none';
            telegramFields.style.display = channel === 'telegram' ? 'block' : 'none';
            whatsappSetupSummary.style.display = channel === 'whatsapp' ? 'block' : 'none';
            telegramSetupSummary.style.display = channel === 'telegram' ? 'block' : 'none';
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

            const selectedChannel = state.channel || '';
            channelForm.querySelectorAll('input[name="channel"]').forEach((input) => {
                input.checked = input.value === selectedChannel;
            });

            whatsappPhoneNumberIdInput.value = state.channel_setup?.whatsapp?.phone_number_id || '';
            whatsappVerifyTokenInput.value = state.channel_setup?.whatsapp?.verify_token || '';
            whatsappWebhookUrl.value = state.channel_setup?.whatsapp?.webhook_url || '';
            whatsappVerifyTokenPreview.value = state.channel_setup?.whatsapp?.verify_token || 'Generated after save';
            whatsappPhoneIdStatus.textContent = state.channel_setup?.whatsapp?.phone_number_id || 'Not saved yet';
            whatsappAccessTokenStatus.textContent = state.channel_setup?.whatsapp?.access_token_saved ? 'Saved' : 'Not saved yet';
            telegramWebhookUrl.value = state.channel_setup?.telegram?.webhook_url || '';
            telegramBotTokenStatus.textContent = state.channel_setup?.telegram?.bot_token_saved ? 'Saved' : 'Not saved yet';

            updateChannelFields();
        }

        function applyState(state) {
            Object.entries(state.steps ?? {}).forEach(([stepNumber, step]) => {
                const row = stepsContainer.querySelector(`[data-step="${stepNumber}"]`);
                if (!row) {
                    return;
                }

                const badge = row.querySelector('.badge');
                if (!badge) {
                    return;
                }

                const complete = step.status === 'complete';
                badge.textContent = complete ? 'Done' : 'Coming Up';
                badge.className = `badge ${complete ? 'ready' : 'pending'}`;
            });

            const resumeStep = state.steps?.[state.resume_from_step];
            resumeStepLabel.textContent = resumeStep?.label ?? 'Business Website';
            businessName.textContent = state.business?.business_name || state.tenant?.business_name || 'We’ll collect this during setup.';
            industry.textContent = state.business?.industry || state.tenant?.industry || 'We’ll collect this during setup.';
            contactEmail.textContent = state.business?.contact_email || 'We’ll collect this during setup.';
            services.textContent = Array.isArray(state.business?.services) && state.business.services.length > 0
                ? state.business.services.join(', ')
                : 'We’ll collect your services during setup.';
            workspaceNote.textContent = state.workspace?.ready
                ? 'Your workspace is ready in the background. The next step is adding your business details and response style.'
                : 'Your workspace is still being prepared in the background. You don’t need to do anything while we finish that part.';
            filesNote.textContent = state.files?.generated_at
                ? `Your internal setup files were generated on ${state.files.generated_at}.`
                : 'Once you save the capabilities, we’ll prepare the internal setup files behind the scenes.';
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
                    ? 'WhatsApp is saved. Copy the webhook URL and verify token below into Meta, then move to the final go-live step.'
                    : 'Choose WhatsApp, save the connection, and we’ll generate the Meta webhook details for you below.')
                : state.channel === 'telegram'
                    ? (state.channel_setup?.status === 'connected'
                        ? 'Telegram is saved. If you need to set the bot webhook manually, use the URL below, then move to the final go-live step.'
                        : 'Choose Telegram, save the bot token, and we’ll show the webhook URL below.')
                    : 'Save a channel first and we’ll show you the exact webhook details to paste into the provider.';
            goLiveNote.textContent = state.agent_status === 'live'
                ? `Your digital employee is live${state.files?.synced_at ? ` and was last synced at ${state.files.synced_at}.` : '.'}`
                : state.workspace?.ready
                    ? 'Your workspace is ready. Once your channel is connected, you can bring the assistant live.'
                    : 'Your workspace is still being prepared in the background. We’ll only go live once that setup is finished.';

            fillBusinessForm(state);
        }

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

        websiteForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            hideMessage(websiteSuccess);
            hideMessage(websiteError);

            try {
                const data = await fetchJson(onboardingExtractEndpoint, {
                    url: websiteUrlFormInput.value,
                });

                applyState(data.state);
                showMessage(websiteSuccess, data.message || 'We’ve pulled in your website details.');
            } catch (error) {
                showMessage(websiteError, error.message);
            }
        });

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

        manualFocusButton.addEventListener('click', () => {
            businessInfoPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            businessNameInput.focus();
        });

        updateChannelFields();
        applyState(@json($state));
        window.setInterval(refreshOnboardingState, 5000);
    </script>
</x-layouts.app>
