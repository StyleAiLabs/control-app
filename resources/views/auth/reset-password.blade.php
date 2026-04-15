<x-layouts.guest title="Choose New Password — Sync360">
    <section class="auth-wrap auth-wrap--login">
        <div class="hero-card auth-aside auth-story">
            <div>
                <span class="eyebrow"><span class="eyebrow-dot"></span> New password</span>
                <h2>Set a fresh password and get back to the conversations waiting for you.</h2>
                <p>
                    This completes the secure reset flow. Choose a new password with at least 8 characters,
                    then log back into your Sync360 workspace as usual.
                </p>
            </div>

            <div class="auth-proof">
                <span>Same workspace</span>
                <span>New credentials</span>
                <span>Quick return to work</span>
            </div>

            <div class="auth-metric-grid">
                <div class="auth-metric">
                    <span>Security</span>
                    <strong>Reset token validated</strong>
                </div>
                <div class="auth-metric">
                    <span>Password</span>
                    <strong>Updated immediately</strong>
                </div>
                <div class="auth-metric">
                    <span>Next step</span>
                    <strong>Log back in with confidence</strong>
                </div>
            </div>

            <div class="auth-conversation">
                <div class="auth-conversation-header">
                    <strong>Recovery complete</strong>
                    <span>Secure access</span>
                </div>
                <div class="auth-bubble auth-bubble--user">
                    "I’ve opened the reset link."
                </div>
                <div class="auth-bubble auth-bubble--ai">
                    "Great. Set the new password you want to use for future logins."
                </div>
                <div class="auth-bubble auth-bubble--user">
                    "Done. I’m ready to sign in again."
                </div>
            </div>
        </div>

        <div class="panel auth-card">
            <header>
                <span class="kicker">Complete reset</span>
                <h2>Choose your new password</h2>
                <p class="hint">Use the same email address the reset link was sent to.</p>
            </header>

            <form method="POST" action="{{ route('password.update') }}">
                @csrf

                <input type="hidden" name="token" value="{{ $token }}">

                <div class="field-single">
                    <label>
                        Email Address
                        <input type="email" name="email" value="{{ old('email', $email) }}" placeholder="you@yourbusiness.com" required>
                    </label>

                    <label>
                        New Password
                        <input type="password" name="password" placeholder="At least 8 characters" required>
                    </label>

                    <label>
                        Confirm New Password
                        <input type="password" name="password_confirmation" placeholder="Type it again" required>
                    </label>
                </div>

                <button type="submit" style="width: 100%; padding: 14px 22px; font-size: 1rem;">
                    Save New Password &rarr;
                </button>
            </form>

            <p class="hint" style="margin-top: 18px; text-align: center;">
                Need to restart? <a href="{{ route('password.request') }}" style="color: var(--accent-dark); font-weight: 700;">Request another link</a>.
            </p>
        </div>
    </section>
</x-layouts.guest>
