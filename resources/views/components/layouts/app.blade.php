<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Sync360' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,700;1,9..40,400&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
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
            --type-display-tracking: -0.032em;
            --type-heading-tracking: -0.024em;
            --type-kicker-tracking: 0.08em;
            --type-label-tracking: 0.04em;
            --type-tech-tracking: 0.035em;

            /* Spacing scale (4px base, 2px half-steps). See artifacts/DESIGN_SYSTEM.md §5. */
            --space-0-5: 2px;
            --space-1:   4px;
            --space-1-5: 6px;
            --space-2:   8px;
            --space-2-5: 10px;
            --space-3:   12px;
            --space-3-5: 14px;
            --space-4:   16px;
            --space-4-5: 18px;
            --space-5:   20px;
            --space-5-5: 22px;
            --space-6:   24px;
            --space-7:   28px;
            --space-8:   32px;
            --space-9:   36px;
            --space-10:  40px;
            --space-12:  48px;

            /* Radius scale. See artifacts/DESIGN_SYSTEM.md §5. */
            --radius-sm:     8px;
            --radius-md:     10px;
            --radius-lg:     14px;
            --radius-xl:     20px;
            --radius-2xl:    24px;
            --radius-pill:   999px;
            --radius-circle: 50%;

            /* Elevation scale. */
            --shadow-panel: 0 4px 24px rgba(26, 26, 26, 0.05);
            --shadow-focus: 0 0 0 3px rgba(255, 107, 53, 0.14);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "DM Sans", "Segoe UI", sans-serif;
            color: var(--ink);
            line-height: 1.5;
            background:
                radial-gradient(circle at top left, rgba(255, 107, 53, 0.05), transparent 28%),
                linear-gradient(180deg, #ffffff 0%, var(--bg) 100%);
        }
        a { color: inherit; text-decoration: none; }

        /* ── Typography contract ── */
        .type-display,
        .topbar h2 {
            margin: 0;
            font-size: clamp(1.7rem, 4vw, 2.2rem);
            line-height: 1.08;
            letter-spacing: var(--type-display-tracking);
        }
        .type-section-title {
            margin: 0;
            font-size: 1.3rem;
            line-height: 1.16;
            letter-spacing: var(--type-heading-tracking);
        }
        .type-body {
            font-size: 0.96rem;
            line-height: 1.62;
        }
        .type-muted,
        .topbar p,
        .hint {
            color: var(--muted);
            line-height: 1.58;
        }
        .type-muted { font-size: 0.9rem; }
        .hint { font-size: 0.875rem; }
        .type-kicker,
        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            width: fit-content;
            padding: 6px 12px;
            border-radius: 999px;
            background: rgba(255, 107, 53, 0.10);
            color: var(--accent-dark);
            font-size: 0.74rem;
            font-weight: 700;
            line-height: 1.2;
            letter-spacing: var(--type-kicker-tracking);
            text-transform: uppercase;
        }
        .type-label {
            display: block;
            color: var(--muted);
            margin-bottom: 6px;
            font-size: 0.73rem;
            font-weight: 700;
            letter-spacing: var(--type-label-tracking);
            line-height: 1.35;
            text-transform: uppercase;
        }
        .type-value {
            display: block;
            color: var(--ink);
            font-size: 1rem;
            font-weight: 700;
            line-height: 1.36;
            overflow-wrap: anywhere;
        }
        .type-value--technical,
        .type-tech {
            font-family: "JetBrains Mono", monospace;
            letter-spacing: var(--type-tech-tracking);
        }
        .type-value--technical {
            font-size: 0.96rem;
            line-height: 1.46;
        }
        .type-tech {
            font-size: 0.82rem;
            line-height: 1.45;
        }
        .type-tech--wrap {
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }
        .type-status {
            font-size: 0.82rem;
            font-weight: 700;
            line-height: 1.2;
        }

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
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(255,255,255,0.30);
            line-height: 1.3;
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
        .sidebar-user-role  { color: var(--accent); font-size: 0.74rem; font-weight: 700; margin-top: 4px; text-transform: uppercase; letter-spacing: 0.07em; line-height: 1.3; }

        .sidebar-logout-btn { width: 100%; }

        /* ── Main content ── */
        .content { padding: 32px; }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 28px;
            gap: 16px;
        }
        .topbar p { margin: var(--space-2) 0 0; max-width: 64ch; }

        /* ── Panels / Cards ── */
        .panel {
            background: rgba(255,255,255,0.97);
            border: 1px solid rgba(229, 231, 235, 0.90);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-panel);
            padding: var(--space-6);
        }
        .grid   { display: grid; gap: var(--space-4-5); }
        .grid-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }

        /* ── Stats ── */
        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: var(--space-3-5);
        }
        .stat {
            padding: var(--space-5);
            border-radius: var(--radius-2xl);
            background: #faf9f8;
            border: 1px solid var(--stroke);
        }
        .stat strong {
            display: block;
            font-size: 1.3rem;
            font-weight: 700;
            margin-top: var(--space-2);
            color: var(--ink);
            line-height: 1.18;
        }

        /* ── Status badges ── */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: var(--space-1) var(--space-3);
            border-radius: var(--radius-pill);
            font-size: 0.82rem;
            font-weight: 700;
            line-height: 1.2;
            letter-spacing: 0.01em;
            font-family: "DM Sans", "Segoe UI", sans-serif;
        }
        .badge.pending, .badge.queued       { background: #fef3c7; color: #92400e; }
        .badge.provisioning, .badge.running { background: #dbeafe; color: #1d4ed8; }
        .badge.ready, .badge.completed, .badge.trial_active { background: #dcfce7; color: #15803d; }
        .badge.failed, .badge.trial_expired { background: var(--danger-bg); color: var(--danger); }
        .badge--technical {
            font-family: "JetBrains Mono", monospace;
            font-size: 0.78rem;
            letter-spacing: var(--type-tech-tracking);
        }

        /* ── Tables ── */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.93rem; }
        th, td {
            padding: var(--space-3-5) var(--space-3-5);
            border-bottom: 1px solid var(--stroke);
            text-align: left;
            vertical-align: top;
        }
        th {
            color: var(--muted);
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            background: #faf9f8;
        }
        tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: rgba(255, 107, 53, 0.03); }
        .clickable-row { cursor: pointer; }

        /* ── Buttons ── */
        .button, button {
            border: 0;
            border-radius: var(--radius-pill);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: var(--space-2);
            font: inherit;
            font-weight: 600;
            font-size: 0.9rem;
            padding: var(--space-2-5) var(--space-5);
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
            padding: var(--space-2) var(--space-4);
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
            gap: var(--space-3);
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .meta-item {
            padding: var(--space-3-5) var(--space-4);
            border-radius: var(--radius-lg);
            background: #faf9f8;
            border: 1px solid var(--stroke);
        }
        .meta-item > *:last-child {
            margin-bottom: 0;
        }
        .meta-item small,
        .meta-item .type-label {
            margin-bottom: var(--space-1-5);
        }
        .meta-item strong {
            display: block;
            font-size: 1rem;
            line-height: 1.38;
            overflow-wrap: anywhere;
        }

        /* ── Notes / alerts ── */
        .note {
            padding: var(--space-3-5) var(--space-4);
            border-radius: var(--radius-lg);
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
        input:focus-visible, select:focus-visible, textarea:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 2px;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.14);
            background: white;
        }
        .button:focus-visible, button:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 3px;
        }
        .nav-link:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: -2px;
        }
        input::placeholder,
        textarea::placeholder { color: #a29c97; }

        /* ── Field-level validation ── */
        input[aria-invalid="true"],
        select[aria-invalid="true"],
        textarea[aria-invalid="true"] {
            border-color: var(--danger);
            box-shadow: 0 0 0 3px rgba(159, 55, 55, 0.10);
        }
        .field-error {
            display: block;
            color: var(--danger);
            font-size: 0.82rem;
            font-weight: 500;
            margin-top: var(--space-1);
            line-height: 1.4;
        }

        /* ── Notification bell ── */
        .notif-bell-wrap {
            position: relative;
            margin-bottom: 10px;
        }
        .notif-bell-btn {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 10px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.08);
            color: rgba(255,255,255,0.70);
            font-size: 0.88rem;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.15s;
            text-align: left;
            position: relative;
        }
        .notif-bell-btn:hover {
            background: rgba(255,255,255,0.09);
            color: white;
            transform: none;
            box-shadow: none;
        }
        .notif-bell-badge {
            position: absolute;
            top: 8px;
            left: 26px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #ef4444;
            border: 1.5px solid #1a1a1a;
        }
        .notif-bell-count {
            margin-left: auto;
            background: #ef4444;
            color: white;
            font-size: 0.7rem;
            font-weight: 700;
            border-radius: 999px;
            min-width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 5px;
            font-family: "JetBrains Mono", monospace;
        }
        .notif-dropdown {
            display: none;
            position: absolute;
            bottom: calc(100% + 8px);
            left: 0;
            right: 0;
            background: #2a2a2a;
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 -8px 32px rgba(0,0,0,0.4);
            z-index: 100;
        }
        .notif-dropdown.open { display: block; }
        .notif-header {
            padding: 12px 14px 8px;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: rgba(255,255,255,0.35);
            border-bottom: 1px solid rgba(255,255,255,0.07);
        }
        .notif-item {
            padding: 12px 14px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }
        .notif-item:last-child { border-bottom: none; }
        .notif-item-title {
            font-size: 0.84rem;
            font-weight: 600;
            color: #f5f5f5;
            margin-bottom: 3px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .notif-item-msg {
            font-size: 0.78rem;
            color: rgba(255,255,255,0.55);
            line-height: 1.45;
            margin-bottom: 6px;
        }
        .notif-item-cta {
            font-size: 0.75rem;
            font-weight: 600;
            color: #fb923c;
            text-decoration: none;
        }
        .notif-item-cta:hover { color: #fdba74; }
        .notif-empty {
            padding: 16px 14px;
            font-size: 0.82rem;
            color: rgba(255,255,255,0.35);
            text-align: center;
        }
        /* ── Reduced motion ── */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                transition-duration: 0.01ms !important;
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
            }
        }

        /* ── Hamburger button (hidden on desktop) ── */
        .hamburger-btn {
            display: none;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.12);
            color: white;
            border-radius: var(--radius-md);
            padding: var(--space-1-5) var(--space-2);
            cursor: pointer;
            flex-direction: column;
            gap: 4px;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            /* Override the global `button { background: var(--accent) }` rule */
            box-shadow: none;
        }
        /* Higher-specificity rule to override the `button { background: var(--accent) }` cascade */
        .sidebar .hamburger-btn,
        .sidebar .hamburger-btn:hover {
            background: rgba(255,255,255,0.08);
            box-shadow: none;
            transform: none;
            color: white;
        }
        .sidebar .hamburger-btn:hover {
            background: rgba(255,255,255,0.15);
        }
        .hamburger-btn span {
            display: block;
            width: 18px;
            height: 2px;
            background: white;
            border-radius: 2px;
            transition: transform 0.2s ease, opacity 0.15s ease;
        }
        /* Animate to X when open */
        .sidebar.nav-open .hamburger-btn span:nth-child(1) { transform: translateY(6px) rotate(45deg); }
        .sidebar.nav-open .hamburger-btn span:nth-child(2) { opacity: 0; }
        .sidebar.nav-open .hamburger-btn span:nth-child(3) { transform: translateY(-6px) rotate(-45deg); }

        /* ── Mobile nav drawer ── */
        .mobile-drawer {
            display: none; /* shown only on mobile via media query */
        }

        /* ── Responsive ── */
        @media (max-width: 980px) {
            .shell { grid-template-columns: 1fr; }

            /* Compact sticky top bar: brand left, hamburger right */
            .sidebar {
                height: auto;
                position: sticky;
                top: 0;
                z-index: 100;
                flex-direction: row;
                align-items: center;
                padding: var(--space-2) var(--space-3-5);
                gap: var(--space-2);
                /* Must be visible so the absolute-positioned drawer isn't clipped */
                overflow: visible;
            }
            .sidebar-brand {
                flex: 1;
                border-bottom: none;
                margin-bottom: 0;
                padding: 0;
                min-width: 0;
            }

            /* Hide desktop-only nav and footer; show hamburger */
            nav, .sidebar-footer { display: none; }
            .hamburger-btn { display: inline-flex; }

            /* Drawer: hidden by default, shown when .nav-open */
            .mobile-drawer {
                display: none;
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                background: #1A1A1A;
                border-top: 1px solid rgba(255,255,255,0.08);
                padding: var(--space-2) var(--space-3-5) var(--space-3);
                flex-direction: column;
                gap: var(--space-0-5);
                box-shadow: 0 8px 24px rgba(0,0,0,0.32);
                z-index: 99;
                max-height: calc(100vh - 60px);
                overflow-y: auto;
            }
            .sidebar.nav-open .mobile-drawer { display: flex; }

            /* Nav links in drawer */
            .mobile-drawer .nav-link {
                border-left: 3px solid transparent;
                border-radius: var(--radius-md);
                font-size: 0.93rem;
                padding: var(--space-2-5) var(--space-3);
            }
            .mobile-drawer .nav-link.active {
                background: rgba(255, 107, 53, 0.12);
                border-left-color: var(--accent);
                color: white;
            }
            .mobile-drawer .nav-section-label {
                display: block;
                padding: var(--space-2) var(--space-3) var(--space-0-5);
            }

            /* Bell in drawer */
            .mobile-drawer .notif-bell-wrap { margin-bottom: 0; }
            .mobile-drawer .notif-bell-btn {
                width: 100%;
                border-radius: var(--radius-md);
            }

            /* User + logout in drawer footer */
            .mobile-drawer-footer {
                margin-top: var(--space-2);
                padding-top: var(--space-2);
                border-top: 1px solid rgba(255,255,255,0.08);
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: var(--space-2);
            }
            .mobile-drawer-user {
                font-size: 0.85rem;
                font-weight: 600;
                color: rgba(255,255,255,0.75);
                min-width: 0;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .sidebar-logout-btn {
                font-size: 0.82rem;
                padding: var(--space-1-5) var(--space-3);
                width: auto;
                flex-shrink: 0;
            }

            /* Content */
            .content { padding: var(--space-4); }
            .topbar {
                flex-direction: column;
                align-items: stretch;
                gap: var(--space-3);
                margin-bottom: var(--space-5);
            }
            .topbar-actions { display: flex; flex-wrap: wrap; gap: var(--space-2); }

            /* Grids */
            .stats, .grid-2, .meta, .field-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 480px) {
            .content { padding: var(--space-3); }
            .panel { padding: var(--space-4); }
        }
    </style>
</head>
<body>
<div class="shell">
    <aside class="sidebar" id="main-sidebar">
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

        <button class="hamburger-btn" id="hamburger-btn" onclick="toggleMobileNav()" aria-label="Toggle navigation" aria-expanded="false" aria-controls="main-sidebar">
            <span></span><span></span><span></span>
        </button>

        @php
            $extraAlerts = $__env->yieldContent('sidebar-alerts-extra') !== '' ? json_decode($__env->yieldContent('sidebar-alerts-extra'), true) : [];
            $allAlerts   = array_merge($sidebarAlerts ?? [], is_array($extraAlerts) ? $extraAlerts : []);
            $alertCount  = count($allAlerts);
        @endphp

        {{-- Desktop sidebar nav --}}
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
                <a href="{{ route('dashboard') }}#skill-outcomes"
                   class="nav-link">
                    Skill Outcomes
                </a>
            @endif
            @if (auth()->user()?->is_admin)
                <div class="nav-section-label" style="margin-top:12px;">Admin</div>
                <a href="{{ route('admin.index') }}"
                   class="nav-link {{ request()->routeIs('admin.index') ? 'active' : '' }}">
                    Overview
                </a>
                <a href="{{ route('admin.tenants') }}"
                   class="nav-link {{ request()->routeIs('admin.tenants*') ? 'active' : '' }}">
                    Tenants
                </a>
                <a href="{{ route('admin.analytics.skills') }}"
                   class="nav-link {{ request()->routeIs('admin.analytics.skills') ? 'active' : '' }}">
                    Skill Analytics
                </a>
                @if (config('sync360.skill_catalog.enabled', false))
                    <a href="{{ route('admin.skills.index') }}"
                       class="nav-link {{ request()->routeIs('admin.skills.*') ? 'active' : '' }}">
                        Skill Catalog
                    </a>
                @endif
                <a href="{{ route('admin.jobs') }}"
                   class="nav-link {{ request()->routeIs('admin.jobs') ? 'active' : '' }}">
                    Jobs
                </a>
            @endif
        </nav>

        {{-- Mobile nav drawer (visible when hamburger is open) --}}
        <div class="mobile-drawer">
            {{-- Bell/alerts in drawer --}}
            <div class="notif-bell-wrap">
                <button class="notif-bell-btn" id="notif-bell-toggle-mobile" onclick="toggleNotifBell('notif-dropdown-mobile')">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                    </svg>
                    @if ($alertCount > 0)<span class="notif-bell-badge"></span>@endif
                    Alerts
                    @if ($alertCount > 0)<span class="notif-bell-count">{{ $alertCount }}</span>@endif
                </button>
                <div class="notif-dropdown" id="notif-dropdown-mobile">
                    <div class="notif-header">Alerts</div>
                    @forelse ($allAlerts as $alert)
                        <div class="notif-item">
                            <div class="notif-item-title">{{ $alert['icon'] }} {{ $alert['title'] }}</div>
                            <div class="notif-item-msg">{{ $alert['message'] }}</div>
                            @if (!empty($alert['cta']))
                                <a href="{{ $alert['cta']['href'] }}" class="notif-item-cta">{{ $alert['cta']['text'] }} →</a>
                            @endif
                        </div>
                    @empty
                        <div class="notif-empty">No alerts — all good ✓</div>
                    @endforelse
                </div>
            </div>

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
                <a href="{{ route('dashboard') }}#skill-outcomes"
                   class="nav-link">
                    Skill Outcomes
                </a>
            @endif
            @if (auth()->user()?->is_admin)
                <div class="nav-section-label" style="margin-top:8px;">Admin</div>
                <a href="{{ route('admin.index') }}"
                   class="nav-link {{ request()->routeIs('admin.index') ? 'active' : '' }}">
                    Overview
                </a>
                <a href="{{ route('admin.tenants') }}"
                   class="nav-link {{ request()->routeIs('admin.tenants*') ? 'active' : '' }}">
                    Tenants
                </a>
                <a href="{{ route('admin.analytics.skills') }}"
                   class="nav-link {{ request()->routeIs('admin.analytics.skills') ? 'active' : '' }}">
                    Skill Analytics
                </a>
                @if (config('sync360.skill_catalog.enabled', false))
                    <a href="{{ route('admin.skills.index') }}"
                       class="nav-link {{ request()->routeIs('admin.skills.*') ? 'active' : '' }}">
                        Skill Catalog
                    </a>
                @endif
                <a href="{{ route('admin.jobs') }}"
                   class="nav-link {{ request()->routeIs('admin.jobs') ? 'active' : '' }}">
                    Jobs
                </a>
            @endif
            <div class="mobile-drawer-footer">
                <span class="mobile-drawer-user">{{ auth()->user()->name }}</span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="button button--ghost sidebar-logout-btn">Log Out</button>
                </form>
            </div>
        </div>

        {{-- Desktop sidebar footer --}}
        <div class="sidebar-footer">
            <div class="notif-bell-wrap">
                <button class="notif-bell-btn" id="notif-bell-toggle" onclick="toggleNotifBell('notif-dropdown')">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                    </svg>
                    @if ($alertCount > 0)<span class="notif-bell-badge"></span>@endif
                    Alerts
                    @if ($alertCount > 0)<span class="notif-bell-count">{{ $alertCount }}</span>@endif
                </button>
                <div class="notif-dropdown" id="notif-dropdown">
                    <div class="notif-header">Alerts</div>
                    @forelse ($allAlerts as $alert)
                        <div class="notif-item">
                            <div class="notif-item-title">{{ $alert['icon'] }} {{ $alert['title'] }}</div>
                            <div class="notif-item-msg">{{ $alert['message'] }}</div>
                            @if (!empty($alert['cta']))
                                <a href="{{ $alert['cta']['href'] }}" class="notif-item-cta">{{ $alert['cta']['text'] }} →</a>
                            @endif
                        </div>
                    @empty
                        <div class="notif-empty">No alerts — all good ✓</div>
                    @endforelse
                </div>
            </div>
            <div class="sidebar-user">
                <div class="sidebar-user-name">{{ auth()->user()->name }}</div>
                <div class="sidebar-user-email">{{ auth()->user()->email }}</div>
                @if (auth()->user()->is_admin)
                    <div class="sidebar-user-role">Super Admin</div>
                @endif
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="button button--ghost sidebar-logout-btn">Log Out</button>
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
<script>
    function toggleNotifBell(dropdownId) {
        var el = document.getElementById(dropdownId);
        if (el) el.classList.toggle('open');
    }
    function toggleMobileNav() {
        var sidebar = document.getElementById('main-sidebar');
        var btn     = document.getElementById('hamburger-btn');
        var open    = sidebar.classList.toggle('nav-open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    // Close dropdowns on outside click
    document.addEventListener('click', function(e) {
        // Close each bell dropdown independently
        [['notif-bell-toggle', 'notif-dropdown'], ['notif-bell-toggle-mobile', 'notif-dropdown-mobile']].forEach(function(pair) {
            var bellBtn = document.getElementById(pair[0]);
            var bellDd  = document.getElementById(pair[1]);
            if (bellBtn && bellDd && !bellBtn.contains(e.target) && !bellDd.contains(e.target)) {
                bellDd.classList.remove('open');
            }
        });
        // Close mobile drawer on outside click
        var sidebar   = document.getElementById('main-sidebar');
        var hamburger = document.getElementById('hamburger-btn');
        if (sidebar && hamburger && sidebar.classList.contains('nav-open')
            && !sidebar.contains(e.target)) {
            sidebar.classList.remove('nav-open');
            hamburger.setAttribute('aria-expanded', 'false');
        }
    });
</script>
</body>
</html>
