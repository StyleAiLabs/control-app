<x-layouts.guest title="Sync360 — AI Employee For Service Businesses">
    <div class="landing-page full-bleed">
        <x-marketing.hero
            kicker="Sync360 digital employee"
            title="Sync360 keeps every enquiry moving."
            body="While your team gets on with the real work, Sync360 replies after hours, qualifies the job, books the next step, and keeps customers warm without adding more admin to your day."
        >
            <x-slot:actions>
                <x-marketing.cta-group
                    class="hero-actions animate-in animate-delay-3"
                    :primary-href="route('signup')"
                    primary-label="Start Free Trial &rarr;"
                    :secondary-href="route('login')"
                    secondary-label="Log In"
                />
            </x-slot:actions>

            <x-slot:proof>
                <div class="hero-proof type-secondary animate-in animate-delay-4">
                    <span>
                        <svg class="proof-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                        </svg>
                        No credit card required
                    </span>
                    <span>
                        <svg class="proof-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                        </svg>
                        Built for service businesses
                    </span>
                    <span>
                        <svg class="proof-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                        </svg>
                        Live in minutes
                    </span>
                </div>
            </x-slot:proof>

            <x-slot:visual>
                <div class="product-preview">
                    <div class="product-topbar">
                        <span>Sync360 workspace</span>
                        <span>Live</span>
                    </div>

                    <div class="preview-surface">
                        <div class="preview-sidebar">
                            <div class="preview-brand">
                                <strong>Northfield Plumbing</strong>
                                <span>Service workspace</span>
                            </div>
                            <div class="preview-nav">
                                <div># intake</div>
                                <div class="active"># ops-updates</div>
                                <div># quotes</div>
                                <div># follow-ups</div>
                            </div>
                            <div class="preview-team">
                                <span>On shift</span>
                                <div>Dispatch desk</div>
                                <div>Sam R.</div>
                                <div>Sync360</div>
                            </div>
                        </div>

                        <div class="preview-main">
                            <div class="preview-header">
                                <div>
                                    <h3># ops-updates</h3>
                                    <p>Everything Sync360 handled while the team stayed on the tools.</p>
                                </div>
                                <div class="preview-chip">Assistant active</div>
                            </div>

                            <div class="preview-stats">
                                <div class="preview-stat">
                                    <span>Handled</span>
                                    <strong>18 enquiries</strong>
                                </div>
                                <div class="preview-stat">
                                    <span>Booked</span>
                                    <strong>6 site visits</strong>
                                </div>
                                <div class="preview-stat">
                                    <span>Recovered</span>
                                    <strong>4 after hours</strong>
                                </div>
                            </div>

                            <div class="preview-feed">
                                <article class="preview-item">
                                    <div class="preview-item-icon">S</div>
                                    <div class="preview-item-copy">
                                        <div class="preview-item-meta">
                                            <strong>Sync360</strong>
                                            <span>APP</span>
                                            <time>8:01 AM</time>
                                        </div>
                                        <p>Inbox triaged. 5 urgent plumbing enquiries flagged for review.</p>
                                        <small>2 hot water faults, 1 blocked drain, 2 quoting requests</small>
                                    </div>
                                </article>
                                <article class="preview-item">
                                    <div class="preview-item-icon">S</div>
                                    <div class="preview-item-copy">
                                        <div class="preview-item-meta">
                                            <strong>Sync360</strong>
                                            <span>APP</span>
                                            <time>8:04 AM</time>
                                        </div>
                                        <p>Emergency hot water call booked for 10:00 AM with address confirmed.</p>
                                        <small>Customer accepted the first available slot</small>
                                    </div>
                                </article>
                                <article class="preview-item">
                                    <div class="preview-item-icon">S</div>
                                    <div class="preview-item-copy">
                                        <div class="preview-item-meta">
                                            <strong>Sync360</strong>
                                            <span>APP</span>
                                            <time>8:09 AM</time>
                                        </div>
                                        <p>Two quote follow-ups sent. One customer has already replied.</p>
                                        <small>Bathroom renovation lead reopened</small>
                                    </div>
                                </article>
                            </div>

                            <div class="preview-composer">
                                <span>Reply to #ops-updates</span>
                                <button type="button" class="preview-send">Review queue</button>
                            </div>
                        </div>
                    </div>
                </div>
            </x-slot:visual>
        </x-marketing.hero>

        <div class="section-connector" aria-hidden="true">
            <div class="section-connector-line"></div>
            <div class="section-connector-dot section-connector-dot--active"></div>
            <div class="section-connector-line"></div>
        </div>

        <section class="section-shell">
            <div class="inner">
                <div class="outcome-strip">
                    <x-marketing.outcome-card eyebrow="More booked work" title="Reply before the lead goes cold">
                        Every missed message is a chance for someone else to win the job. Sync360 keeps the conversation alive.
                    </x-marketing.outcome-card>
                    <x-marketing.outcome-card eyebrow="Less admin drag" title="Quotes, follow-ups, and bookings off your plate">
                        Customers get a smoother experience without you spending your evening inside your phone.
                    </x-marketing.outcome-card>
                    <x-marketing.outcome-card eyebrow="More consistent service" title="Your business sounds sharp every time">
                        Friendly replies, clear next steps, and fewer dropped balls when things get busy.
                    </x-marketing.outcome-card>
                </div>
            </div>
        </section>

        <div class="section-connector" aria-hidden="true">
            <div class="section-connector-line"></div>
            <div class="section-connector-dot section-connector-dot--active"></div>
            <div class="section-connector-line"></div>
        </div>

        <section class="section-shell trust-section">
            <div class="inner">
                <x-marketing.stat-bar :stats="[
                    ['value' => '127', 'label' => 'Service businesses using Sync360'],
                    ['value' => '2,847', 'label' => 'Enquiries handled last month'],
                    ['value' => '89%', 'label' => 'Leads converted to bookings'],
                ]" />
            </div>
        </section>

        <div class="section-connector" aria-hidden="true">
            <div class="section-connector-line"></div>
            <div class="section-connector-dot section-connector-dot--active"></div>
            <div class="section-connector-line"></div>
        </div>

        <section class="section-shell how-it-fits-section">
            <div class="inner how-it-fits-inner">
                <div class="how-it-fits-header">
                    <span class="eyebrow type-kicker"><span class="eyebrow-dot"></span> How it fits</span>
                    <h2 class="type-h2">Built for teams that are great at the work but buried in the back-and-forth.</h2>
                    <p class="type-body-lg">
                        Sync360 is designed for trades, clinics, studios, agencies, and service operators who lose time
                        to repetitive messages, quoting, scheduling, and chasing follow-ups.
                    </p>
                </div>

                <div class="flow-diagram flow-diagram--columns">
                    <div class="flow-step flow-step--column">
                        <div class="flow-step-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                                <polyline points="22,6 12,13 2,6"></polyline>
                            </svg>
                        </div>
                        <div class="flow-step-content">
                            <strong class="flow-step-title">Capture the enquiry</strong>
                            <p class="flow-step-desc">Website forms, WhatsApp messages, or email conversations are picked up instantly.</p>
                        </div>
                    </div>

                    <div class="flow-step flow-step--column">
                        <div class="flow-step-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="3"></circle>
                                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
                            </svg>
                        </div>
                        <div class="flow-step-content">
                            <strong class="flow-step-title">Move the customer forward</strong>
                            <p class="flow-step-desc">Sync360 asks the right questions, confirms the next step, and keeps momentum going.</p>
                        </div>
                    </div>

                    <div class="flow-step flow-step--column flow-step--last">
                        <div class="flow-step-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                <polyline points="22 4 12 14.01 9 11.01"></polyline>
                            </svg>
                        </div>
                        <div class="flow-step-content">
                            <strong class="flow-step-title">Hand your team clean context</strong>
                            <p class="flow-step-desc">The job, customer summary, and recommended action are already laid out before anyone jumps in.</p>
                        </div>
                    </div>
                </div>

                <div class="value-grid value-grid--metric-first">
                    <div class="value-block value-block--metric-first">
                        <div class="value-metric value-metric--hero">
                            <strong class="metric-value metric-value--large" data-count-to="90" data-suffix=" sec">~90 sec</strong>
                            <span class="metric-label">Average response time</span>
                        </div>
                        <div class="value-content">
                            <small class="type-label">Response time</small>
                            <h3 class="type-h3">Replies in under 2 minutes, 24/7.</h3>
                            <p class="type-body">89% of enquiries get an instant response. Customers stop shopping around when you reply before the lead goes cold.</p>
                        </div>
                    </div>
                    <div class="value-block value-block--metric-first">
                        <div class="value-metric value-metric--hero">
                            <strong class="metric-value metric-value--large" data-count-to="10" data-suffix=" hrs">~10 hrs</strong>
                            <span class="metric-label">Admin time saved weekly</span>
                        </div>
                        <div class="value-content">
                            <small class="type-label">Admin reduction</small>
                            <h3 class="type-h3">80% of enquiries handled automatically.</h3>
                            <p class="type-body">Sync360 qualifies, books, and follows up without copy-pasting or late-night admin. Your team gets evenings back.</p>
                        </div>
                    </div>
                    <div class="value-block value-block--metric-first">
                        <div class="value-metric value-metric--hero">
                            <strong class="metric-value metric-value--large" data-count-to="89" data-suffix="%">89%</strong>
                            <span class="metric-label">Lead-to-booking rate</span>
                        </div>
                        <div class="value-content">
                            <small class="type-label">Conversion lift</small>
                            <h3 class="type-h3">89% of leads convert to booked jobs.</h3>
                            <p class="type-body">Handle 3x more enquiries with the same team. Scale capacity before you need to hire more support staff.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <div class="section-connector" aria-hidden="true">
            <div class="section-connector-line"></div>
            <div class="section-connector-dot section-connector-dot--active"></div>
            <div class="section-connector-line"></div>
        </div>

        <section class="section-shell testimonials-section">
            <div class="inner">
                <div class="testimonials-header">
                    <span class="eyebrow type-kicker"><span class="eyebrow-dot"></span> Real results</span>
                    <h2 class="type-h2">Trusted by service businesses across the country.</h2>
                </div>

                <div class="testimonials-grid">
                    <x-marketing.testimonial-card
                        quote="We were losing 3-4 jobs a week from missed calls after hours. Sync360 caught them all. First month: 18 extra bookings, paid for itself 10 times over."
                        name="Matt Johnson"
                        role="Northfield Plumbing, Owner"
                        initials="MJ"
                        metric-label="Extra bookings in month 1"
                        metric-value="+18"
                        count-to="18"
                        prefix="+"
                    />

                    <x-marketing.testimonial-card
                        quote="I was spending 2 hours every evening replying to enquiries. Sync360 handles 80% of them automatically. I get my evenings back and customers get faster replies."
                        name="Sarah Kim"
                        role="Apex Electrical, Office Manager"
                        initials="SK"
                        metric-label="Admin time saved weekly"
                        metric-value="10 hrs"
                        count-to="10"
                        suffix=" hrs"
                    />

                    <x-marketing.testimonial-card
                        quote="The quote follow-ups alone are worth it. Sync360 reopened $47k in dead leads over 3 months. Customers said we were the only ones who followed up properly."
                        name="David Torres"
                        role="Torres Roofing, Director"
                        initials="DT"
                        metric-label="Recovered revenue (3 months)"
                        metric-value="$47k"
                        count-to="47"
                        prefix="$"
                        suffix="k"
                    />
                </div>
            </div>
        </section>

        <section class="final-cta">
            <div class="final-cta-content">
                <div>
                    <span class="eyebrow type-kicker"><span class="eyebrow-dot"></span> Ready to try it?</span>
                    <h2 class="type-h2">See what your business feels like when the inbox stops slowing you down.</h2>
                    <p class="type-body-lg">Start a free trial, load in your business details, and watch Sync360 turn customer conversations into booked work.</p>
                    
                    <div class="final-cta-proof">
                        <div class="proof-item">
                            <svg class="proof-check" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                            </svg>
                            <span>14-day free trial</span>
                        </div>
                        <div class="proof-item">
                            <svg class="proof-check" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                            </svg>
                            <span>No credit card required</span>
                        </div>
                        <div class="proof-item">
                            <svg class="proof-check" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                            </svg>
                            <span>Cancel anytime</span>
                        </div>
                    </div>
                </div>

                <div class="final-actions">
                    <x-marketing.cta-group
                        :primary-href="route('signup')"
                        primary-label="Start Free Trial &rarr;"
                        :secondary-href="route('login')"
                        secondary-label="Already a customer?"
                    />
                </div>
            </div>
        </section>
    </div>
</x-layouts.guest>
