<x-layouts.app title="Business Profile — Sync360">
    <div class="topbar">
        <div>
            <span class="eyebrow">Business Profile</span>
            <h2>Keep your assistant aligned with your business.</h2>
            <p>Update your business details here. If your digital employee is already live, we’ll sync the latest details into it automatically where possible.</p>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            <a href="{{ route('dashboard') }}" class="button button--secondary">Back to Dashboard</a>
            @if ($canSync)
                <form method="POST" action="{{ route('profile.sync-agent') }}">
                    @csrf
                    <button type="submit">Sync Assistant Now</button>
                </form>
            @endif
        </div>
    </div>

    <section class="grid grid-2">
        <div class="panel">
            <span class="eyebrow">Business Details</span>
            <form method="POST" action="{{ route('profile.update') }}" style="margin-top: 18px; display: grid; gap: 18px;">
                @csrf
                @method('PATCH')

                <div class="field-grid">
                    <label>
                        Business Name
                        <input type="text" name="business_name" value="{{ old('business_name', $profile?->business_name ?: $tenant->business_name) }}">
                    </label>
                    <label>
                        Trading Name <span class="hint">(optional)</span>
                        <input type="text" name="trading_name" value="{{ old('trading_name', $profile?->trading_name) }}">
                    </label>
                </div>

                <div class="field-grid">
                    <label>
                        Industry
                        <input type="text" name="industry" value="{{ old('industry', $profile?->industry ?: $tenant->industry) }}">
                    </label>
                    <label>
                        Website <span class="hint">(optional)</span>
                        <input type="url" name="website_url" value="{{ old('website_url', $profile?->website_url) }}">
                    </label>
                </div>

                <div class="field-single">
                    <label>
                        What your business does
                        <textarea name="description" placeholder="Describe the business, who it serves, and the types of jobs or requests it handles.">{{ old('description', $profile?->description) }}</textarea>
                    </label>
                </div>

                <div class="field-grid">
                    <label>
                        Tagline <span class="hint">(optional)</span>
                        <input type="text" name="tagline" value="{{ old('tagline', $profile?->tagline) }}">
                    </label>
                    <label>
                        Primary Language <span class="hint">(optional)</span>
                        <input type="text" name="primary_language" value="{{ old('primary_language', $profile?->primary_language) }}">
                    </label>
                </div>

                <div class="field-single">
                    <label>
                        Services
                        <textarea name="services_text" placeholder="Add one service per line">{{ old('services_text', is_array($profile?->services) ? implode("\n", $profile->services) : '') }}</textarea>
                    </label>
                </div>

                <div class="field-single">
                    <label>
                        Business Hours <span class="hint">(one line per day or rule)</span>
                        <textarea name="business_hours_text" placeholder="Mon-Fri: 8am - 5pm&#10;Sat: 9am - 1pm&#10;Sun: Closed">{{ old('business_hours_text', is_array($profile?->business_hours) ? implode("\n", $profile->business_hours) : '') }}</textarea>
                    </label>
                </div>

                <div class="field-single">
                    <label>
                        After-Hours Policy <span class="hint">(optional)</span>
                        <textarea name="after_hours_policy" placeholder="Explain what the assistant should say after hours.">{{ old('after_hours_policy', $profile?->after_hours_policy) }}</textarea>
                    </label>
                </div>

                <div class="field-grid">
                    <label>
                        Contact Email
                        <input type="email" name="contact_email" value="{{ old('contact_email', $profile?->contact_email) }}">
                    </label>
                    <label>
                        Contact Phone <span class="hint">(optional)</span>
                        <input type="text" name="contact_phone" value="{{ old('contact_phone', $profile?->contact_phone) }}">
                    </label>
                </div>

                <div class="field-grid">
                    <label>
                        Mobile <span class="hint">(optional)</span>
                        <input type="text" name="contact_mobile" value="{{ old('contact_mobile', $profile?->contact_mobile) }}">
                    </label>
                    <label>
                        Physical Address <span class="hint">(optional)</span>
                        <input type="text" name="physical_address" value="{{ old('physical_address', $profile?->physical_address) }}">
                    </label>
                </div>

                <div class="field-grid">
                    <label>
                        Postal Address <span class="hint">(optional)</span>
                        <input type="text" name="postal_address" value="{{ old('postal_address', $profile?->postal_address) }}">
                    </label>
                    <label>
                        City <span class="hint">(optional)</span>
                        <input type="text" name="city" value="{{ old('city', $profile?->city) }}">
                    </label>
                </div>

                <div class="field-grid">
                    <label>
                        Country <span class="hint">(optional)</span>
                        <input type="text" name="country" value="{{ old('country', $profile?->country) }}">
                    </label>
                    <label>
                        GST / Tax Number <span class="hint">(optional)</span>
                        <input type="text" name="tax_number" value="{{ old('tax_number', $profile?->tax_number) }}">
                    </label>
                </div>

                <div class="field-grid">
                    <label>
                        Company Registration <span class="hint">(optional)</span>
                        <input type="text" name="company_reg_number" value="{{ old('company_reg_number', $profile?->company_reg_number) }}">
                    </label>
                    <label>
                        Target Customers <span class="hint">(optional)</span>
                        <input type="text" name="target_customers" value="{{ old('target_customers', $profile?->target_customers) }}">
                    </label>
                </div>

                <div class="field-grid">
                    <label>
                        Owner Name <span class="hint">(optional)</span>
                        <input type="text" name="owner_name" value="{{ old('owner_name', $profile?->owner_name) }}">
                    </label>
                    <label>
                        Owner Email <span class="hint">(optional)</span>
                        <input type="email" name="owner_email" value="{{ old('owner_email', $profile?->owner_email) }}">
                    </label>
                </div>

                <div class="field-grid">
                    <label>
                        Owner Phone <span class="hint">(optional)</span>
                        <input type="text" name="owner_phone" value="{{ old('owner_phone', $profile?->owner_phone) }}">
                    </label>
                    <label>
                        Pricing Notes <span class="hint">(optional)</span>
                        <input type="text" name="pricing_notes" value="{{ old('pricing_notes', $profile?->pricing_notes) }}">
                    </label>
                </div>

                <div class="field-single">
                    <label>
                        FAQs <span class="hint">(optional, one per line)</span>
                        <textarea name="faqs_text" placeholder="Do you offer emergency callouts?&#10;What areas do you service?">{{ old('faqs_text', is_array($profile?->faqs) ? implode("\n", $profile->faqs) : '') }}</textarea>
                    </label>
                </div>

                <button type="submit">Save Business Profile</button>
            </form>
        </div>

        <div class="panel">
            <span class="eyebrow">Sync Status</span>
            <div class="meta" style="margin-top: 18px;">
                <div class="meta-item">
                    <small>Assistant Status</small>
                    <span class="badge {{ $tenant->agent_status === 'live' ? 'ready' : ($tenant->agent_status === 'failed' ? 'failed' : 'pending') }}">
                        {{ ucfirst($tenant->agent_status) }}
                    </span>
                </div>
                <div class="meta-item">
                    <small>Onboarding</small>
                    <span class="badge {{ $tenant->onboarding_status === 'complete' ? 'ready' : 'pending' }}">
                        {{ $tenant->onboarding_status === 'complete' ? 'Complete' : 'In Progress' }}
                    </span>
                </div>
                <div class="meta-item">
                    <small>Profile Completeness</small>
                    <span>{{ $profile?->profile_completeness ?? 0 }}%</span>
                </div>
                <div class="meta-item">
                    <small>Last Synced To Assistant</small>
                    <span>{{ $profile?->last_synced_to_agent?->diffForHumans() ?: 'Not synced yet' }}</span>
                </div>
                <div class="meta-item">
                    <small>Last File Generation</small>
                    <span>{{ $tenant->businessProfileFiles?->generated_at?->diffForHumans() ?: 'Not generated yet' }}</span>
                </div>
                <div class="meta-item">
                    <small>Latest Live Sync</small>
                    <span>{{ $tenant->businessProfileFiles?->synced_at?->diffForHumans() ?: 'Not pushed live yet' }}</span>
                </div>
            </div>

            <div class="note" style="margin-top: 18px;">
                @if ($tenant->agent_status === 'live')
                    Saving this form will try to sync the live assistant automatically. If you ever need to retry, use the manual sync button.
                @elseif ($canSync)
                    Your setup is complete. Use the sync button once you are ready to push the latest business profile into the assistant.
                @else
                    Finish the guided setup first so the assistant has its tone, capabilities, channel, and live workspace in place.
                @endif
            </div>

            <div class="note" style="margin-top: 18px;">
                The most helpful fields to keep current are your services, hours, contact details, GST/tax number, and any after-hours instructions.
            </div>

            <div style="margin-top: 18px; display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="{{ route('onboarding.show') }}" class="button button--secondary">Back to Guided Setup</a>
                @if (filled($tenant->workspace_url))
                    <a href="{{ $tenant->workspace_url }}" class="button button--secondary" target="_blank" rel="noreferrer">Open Workspace</a>
                @endif
            </div>
        </div>
    </section>
</x-layouts.app>
