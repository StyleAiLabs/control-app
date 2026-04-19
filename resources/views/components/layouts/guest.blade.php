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
            --bg: #110f0d;
            --bg-soft: #181512;
            --panel: rgba(255, 255, 255, 0.08);
            --panel-strong: rgba(255, 255, 255, 0.94);
            --ink: #f7f5f1;
            --ink-dark: #1a1a1a;
            --muted: rgba(247, 245, 241, 0.72);
            --muted-dark: #6b7280;
            --accent: #FF6B35;
            --accent-dark: #e55a25;
            --stroke: rgba(255, 255, 255, 0.12);
            --stroke-dark: rgba(26, 26, 26, 0.08);
            --success: #10b981;
            --danger: #fecaca;
            --danger-ink: #991b1b;
            --danger-bg: rgba(127, 29, 29, 0.12);
            --shadow: 0 18px 60px rgba(0, 0, 0, 0.22);
            --type-display-tracking: -0.038em;
            --type-heading-tracking: -0.026em;
            --type-kicker-tracking: 0.08em;
            --type-label-tracking: 0.05em;
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
            --space-16:  64px;
            --space-18:  72px;

            /* Radius scale. See artifacts/DESIGN_SYSTEM.md §5. */
            --radius-sm:     8px;
            --radius-md:     12px;
            --radius-lg:     16px;
            --radius-xl:     22px;
            --radius-2xl:    26px;
            --radius-3xl:    30px;
            --radius-pill:   999px;
            --radius-circle: 50%;

            /* Elevation scale (--shadow is the hero/auth-card elevation, kept for existing references). */
            --shadow-focus:    0 0 0 3px rgba(255, 107, 53, 0.14);
            --shadow-elevated: var(--shadow);
        }

        * { box-sizing: border-box; }
        html { background: var(--bg); }
        body {
            margin: 0;
            font-family: "DM Sans", "Segoe UI", sans-serif;
            color: var(--ink);
            line-height: 1.5;
            background:
                radial-gradient(circle at 12% 18%, rgba(255, 107, 53, 0.18), transparent 28%),
                radial-gradient(circle at 88% 12%, rgba(255, 170, 123, 0.12), transparent 24%),
                linear-gradient(180deg, #1A1A1A 0%, #14110f 55%, #100d0b 100%);
        }
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(rgba(255,255,255,0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.02) 1px, transparent 1px);
            background-size: 72px 72px;
            mask-image: linear-gradient(180deg, rgba(0,0,0,0.18), transparent 80%);
            pointer-events: none;
        }

        a { color: inherit; text-decoration: none; }
        .type-display {
            margin: 0;
            line-height: 0.98;
            letter-spacing: var(--type-display-tracking);
        }
        .type-section-title {
            margin: 0;
            font-size: 1.7rem;
            line-height: 1.14;
            letter-spacing: var(--type-heading-tracking);
        }
        .type-body {
            font-size: 0.98rem;
            line-height: 1.62;
        }
        .type-muted,
        .hint {
            color: var(--muted-dark);
            line-height: 1.58;
        }
        .hint { font-size: 0.92rem; }
        .type-kicker,
        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            width: fit-content;
            padding: 9px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: rgba(255, 244, 239, 0.92);
            font-size: 0.78rem;
            font-weight: 700;
            line-height: 1.2;
            letter-spacing: var(--type-kicker-tracking);
            text-transform: uppercase;
        }
        .type-label {
            display: block;
            margin-bottom: 6px;
            color: rgba(247, 245, 241, 0.66);
            font-size: 0.74rem;
            font-weight: 700;
            letter-spacing: var(--type-label-tracking);
            line-height: 1.35;
            text-transform: uppercase;
        }
        .panel .type-label,
        .auth-card .type-label {
            color: rgba(17, 15, 13, 0.52);
        }
        .type-value {
            display: block;
            font-size: 1rem;
            font-weight: 700;
            line-height: 1.38;
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
        .shell {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            position: relative;
            z-index: 0;
        }

        .container {
            width: min(1240px, calc(100% - 40px));
            margin: 0 auto;
        }
        .full-bleed {
            width: 100vw;
            margin-left: calc(50% - 50vw);
        }

        .nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 22px 0 18px;
            position: relative;
            z-index: 3;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .brand-mark {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            background:
                linear-gradient(135deg, rgba(255, 107, 53, 1), rgba(229, 90, 37, 0.86));
            box-shadow: 0 12px 28px rgba(255, 107, 53, 0.32);
            color: white;
            flex-shrink: 0;
        }
        .brand-wordmark {
            display: grid;
            gap: 3px;
        }
        .brand-wordmark strong {
            font-size: 1.05rem;
            letter-spacing: 0.04em;
            line-height: 1.05;
        }
        .brand-wordmark span {
            color: rgba(247, 245, 241, 0.68);
            font-size: 0.88rem;
            line-height: 1.3;
        }
        .nav-links {
            display: flex;
            gap: 14px;
            align-items: center;
        }
        .link {
            color: rgba(247, 245, 241, 0.82);
            font-size: 0.95rem;
            font-weight: 500;
            transition: color 0.15s ease;
        }
        .link:hover { color: white; }

        .button, button {
            border: 0;
            border-radius: var(--radius-pill);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: var(--space-2);
            font: inherit;
            font-weight: 700;
            padding: var(--space-3-5) var(--space-6);
            transition: transform 0.18s ease, box-shadow 0.18s ease, background 0.18s ease, border-color 0.18s ease;
            text-decoration: none;
        }
        .button:hover, button:hover {
            transform: translateY(-2px);
        }
        .button--primary, button {
            background: var(--accent);
            color: white;
            box-shadow: 0 12px 30px rgba(255, 107, 53, 0.34);
        }
        .button--primary:hover, button:hover {
            background: var(--accent-dark);
            box-shadow: 0 16px 38px rgba(255, 107, 53, 0.42);
        }
        .button--secondary {
            background: rgba(255, 255, 255, 0.04);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.16);
            box-shadow: none;
        }
        .button--secondary:hover {
            border-color: rgba(255, 255, 255, 0.28);
            background: rgba(255, 255, 255, 0.08);
            box-shadow: none;
        }

        .eyebrow-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--accent);
            box-shadow: 0 0 0 6px rgba(255, 107, 53, 0.16);
        }

        h1, h2, h3, h4 { margin: 0; line-height: 1.08; letter-spacing: var(--type-heading-tracking); }
        p { margin: 0; color: var(--muted); line-height: 1.62; }

        .landing-page {
            display: grid;
            gap: 0;
            margin-top: 6px;
        }
        .landing-hero {
            display: grid;
            grid-template-columns: minmax(0, 440px) minmax(0, 1fr);
            gap: clamp(24px, 4vw, 52px);
            align-items: center;
            width: min(1240px, calc(100% - 40px));
            margin: 0 auto;
            padding: 34px 0 70px;
            min-height: calc(100svh - 116px);
            position: relative;
            overflow: hidden;
        }
        .landing-hero::after {
            content: "";
            position: absolute;
            right: -120px;
            bottom: -140px;
            width: 420px;
            height: 420px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255, 107, 53, 0.18), transparent 68%);
            pointer-events: none;
            z-index: 0;
        }
        .hero-copy,
        .hero-visual {
            position: relative;
            z-index: 1;
            min-width: 0;
        }
        .hero-copy {
            display: grid;
            grid-template-columns: 1fr;
            gap: 26px;
            align-content: center;
            max-width: 520px;
            padding-left: 0;
        }
        .hero-copy-main {
            display: grid;
            gap: 16px;
            max-width: 480px;
        }
        .hero-copy h1 {
            margin-top: 0;
            font-size: clamp(3rem, 5vw, 5.15rem);
            color: white;
            max-width: 8.2ch;
            line-height: 0.96;
        }
        .hero-support {
            display: grid;
            gap: 20px;
            max-width: 32rem;
            padding-bottom: 0;
        }
        .hero-copy p {
            margin-top: 0;
            font-size: 1.04rem;
            max-width: 34ch;
        }
        .hero-actions {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
            margin-top: 0;
        }
        .hero-proof {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 0;
            color: rgba(247, 245, 241, 0.78);
            font-size: 0.88rem;
        }
        .hero-proof span {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .hero-proof span::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: rgba(255, 107, 53, 0.7);
        }

        .hero-visual {
            display: grid;
            justify-items: stretch;
            align-items: center;
            padding-right: 0;
        }
        .product-preview {
            width: 100%;
            max-width: 620px;
            margin-left: auto;
            border-radius: 30px;
            padding: 14px;
            background:
                linear-gradient(180deg, rgba(255, 255, 255, 0.09), rgba(255, 255, 255, 0.03));
            border: 1px solid rgba(255, 255, 255, 0.12);
            box-shadow: var(--shadow);
            backdrop-filter: blur(14px);
            transform: translateY(4px);
        }
        .product-topbar {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
            padding: 4px 6px 12px;
            color: rgba(247, 245, 241, 0.72);
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .product-topbar span:last-child {
            color: #9df8c1;
        }
        .preview-surface {
            border-radius: 24px;
            overflow: hidden;
            background: #f8f3ee;
            display: grid;
            grid-template-columns: 188px 1fr;
            min-height: 388px;
        }
        .preview-sidebar {
            background: linear-gradient(180deg, #231814, #17110f);
            color: rgba(247, 245, 241, 0.86);
            padding: 16px 14px;
            display: grid;
            align-content: start;
            gap: 14px;
        }
        .preview-brand {
            display: grid;
            gap: 4px;
            padding-bottom: 14px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .preview-brand strong {
            font-size: 0.95rem;
            letter-spacing: 0.06em;
            color: white;
        }
        .preview-brand span,
        .preview-team span {
            color: rgba(247, 245, 241, 0.5);
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }
        .preview-nav,
        .preview-team {
            display: grid;
            gap: 8px;
        }
        .preview-nav div,
        .preview-team div {
            border-radius: 12px;
            padding: 9px 10px;
            background: rgba(255, 255, 255, 0.04);
            font-size: 0.8rem;
            line-height: 1.4;
        }
        .preview-nav div.active {
            background: rgba(255, 107, 53, 0.18);
            color: white;
            border: 1px solid rgba(255, 107, 53, 0.36);
        }
        .preview-main {
            padding: 14px 16px;
            display: grid;
            grid-template-rows: auto auto 1fr auto;
            gap: 12px;
            color: var(--ink-dark);
        }
        .preview-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(26, 26, 26, 0.08);
        }
        .preview-header h3 {
            font-size: 1.15rem;
            color: #131313;
        }
        .preview-header p {
            color: #6a5e57;
            font-size: 0.84rem;
        }
        .preview-chip {
            border-radius: 999px;
            padding: 6px 10px;
            background: rgba(16, 185, 129, 0.12);
            color: #0f8b61;
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            white-space: nowrap;
        }
        .preview-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }
        .preview-stat {
            border-radius: 16px;
            background: white;
            padding: 10px 11px;
            border: 1px solid rgba(26, 26, 26, 0.06);
        }
        .preview-stat span {
            display: block;
            color: #7a6d66;
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .preview-stat strong {
            display: block;
            margin-top: 6px;
            font-size: 1rem;
            color: #141414;
        }
        .preview-feed {
            display: grid;
            align-content: start;
            gap: 0;
            background: rgba(255, 255, 255, 0.56);
            border-radius: 18px;
            border: 1px solid rgba(26, 26, 26, 0.06);
            overflow: hidden;
            max-height: 192px;
        }
        .preview-item {
            display: flex;
            gap: 12px;
            padding: 12px 14px;
            border-bottom: 1px solid rgba(26, 26, 26, 0.06);
        }
        .preview-item:last-child {
            border-bottom: 0;
        }
        .preview-item-icon {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            flex-shrink: 0;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, #ff7a46, #ff6b35);
            color: white;
            font-size: 0.82rem;
            font-weight: 700;
            box-shadow: 0 10px 18px rgba(255, 107, 53, 0.16);
        }
        .preview-item-copy {
            display: grid;
            gap: 5px;
            min-width: 0;
        }
        .preview-item-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .preview-item-meta strong {
            color: #1a1a1a;
            font-size: 0.9rem;
        }
        .preview-item-meta span {
            display: inline-flex;
            align-items: center;
            padding: 3px 7px;
            border-radius: 999px;
            background: rgba(255, 107, 53, 0.08);
            color: #d86438;
            font-size: 0.66rem;
            font-family: "JetBrains Mono", monospace;
            letter-spacing: 0.08em;
        }
        .preview-item-meta time {
            color: #8b7d76;
            font-size: 0.72rem;
            font-family: "JetBrains Mono", monospace;
        }
        .preview-item-copy p {
            color: #26201d;
            font-size: 0.95rem;
            line-height: 1.45;
        }
        .preview-item-copy small {
            color: #8b7d76;
            font-size: 0.78rem;
            line-height: 1.45;
        }
        .preview-composer {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            align-items: center;
            min-height: 48px;
            background: rgba(255, 255, 255, 0.78);
            border: 1px solid rgba(26, 26, 26, 0.08);
            border-radius: 18px;
            padding: 0 12px 0 14px;
            color: #9a8d87;
            font-size: 0.88rem;
        }
        .preview-send {
            border: 0;
            border-radius: 999px;
            padding: 9px 14px;
            background: #171311;
            color: white;
            font-size: 0.8rem;
            font-weight: 700;
            box-shadow: none;
        }
        .preview-send:hover {
            transform: none;
            background: #171311;
            box-shadow: none;
        }

        .section-shell {
            padding: 0 min(5vw, 56px) 28px;
        }
        .section-shell .inner {
            width: min(1240px, calc(100% - 40px));
            margin: 0 auto;
        }
        .outcome-strip {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-top: -8px;
        }
        .outcome-card {
            padding: 22px 24px;
            border-radius: 24px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(10px);
            box-shadow: 0 18px 42px rgba(0, 0, 0, 0.12);
        }
        .outcome-card span {
            color: rgba(247, 245, 241, 0.6);
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }
        .outcome-card strong {
            display: block;
            margin-top: 10px;
            font-size: 1.55rem;
            color: white;
        }
        .outcome-card p {
            margin-top: 10px;
            font-size: 0.98rem;
        }

        .story-grid {
            display: grid;
            grid-template-columns: minmax(0, 0.92fr) minmax(0, 1.08fr);
            gap: 28px;
            padding: 72px 0 28px;
            align-items: start;
        }
        .story-copy h2,
        .final-cta h2 {
            font-size: clamp(2rem, 4.4vw, 3.5rem);
            color: white;
            max-width: 12ch;
        }
        .story-copy p {
            margin-top: 18px;
            max-width: 52ch;
            font-size: 1.02rem;
        }
        .story-list {
            display: grid;
            gap: 14px;
            margin-top: 28px;
        }
        .story-list div {
            display: flex;
            gap: 14px;
            align-items: flex-start;
            padding: 16px 0;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
        }
        .story-list strong {
            display: block;
            color: white;
            font-size: 1rem;
        }
        .story-list p {
            margin-top: 4px;
            font-size: 0.95rem;
        }
        .story-step {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            flex-shrink: 0;
            display: grid;
            place-items: center;
            background: rgba(255, 107, 53, 0.14);
            color: white;
            font-weight: 700;
        }
        .value-grid {
            display: grid;
            gap: 14px;
        }
        .value-block {
            border-radius: 26px;
            padding: 24px;
            background: linear-gradient(180deg, rgba(255,255,255,0.08), rgba(255,255,255,0.03));
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .value-block small {
            color: rgba(255, 244, 239, 0.64);
            text-transform: uppercase;
            letter-spacing: 0.1em;
            font-size: 0.76rem;
        }
        .value-block h3 {
            margin-top: 12px;
            font-size: 1.45rem;
            color: white;
        }
        .value-block p {
            margin-top: 10px;
        }

        .final-cta {
            margin: 44px auto 72px;
            width: min(1240px, calc(100% - 40px));
            border-radius: 34px;
            padding: 34px;
            background:
                linear-gradient(135deg, rgba(255, 107, 53, 0.18), rgba(255, 107, 53, 0.06)),
                rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: end;
            justify-content: space-between;
            gap: 24px;
        }
        .final-cta p {
            margin-top: 14px;
            max-width: 54ch;
        }
        .final-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .auth-wrap {
            display: grid;
            grid-template-columns: 1fr min(520px, 100%);
            gap: 24px;
            padding: 28px 0 64px;
            align-items: start;
        }
        .auth-wrap--login {
            grid-template-columns: minmax(0, 1.02fr) minmax(380px, 460px);
        }
        .auth-story {
            display: grid;
            gap: 22px;
        }
        .hero-card,
        .panel {
            border-radius: var(--radius-3xl);
            box-shadow: var(--shadow-elevated);
        }
        .hero-card {
            padding: var(--space-8);
            background: linear-gradient(180deg, rgba(255,255,255,0.09), rgba(255,255,255,0.04));
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        .panel {
            padding: var(--space-9);
            background: var(--panel-strong);
            color: var(--ink-dark);
            border: 1px solid var(--stroke-dark);
        }
        .auth-aside h2 {
            margin-top: 18px;
            color: white;
            font-size: clamp(2rem, 4vw, 3rem);
        }
        .auth-aside p {
            margin-top: 14px;
            max-width: 42ch;
        }
        .auth-proof {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 22px;
        }
        .auth-proof span {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: rgba(247, 245, 241, 0.86);
            font-size: 0.84rem;
        }
        .auth-proof span::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: rgba(255, 107, 53, 0.82);
        }
        .auth-metric-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
        }
        .auth-metric {
            padding: 18px;
            border-radius: 22px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .auth-metric span {
            display: block;
            color: rgba(247, 245, 241, 0.58);
            font-size: 0.76rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }
        .auth-metric strong {
            display: block;
            margin-top: 8px;
            color: white;
            font-size: 1.12rem;
            line-height: 1.25;
        }
        .auth-conversation {
            padding: 20px;
            border-radius: 26px;
            background: linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.03));
            border: 1px solid rgba(255, 255, 255, 0.08);
            display: grid;
            gap: 12px;
        }
        .auth-conversation-header {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            align-items: center;
        }
        .auth-conversation-header strong {
            color: white;
            font-size: 1rem;
        }
        .auth-conversation-header span {
            color: rgba(247, 245, 241, 0.58);
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .auth-bubble {
            max-width: 88%;
            padding: 13px 15px;
            border-radius: 18px;
            font-size: 0.93rem;
            line-height: 1.5;
        }
        .auth-bubble--user {
            background: rgba(255, 255, 255, 0.09);
            color: rgba(247, 245, 241, 0.92);
            border-bottom-left-radius: 8px;
        }
        .auth-bubble--ai {
            margin-left: auto;
            background: linear-gradient(135deg, rgba(255, 122, 70, 0.96), rgba(255, 107, 53, 0.9));
            color: white;
            border-bottom-right-radius: 8px;
            box-shadow: 0 14px 28px rgba(255, 107, 53, 0.2);
        }
        .auth-card h2 {
            font-size: 1.7rem;
            color: var(--ink-dark);
        }
        .auth-card .hint {
            color: var(--muted-dark);
        }
        .auth-card header {
            display: grid;
            gap: 10px;
            margin-bottom: 24px;
        }
        .auth-card .kicker {
            color: rgba(17, 15, 13, 0.48);
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
        }

        form { display: grid; gap: 16px; }
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
            color: var(--ink-dark);
            font-size: 0.9rem;
            font-weight: 600;
        }
        input, select {
            width: 100%;
            border-radius: 14px;
            border: 1.5px solid rgba(17, 15, 13, 0.12);
            padding: 13px 16px;
            background: #fcfaf8;
            font: inherit;
            color: var(--ink-dark);
            transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
        }
        input:focus-visible, select:focus-visible {
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
        input::placeholder { color: #a29c97; }

        /* ── Field-level validation ── */
        input[aria-invalid="true"],
        select[aria-invalid="true"],
        textarea[aria-invalid="true"] {
            border-color: var(--danger-ink);
            box-shadow: 0 0 0 3px rgba(127, 29, 29, 0.10);
        }
        .field-error {
            display: block;
            color: var(--danger-ink);
            font-size: 0.82rem;
            font-weight: 500;
            margin-top: var(--space-1);
            line-height: 1.4;
        }

        .mini-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-top: 24px;
        }
        .mini-tile {
            padding: 18px;
            border-radius: 22px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .mini-tile h3 {
            margin-top: 10px;
            font-size: 1.08rem;
            color: white;
        }
        .mini-tile .hint,
        .mini-tile p {
            color: rgba(247, 245, 241, 0.72);
        }
        .mini-tile p {
            margin-top: 8px;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 16px;
            font-size: 0.95rem;
            margin-bottom: 18px;
        }
        .alert--success {
            background: rgba(16, 185, 129, 0.12);
            color: #b6f4d6;
            border: 1px solid rgba(16, 185, 129, 0.22);
        }
        .alert--error {
            background: var(--danger-bg);
            color: var(--danger);
            border: 1px solid rgba(248, 113, 113, 0.24);
        }
        .list { padding-left: 18px; margin: 10px 0 0; }
        .list li { margin-bottom: 6px; }

        footer {
            padding: 24px 0 36px;
            color: rgba(247, 245, 241, 0.5);
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }

        /* ── Reduced motion ── */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                transition-duration: 0.01ms !important;
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
            }
        }

        @media (max-width: 1100px) {
            .landing-hero,
            .story-grid,
            .auth-wrap {
                grid-template-columns: 1fr;
            }
            .hero-copy {
                gap: 22px;
                max-width: 640px;
            }
            .hero-support {
                max-width: 34rem;
            }
            .hero-copy,
            .hero-visual {
                padding-left: 0;
                padding-right: 0;
            }
            .hero-visual {
                justify-items: center;
            }
            .product-preview {
                transform: none;
            }
            .final-cta {
                flex-direction: column;
                align-items: start;
            }
        }

        @media (max-width: 900px) {
            .nav {
                padding-top: 18px;
            }
            .field-grid,
            .preview-stats,
            .outcome-strip,
            .mini-grid,
            .auth-metric-grid {
                grid-template-columns: 1fr;
            }
            .preview-surface {
                grid-template-columns: 1fr;
            }
            .preview-sidebar {
                grid-template-columns: 1fr 1fr;
                align-items: start;
            }
            .preview-brand {
                grid-column: 1 / -1;
            }
            .preview-nav,
            .preview-team {
                align-content: start;
            }
        }

        @media (max-width: 720px) {
            .container,
            .final-cta {
                width: min(100%, calc(100% - 28px));
            }
            .landing-hero,
            .section-shell {
                padding-inline: 14px;
            }
            .hero-copy h1 {
                font-size: clamp(2.7rem, 13vw, 4.2rem);
                max-width: 9.5ch;
            }
            .nav {
                flex-wrap: wrap;
            }
            .nav-links {
                width: 100%;
                justify-content: space-between;
            }
            .hero-actions,
            .final-actions {
                width: 100%;
            }
            .hero-actions .button,
            .final-actions .button {
                flex: 1 1 180px;
            }
            .product-preview {
                padding: 14px;
                border-radius: 28px;
            }
        }
    </style>
</head>
<body>
<div class="shell">
    <nav class="nav container">
        <a href="{{ route('landing') }}" class="brand">
            <div class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M5 12.5C5 8.35786 8.35786 5 12.5 5H19V11.5C19 15.6421 15.6421 19 11.5 19H5V12.5Z" fill="white" opacity="0.28"/>
                    <path d="M8 12C8 9.23858 10.2386 7 13 7H16V10C16 12.7614 13.7614 15 11 15H8V12Z" fill="white"/>
                </svg>
            </div>
            <div class="brand-wordmark">
                <strong>SYNC360</strong>
                <span>Digital employee</span>
            </div>
        </a>
        <div class="nav-links">
            <a href="{{ route('login') }}" class="link">Log In</a>
            <a href="{{ route('signup') }}" class="button button--primary">Start Free Trial</a>
        </div>
    </nav>

    <main class="container" style="flex: 1;">
        @if (session('status'))
            <div class="alert alert--success">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert--error">
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
        <span>Conversations answered. Jobs booked. Admin off your plate.</span>
    </footer>
</div>
</body>
</html>
