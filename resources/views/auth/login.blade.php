<x-layouts.guest title="Log In — Sync360">
    <section class="auth-wrap auth-wrap--login">
        <div class="hero-card auth-aside auth-story">
            <div>
                <span class="eyebrow"><span class="eyebrow-dot"></span> Welcome back</span>
                <h2>Your workspace kept the conversation moving while you were away.</h2>
                <p>
                    Jump back into the day with customer context, next actions, and a digital employee
                    that has already been handling the busywork in the background.
                </p>
            </div>

            <div class="auth-proof">
                <span>Replies tracked</span>
                <span>Bookings moving</span>
                <span>Follow-ups queued</span>
            </div>

            <div class="auth-metric-grid">
                <div class="auth-metric">
                    <span>Inbox</span>
                    <strong>Prioritised before you log in</strong>
                </div>
                <div class="auth-metric">
                    <span>Customers</span>
                    <strong>Warm replies, even after hours</strong>
                </div>
                <div class="auth-metric">
                    <span>Team</span>
                    <strong>Cleaner handoff every morning</strong>
                </div>
            </div>

            <div class="auth-conversation">
                <div class="auth-conversation-header">
                    <strong>What your workspace feels like</strong>
                    <span>Live context</span>
                </div>
                <div class="auth-bubble auth-bubble--user">
                    "Can someone come by this afternoon for a quote?"
                </div>
                <div class="auth-bubble auth-bubble--ai">
                    "Absolutely. I’ve offered two time windows and captured the job details for your team."
                </div>
                <div class="auth-bubble auth-bubble--user">
                    "Perfect, book the 3:30 slot."
                </div>
            </div>
        </div>

        <div class="panel auth-card">
            <header>
                <span class="kicker">Secure access</span>
                <h2>Log in to your Sync360 workspace</h2>
                <p class="hint">Pick up where you left off and get back to the conversations that matter.</p>
            </header>

            <form method="POST" action="{{ route('login.store') }}">
                @csrf

                <div class="field-single">
                    <label>
                        Email Address
                        <input type="email" name="email" value="{{ old('email') }}" placeholder="you@yourbusiness.com" required>
                    </label>

                    <label>
                        Password
                        <input type="password" name="password" placeholder="Your password" required>
                    </label>
                </div>

                <p class="hint" style="margin-top: -4px; margin-bottom: 4px; text-align: right;">
                    <a href="{{ route('password.request') }}" style="color: var(--accent-dark); font-weight: 700;">Forgot your password?</a>
                </p>

                <label style="display: flex; align-items: center; gap: 10px; font-weight: 500; cursor: pointer;">
                    <input type="checkbox" name="remember" value="1" style="width: auto; accent-color: var(--accent);">
                    Keep me signed in
                </label>

                <button type="submit" style="width: 100%; padding: 14px 22px; font-size: 1rem;">
                    Log In &rarr;
                </button>
            </form>

            <p class="hint" style="margin-top: 18px; text-align: center;">
                No account yet? <a href="{{ route('signup') }}" style="color: var(--accent-dark); font-weight: 700;">Start your free trial</a>.
            </p>
        </div>
    </section>
</x-layouts.guest>
