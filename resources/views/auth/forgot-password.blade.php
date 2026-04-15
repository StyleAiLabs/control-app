<x-layouts.guest title="Reset Password — Sync360">
    <section class="auth-wrap auth-wrap--login">
        <div class="hero-card auth-aside auth-story">
            <div>
                <span class="eyebrow"><span class="eyebrow-dot"></span> Recover access</span>
                <h2>Get back into your workspace without waiting on manual support.</h2>
                <p>
                    Enter the email tied to your Sync360 account and we will send a secure password reset link
                    through the existing email delivery setup.
                </p>
            </div>

            <div class="auth-proof">
                <span>Self-serve recovery</span>
                <span>Secure reset token</span>
                <span>Same account, fresh password</span>
            </div>

            <div class="auth-metric-grid">
                <div class="auth-metric">
                    <span>Access</span>
                    <strong>Recover login in a few minutes</strong>
                </div>
                <div class="auth-metric">
                    <span>Security</span>
                    <strong>Short-lived reset token</strong>
                </div>
                <div class="auth-metric">
                    <span>Workflow</span>
                    <strong>No support queue needed</strong>
                </div>
            </div>

            <div class="auth-conversation">
                <div class="auth-conversation-header">
                    <strong>What happens next</strong>
                    <span>Recovery path</span>
                </div>
                <div class="auth-bubble auth-bubble--user">
                    "I can’t remember my password."
                </div>
                <div class="auth-bubble auth-bubble--ai">
                    "No problem. We’ll send a secure reset link to your account email."
                </div>
                <div class="auth-bubble auth-bubble--user">
                    "Perfect, I’ll update it and log back in."
                </div>
            </div>
        </div>

        <div class="panel auth-card">
            <header>
                <span class="kicker">Password recovery</span>
                <h2>Send me a reset link</h2>
                <p class="hint">Use the email address you log in with and we’ll email the next step.</p>
            </header>

            <form method="POST" action="{{ route('password.email') }}">
                @csrf

                <div class="field-single">
                    <label>
                        Email Address
                        <input type="email" name="email" value="{{ old('email') }}" placeholder="you@yourbusiness.com" required>
                    </label>
                </div>

                <button type="submit" style="width: 100%; padding: 14px 22px; font-size: 1rem;">
                    Email Reset Link &rarr;
                </button>
            </form>

            <p class="hint" style="margin-top: 18px; text-align: center;">
                Remembered it? <a href="{{ route('login') }}" style="color: var(--accent-dark); font-weight: 700;">Back to log in</a>.
            </p>
        </div>
    </section>
</x-layouts.guest>
