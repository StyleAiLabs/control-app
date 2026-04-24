<x-layouts.guest title="Start Free Trial — Sync360">
    <section class="auth-wrap">
        <div class="hero-card auth-aside auth-story">
            <div>
                <span class="eyebrow"><span class="eyebrow-dot"></span> Free trial · no credit card needed</span>
                <h2>Set up the workspace that keeps enquiries moving for you.</h2>
                <p>
                    Give us a few details about your business and we will spin up a workspace that is tailored
                    to your industry, your workflow, and the way customers already reach out.
                </p>
            </div>

            <div class="auth-proof">
                <span>Tailored to your industry</span>
                <span>Ready in minutes</span>
                <span>Built for busy operators</span>
            </div>

            <div class="mini-grid">
                <div class="mini-tile">
                    <div class="hint">Industry fit</div>
                    <h3>Start with the right context</h3>
                    <p>We load a workspace that matches how your business actually sells, books, and follows up.</p>
                </div>
                <div class="mini-tile">
                    <div class="hint">Fast launch</div>
                    <h3>Go live without a long setup project</h3>
                    <p>Your workspace starts provisioning as soon as you create the account.</p>
                </div>
            </div>

            <div class="auth-conversation">
                <div class="auth-conversation-header">
                    <strong>The experience you are creating</strong>
                    <span>Customer preview</span>
                </div>
                <div class="auth-bubble auth-bubble--user">
                    "Do you have any appointments left this week?"
                </div>
                <div class="auth-bubble auth-bubble--ai">
                    "Yes. I can offer Thursday afternoon or Friday morning. Which suits you best?"
                </div>
                <div class="auth-bubble auth-bubble--user">
                    "Friday morning works. Please lock it in."
                </div>
            </div>
        </div>

        <div class="panel auth-card">
            <header>
                <span class="kicker type-kicker">Create workspace</span>
                <h2 class="type-section-title">Start your free trial</h2>
                <p class="hint type-muted">Tell us a bit about your business and we will get your workspace ready.</p>
            </header>

            <form method="POST" action="{{ route('signup.store') }}">
                @csrf

                <div class="field-grid">
                    <label>
                        Business Name
                        <input type="text" name="business_name" value="{{ old('business_name') }}" placeholder="e.g. Acme Plumbing" required>
                    </label>
                    <label>
                        Your Name
                        <input type="text" name="contact_name" value="{{ old('contact_name') }}" placeholder="e.g. Jane Smith" required>
                    </label>
                </div>

                <div class="field-grid">
                    <label>
                        Email Address
                        <input type="email" name="email" value="{{ old('email') }}" placeholder="you@yourbusiness.com" required>
                    </label>
                    <label>
                        Phone <span style="font-weight: 400; color: var(--muted);">(optional)</span>
                        <input type="text" name="phone" value="{{ old('phone') }}" placeholder="+64 21 000 0000">
                    </label>
                </div>

                <div class="field-grid">
                    <label>
                        Industry
                        <select name="industry" required>
                            <option value="">What industry are you in?</option>
                            @foreach (['Healthcare', 'Professional Services', 'Retail', 'Trades', 'Education', 'Hospitality'] as $industry)
                                <option value="{{ $industry }}" @selected(old('industry') === $industry)>{{ $industry }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <div class="field-grid">
                    <label>
                        Password
                        <input type="password" name="password" placeholder="At least 8 characters" required>
                    </label>
                    <label>
                        Confirm Password
                        <input type="password" name="password_confirmation" placeholder="Same password again" required>
                    </label>
                </div>

                <button type="submit" style="width: 100%; padding: 14px 22px; font-size: 1rem;">
                    Set Up My Digital Employee &rarr;
                </button>
            </form>

            <p class="hint" style="margin-top: 18px; text-align: center;">
                Already have an account? <a href="{{ route('login') }}" style="color: var(--accent-dark); font-weight: 700;">Log in</a>.
            </p>
        </div>
    </section>
</x-layouts.guest>
