<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Sync360' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,700;1,9..40,400&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f7f5f3;
            --panel: #ffffff;
            --ink: #1a1a1a;
            --muted: #6b7280;
            --accent: #FF6B35;
            --accent-dark: #e55a25;
            --stroke: #e5e7eb;
            --warn: #8a5a00;
            --danger: #a13333;
            --danger-bg: #fff0f0;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "DM Sans", "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top right, rgba(255, 107, 53, 0.06), transparent 30%),
                linear-gradient(180deg, #ffffff 0%, var(--bg) 60%, #f0ede9 100%);
            color: var(--ink);
        }
        a { color: inherit; text-decoration: none; }
        .shell { min-height: 100vh; display: flex; flex-direction: column; }

        /* ── Navigation ── */
        .nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px 28px;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            font-size: 1.1rem;
            letter-spacing: -0.01em;
            color: var(--ink);
        }
        .brand-dot {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .brand-dot svg { width: 16px; height: 16px; }
        .nav-links {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        .link {
            color: var(--muted);
            font-size: 0.95rem;
            font-weight: 500;
            transition: color 0.15s;
        }
        .link:hover { color: var(--ink); }

        /* ── Buttons ── */
        .button, button {
            border: 0;
            border-radius: 999px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font: inherit;
            font-weight: 600;
            padding: 12px 22px;
            transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
            text-decoration: none;
        }
        .button:hover, button:hover {
            transform: translateY(-2px);
        }
        .button--primary, button {
            background: var(--accent);
            color: white;
            box-shadow: 0 8px 24px rgba(255, 107, 53, 0.30);
        }
        .button--primary:hover, button:hover {
            background: var(--accent-dark);
            box-shadow: 0 12px 32px rgba(255, 107, 53, 0.38);
        }
        .button--secondary {
            background: white;
            color: var(--ink);
            border: 1.5px solid var(--stroke);
            box-shadow: none;
        }
        .button--secondary:hover {
            border-color: #c9cbd0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        /* ── Layout helpers ── */
        .container {
            width: min(1120px, calc(100% - 32px));
            margin: 0 auto;
        }
        .hero {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 28px;
            padding: 32px 0 64px;
            align-items: center;
        }
        .hero-card, .panel {
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid rgba(229, 231, 235, 0.9);
            border-radius: 28px;
            box-shadow: 0 20px 60px rgba(26, 26, 26, 0.07);
        }
        .hero-copy {
            padding: 40px;
        }

        /* ── Eyebrow badge ── */
        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 107, 53, 0.10);
            color: var(--accent-dark);
            border-radius: 999px;
            padding: 8px 14px;
            font-size: 0.82rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            font-family: "Space Mono", monospace;
        }
        .eyebrow-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--accent);
            display: inline-block;
        }

        /* ── Typography ── */
        h1, h2, h3 { margin: 0; line-height: 1.05; letter-spacing: -0.02em; }
        p { color: var(--muted); line-height: 1.6; }
        .hero-copy h1 {
            margin-top: 20px;
            font-size: clamp(2.5rem, 6vw, 4.4rem);
            max-width: 14ch;
            letter-spacing: -0.03em;
        }
        .hero-copy p {
            margin: 18px 0 30px;
            font-size: 1.05rem;
            max-width: 52ch;
            line-height: 1.65;
        }

        /* ── Hero stats ── */
        .hero-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-top: 28px;
        }
        .stat {
            padding: 16px 18px;
            border-radius: 18px;
            background: #faf9f8;
            border: 1px solid var(--stroke);
        }
        .stat strong {
            display: block;
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--ink);
            margin-top: 4px;
        }

        /* ── Hero visual ── */
        .hero-visual {
            padding: 28px;
            display: grid;
            gap: 16px;
            position: relative;
            overflow: hidden;
        }
        .hero-visual::before {
            content: "";
            position: absolute;
            inset: auto -80px -80px auto;
            width: 240px;
            height: 240px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255, 107, 53, 0.14), transparent 70%);
            pointer-events: none;
        }
        .mini-panel {
            position: relative;
            padding: 22px;
            border-radius: 22px;
            background: #1A1A1A;
            color: white;
            overflow: hidden;
        }
        .mini-panel::before {
            content: "";
            position: absolute;
            top: -40px; right: -40px;
            width: 140px; height: 140px;
            border-radius: 50%;
            background: rgba(255, 107, 53, 0.15);
            pointer-events: none;
        }
        .mini-panel small {
            color: rgba(255,255,255,0.55);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-size: 0.75rem;
            font-family: "Space Mono", monospace;
        }
        .mini-panel strong { display: block; margin-top: 10px; font-size: 1.3rem; line-height: 1.2; }
        .mini-panel p { color: rgba(255,255,255,0.75); margin: 10px 0 0; font-size: 0.92rem; }
        .mini-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }
        .mini-tile {
            padding: 18px;
            border-radius: 18px;
            background: #faf9f8;
            border: 1px solid var(--stroke);
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .mini-tile:hover {
            border-color: rgba(255, 107, 53, 0.30);
            box-shadow: 0 4px 16px rgba(255, 107, 53, 0.08);
        }

        /* ── Auth pages ── */
        .auth-wrap {
            display: grid;
            grid-template-columns: 1fr min(520px, 100%);
            gap: 24px;
            padding: 28px 0 64px;
            align-items: start;
        }
        .auth-aside { padding: 32px; }
        .auth-card  { padding: 36px; }
        form { display: grid; gap: 16px; }
        .field-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        label {
            display: grid;
            gap: 6px;
            color: var(--ink);
            font-size: 0.9rem;
            font-weight: 600;
        }
        input, select {
            width: 100%;
            border-radius: 14px;
            border: 1.5px solid var(--stroke);
            padding: 13px 16px;
            background: #fafafa;
            font: inherit;
            color: var(--ink);
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        input:focus, select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.12);
            background: white;
        }
        input::placeholder { color: #adb5bd; }

        /* ── Utility ── */
        .hint { font-size: 0.875rem; color: var(--muted); }
        .alert {
            padding: 14px 16px;
            border-radius: 14px;
            font-size: 0.95rem;
        }
        .alert--success {
            background: rgba(255, 107, 53, 0.07);
            color: var(--accent-dark);
            border: 1px solid rgba(255, 107, 53, 0.20);
        }
        .alert--error {
            background: var(--danger-bg);
            color: var(--danger);
            border: 1px solid rgba(161, 51, 51, 0.20);
        }
        .list { padding-left: 18px; margin: 10px 0 0; }
        .list li { margin-bottom: 6px; }

        /* ── Footer ── */
        footer {
            padding: 32px 28px 40px;
            color: var(--muted);
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }
        footer span { opacity: 0.6; }

        /* ── Responsive ── */
        @media (max-width: 900px) {
            .hero, .auth-wrap { grid-template-columns: 1fr; }
            .field-grid, .hero-stats, .mini-grid { grid-template-columns: 1fr; }
            .nav { padding-inline: 18px; }
        }
    </style>
</head>
<body>
<div class="shell">
    <nav class="nav container">
        <a href="{{ route('landing') }}" class="brand">
            <div class="brand-dot">
                <svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M8 2C4.686 2 2 4.686 2 8s2.686 6 6 6 6-2.686 6-6-2.686-6-6-6zm0 10a4 4 0 110-8 4 4 0 010 8z" fill="white" opacity="0.5"/>
                    <circle cx="8" cy="8" r="2.5" fill="white"/>
                </svg>
            </div>
            Sync360
        </a>
        <div class="nav-links">
            <a href="{{ route('login') }}" class="link">Log In</a>
            <a href="{{ route('signup') }}" class="button button--primary">Start Free Trial</a>
        </div>
    </nav>

    <main class="container" style="flex: 1;">
        @if (session('status'))
            <div class="alert alert--success" style="margin-bottom: 18px;">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert--error" style="margin-bottom: 18px;">
                <strong>Please fix the following:</strong>
                <ul class="list">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{ $slot }}
    </main>

    <footer class="container">
        <span>© {{ date('Y') }} Sync360. All rights reserved.</span>
        <span>Your AI-powered digital employee.</span>
    </footer>
</div>
</body>
</html>
