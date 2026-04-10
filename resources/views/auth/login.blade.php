<x-layouts.guest title="Log In — Sync360">
    <section class="auth-wrap" style="grid-template-columns: 1fr min(460px, 100%);">
        <div class="hero-card auth-aside">
            <span class="eyebrow"><span class="eyebrow-dot"></span> Welcome Back</span>
            <h2 style="margin-top: 18px; font-size: clamp(2rem, 4vw, 3rem);">Your digital employee has been busy.</h2>
            <p>
                Log in to see what it's handled for your business &mdash; messages answered,
                bookings confirmed, and quotes sent while you were away.
            </p>
        </div>

        <div class="panel auth-card">
            <h2 style="font-size: 1.7rem;">Log In</h2>
            <p class="hint" style="margin-bottom: 22px;">Good to have you back.</p>

            <form method="POST" action="{{ route('login.store') }}">
                @csrf

                <label>
                    Email Address
                    <input type="email" name="email" value="{{ old('email') }}" placeholder="you@yourbusiness.com" required>
                </label>

                <label>
                    Password
                    <input type="password" name="password" placeholder="Your password" required>
                </label>

                <label style="display: flex; align-items: center; gap: 10px; font-weight: 500; cursor: pointer;">
                    <input type="checkbox" name="remember" value="1" style="width: auto; accent-color: var(--accent);">
                    Keep me signed in
                </label>

                <button type="submit" style="width: 100%; padding: 14px 22px; font-size: 1rem;">
                    Log In &rarr;
                </button>
            </form>

            <p class="hint" style="margin-top: 18px; text-align: center;">
                No account yet? <a href="{{ route('signup') }}" style="color: var(--accent-dark); font-weight: 700;">Start a free trial</a>.
            </p>
        </div>
    </section>
</x-layouts.guest>
