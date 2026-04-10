<x-layouts.guest title="Start Free Trial — Sync360">
    <section class="auth-wrap">
        <div class="hero-card auth-aside">
            <span class="eyebrow"><span class="eyebrow-dot"></span> Free Trial &middot; No credit card needed</span>
            <h2 style="margin-top: 18px; font-size: clamp(2rem, 4vw, 3rem);">Meet your new digital employee.</h2>
            <p>
                Answer messages, book jobs, and send quotes &mdash; automatically.
                Takes less than 2 minutes to set up. Cancel any time.
            </p>
            <div class="mini-grid">
                <div class="mini-tile">
                    <div class="hint">Tailored to your trade</div>
                    <h3 style="margin-top: 10px;">Built for your industry</h3>
                    <p>We load skills matched to how your business actually works.</p>
                </div>
                <div class="mini-tile">
                    <div class="hint">Ready in minutes</div>
                    <h3 style="margin-top: 10px;">Up and running fast</h3>
                    <p>Your digital employee starts setting up the moment you sign up.</p>
                </div>
            </div>
        </div>

        <div class="panel auth-card">
            <h2 style="font-size: 1.7rem;">Start Free Trial</h2>
            <p class="hint" style="margin-bottom: 22px;">Tell us a bit about your business to get started.</p>

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
                    <label>
                        Skill Pack
                        <select name="skill_pack" required>
                            <option value="">What do you need most help with?</option>
                            @foreach (['Operations Core', 'Sales Assist', 'Client Support', 'Bookings + Scheduling', 'Back Office'] as $skillPack)
                                <option value="{{ $skillPack }}" @selected(old('skill_pack') === $skillPack)>{{ $skillPack }}</option>
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
