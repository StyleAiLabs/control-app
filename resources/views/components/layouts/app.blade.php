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
            --warning: #9c6a08;
            --danger: #9f3737;
            --danger-bg: #fff1f1;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "DM Sans", "Segoe UI", sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at top left, rgba(255, 107, 53, 0.05), transparent 28%),
                linear-gradient(180deg, #ffffff 0%, var(--bg) 100%);
        }
        a { color: inherit; text-decoration: none; }

        /* ── Shell ── */
        .shell {
            min-height: 100vh;
            display: grid;
            grid-template-columns: 248px 1fr;
        }

        /* ── Sidebar ── */
        .sidebar {
            background: #1A1A1A;
            color: white;
            padding: 28px 16px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
        }
        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 4px 10px 18px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            margin-bottom: 8px;
        }
        .sidebar-brand-dot {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            background: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .sidebar-brand-dot svg { width: 16px; height: 16px; }
        .sidebar-brand-text h1 {
            margin: 0;
            font-size: 1rem;
            font-weight: 700;
            letter-spacing: -0.01em;
            line-height: 1.2;
        }
        .sidebar-brand-text p {
            margin: 2px 0 0;
            color: rgba(255,255,255,0.45);
            font-size: 0.75rem;
            line-height: 1.3;
        }
        .nav-section-label {
            padding: 6px 12px 4px;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(255,255,255,0.30);
            font-family: "Space Mono", monospace;
        }
        .nav-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 10px;
            color: rgba(255,255,255,0.65);
            font-weight: 500;
            font-size: 0.92rem;
            transition: background 0.15s, color 0.15s, border-color 0.15s;
            border-left: 3px solid transparent;
        }
        .nav-link:hover {
            background: rgba(255,255,255,0.07);
            color: rgba(255,255,255,0.90);
            border-left-color: rgba(255,255,255,0.15);
        }
        .nav-link.active {
            background: rgba(255, 107, 53, 0.12);
            color: white;
            border-left-color: var(--accent);
            font-weight: 600;
        }

        /* ── Sidebar footer ── */
        .sidebar-footer {
            margin-top: auto;
            padding-top: 16px;
            border-top: 1px solid rgba(255,255,255,0.08);
        }
        .sidebar-user {
            padding: 10px 12px;
            border-radius: 10px;
            background: rgba(255,255,255,0.05);
            margin-bottom: 10px;
        }
        .sidebar-user-name  { font-weight: 600; font-size: 0.92rem; }
        .sidebar-user-email { color: rgba(255,255,255,0.55); font-size: 0.80rem; margin-top: 2px; }
        .sidebar-user-role  { color: var(--accent); font-size: 0.74rem; font-weight: 700; margin-top: 4px; font-family: "Space Mono", monospace; text-transform: uppercase; letter-spacing: 0.05em; }

        /* ── Main content ── */
        .content { padding: 32px; }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 28px;
            gap: 16px;
        }
        .topbar h2 {
            margin: 0;
            font-size: clamp(1.7rem, 4vw, 2.2rem);
            letter-spacing: -0.02em;
        }
        .topbar p {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 0.95rem;
        }

        /* ── Panels / Cards ── */
        .panel {
            background: rgba(255,255,255,0.97);
            border: 1px solid rgba(229, 231, 235, 0.90);
            border-radius: 20px;
            box-shadow: 0 4px 24px rgba(26, 26, 26, 0.05);
            padding: 24px;
        }
        .grid   { display: grid; gap: 18px; }
        .grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }

        /* ── Stats ── */
        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }
        .stat {
            padding: 20px;
            border-radius: 16px;
            background: #faf9f8;
            border: 1px solid var(--stroke);
        }
        .stat strong {
            display: block;
            font-size: 1.3rem;
            font-weight: 700;
            margin-top: 8px;
            color: var(--ink);
        }

        /* ── Eyebrow badge ── */
        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 999px;
            background: rgba(255, 107, 53, 0.10);
            color: var(--accent-dark);
            font-weight: 700;
            font-size: 0.74rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-family: "Space Mono", monospace;
        }

        /* ── Status badges ── */
        .badge {
            display: inline-flex;
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 700;
            font-family: "Space Mono", monospace;
            letter-spacing: 0.02em;
        }
        .badge.pending, .badge.queued       { background: #fef3c7; color: #92400e; }
        .badge.provisioning, .badge.running { background: #dbeafe; color: #1d4ed8; }
        .badge.ready, .badge.completed, .badge.trial_active { background: #dcfce7; color: #15803d; }
        .badge.failed, .badge.trial_expired { background: var(--danger-bg); color: var(--danger); }

        /* ── Tables ── */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.93rem; }
        th, td {
            padding: 13px 14px;
            border-bottom: 1px solid var(--stroke);
            text-align: left;
            vertical-align: top;
        }
        th {
            color: var(--muted);
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-family: "Space Mono", monospace;
            background: #faf9f8;
        }
        tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: rgba(255, 107, 53, 0.03); }
        .clickable-row { cursor: pointer; }

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
            font-size: 0.9rem;
            padding: 10px 20px;
            transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
            text-decoration: none;
        }
        .button:hover, button:hover { transform: translateY(-1px); }
        .button--primary, button {
            background: var(--accent);
            color: white;
            box-shadow: 0 6px 20px rgba(255, 107, 53, 0.28);
        }
        .button--primary:hover, button:hover {
            background: var(--accent-dark);
            box-shadow: 0 10px 28px rgba(255, 107, 53, 0.35);
        }
        .button--secondary {
            background: white;
            border: 1.5px solid var(--stroke);
            color: var(--ink);
            box-shadow: none;
        }
        .button--secondary:hover {
            border-color: #c9cbd0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        .button--ghost {
            background: rgba(255,255,255,0.08);
            color: rgba(255,255,255,0.80);
            border: 1px solid rgba(255,255,255,0.12);
            box-shadow: none;
            font-size: 0.85rem;
            padding: 8px 16px;
        }
        .button--ghost:hover {
            background: rgba(255,255,255,0.14);
            color: white;
            transform: none;
        }
        .button--danger {
            background: var(--danger);
            color: white;
            box-shadow: 0 6px 20px rgba(159, 55, 55, 0.28);
        }
        .button--danger:hover {
            background: #872f2f;
            box-shadow: 0 10px 28px rgba(159, 55, 55, 0.35);
        }
        .button--danger:disabled {
            background: #d8b9b9;
            box-shadow: none;
            cursor: not-allowed;
        }

        /* ── Meta items ── */
        .meta {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .meta-item {
            padding: 14px 16px;
            border-radius: 14px;
            background: #faf9f8;
            border: 1px solid var(--stroke);
        }
        .meta-item small {
            display: block;
            color: var(--muted);
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-size: 0.72rem;
            font-weight: 700;
            font-family: "Space Mono", monospace;
        }

        /* ── Notes / alerts ── */
        .note {
            padding: 14px 16px;
            border-radius: 14px;
            background: rgba(255, 107, 53, 0.07);
            color: var(--accent-dark);
            border: 1px solid rgba(255, 107, 53, 0.18);
            font-size: 0.93rem;
            line-height: 1.55;
        }
        .note.error {
            background: var(--danger-bg);
            color: var(--danger);
            border-color: rgba(159, 55, 55, 0.18);
        }
        .danger-panel {
            border-color: rgba(159, 55, 55, 0.24);
            box-shadow: 0 4px 24px rgba(159, 55, 55, 0.06);
        }

        /* ── Progress bar ── */
        .progress-bar {
            height: 10px;
            border-radius: 999px;
            background: #f0ede9;
            overflow: hidden;
        }
        .progress-bar span {
            display: block;
            height: 100%;
            background: linear-gradient(90deg, var(--accent) 0%, var(--accent-dark) 100%);
            width: 45%;
            animation: pulse 1.4s ease-in-out infinite alternate;
        }
        @keyframes pulse {
            from { transform: translateX(-15%); opacity: 0.75; }
            to   { transform: translateX(20%);  opacity: 1; }
        }

        /* ── Misc ── */
        .hint { font-size: 0.875rem; color: var(--muted); }
        form.inline { display: inline; }
        form {
            display: grid;
            gap: 16px;
        }
        .field-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .field-single {
            display: grid;
            gap: 14px;
        }
        label {
            display: grid;
            gap: 6px;
            color: var(--ink);
            font-size: 0.9rem;
            font-weight: 600;
        }
        input, select, textarea {
            width: 100%;
            border-radius: 14px;
            border: 1.5px solid rgba(17, 15, 13, 0.12);
            padding: 13px 16px;
            background: #fcfaf8;
            font: inherit;
            color: var(--ink);
            transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
        }
        textarea {
            min-height: 120px;
            resize: vertical;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.14);
            background: white;
        }
        input::placeholder,
        textarea::placeholder { color: #a29c97; }

        /* ── Responsive ── */
        @media (max-width: 980px) {
            .shell { grid-template-columns: 1fr; }
            .sidebar { height: auto; position: static; flex-direction: row; flex-wrap: wrap; padding: 16px; }
            .sidebar-brand { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
            .sidebar-footer { display: none; }
            .stats, .grid-2, .meta, .field-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <div class="sidebar-brand">
            <div class="sidebar-brand-dot">
                <svg viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M8 2C4.686 2 2 4.686 2 8s2.686 6 6 6 6-2.686 6-6-2.686-6-6-6zm0 10a4 4 0 110-8 4 4 0 010 8z" fill="white" opacity="0.5"/>
                    <circle cx="8" cy="8" r="2.5" fill="white"/>
                </svg>
            </div>
            <div class="sidebar-brand-text">
                <h1>Sync360</h1>
                <p>Your digital employee</p>
            </div>
        </div>

        <nav>
            <div class="nav-section-label">Menu</div>
            <a href="{{ auth()->user()->is_admin && ! auth()->user()->tenant ? route('admin.index') : route('dashboard') }}"
               class="nav-link {{ request()->routeIs('dashboard') || request()->routeIs('admin.index') ? 'active' : '' }}">
                Dashboard
            </a>
            @if (auth()->user()->tenant)
                <a href="{{ route('conversations.index') }}"
                   class="nav-link {{ request()->routeIs('conversations.*') ? 'active' : '' }}">
                    Conversations
                </a>
                <a href="{{ route('profile.show') }}"
                   class="nav-link {{ request()->routeIs('profile.*') ? 'active' : '' }}">
                    Profile
                </a>
                <a href="{{ route('tenant.setup') }}"
                   class="nav-link {{ request()->routeIs('tenant.*') ? 'active' : '' }}">
                    Setup
                </a>
            @endif
            @if (auth()->user()?->is_admin)
                <div class="nav-section-label" style="margin-top:12px;">Admin</div>
                <a href="{{ route('admin.index') }}"
                   class="nav-link {{ request()->routeIs('admin.*') ? 'active' : '' }}">
                    Admin Panel
                </a>
            @endif
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-user">
                <div class="sidebar-user-name">{{ auth()->user()->name }}</div>
                <div class="sidebar-user-email">{{ auth()->user()->email }}</div>
                @if (auth()->user()->is_admin)
                    <div class="sidebar-user-role">Super Admin</div>
                @endif
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="button button--ghost" style="width: 100%;">Log Out</button>
            </form>
        </div>
    </aside>

    <main class="content">
        @if (session('status'))
            <div class="note" style="margin-bottom: 20px;">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="note error" style="margin-bottom: 20px;">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        {{ $slot }}
    </main>
</div>
</body>
</html>
