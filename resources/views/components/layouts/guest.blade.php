<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Sync360' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
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
            /* Typography tracking (optical adjustment per text role) */
            --type-display-tracking: -0.035em;
            --type-heading-tracking: -0.028em;
            --type-subheading-tracking: -0.015em;
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

        /* ── Typography contract: 5-step scale with clear roles ──
           Scale ratio: 1.333 (major third) for clearer hierarchy
           Body base: 1rem (16px) — respects user font settings
           Headings: DM Sans (clean, professional)
           Body: DM Sans (readable, neutral)
           Technical: JetBrains Mono (code, timestamps, IDs)
        */

        /* Display: hero headlines, marketing impact */
        .type-display {
            margin: 0;
            font-family: "DM Sans", sans-serif;
            font-weight: 700;
            font-size: clamp(2.67rem, 6vw, 4.74rem);
            line-height: 0.95;
            letter-spacing: var(--type-display-tracking);
            color: white;
        }

        /* H1: primary page headings (alternative to display for shorter headlines) */
        .type-h1 {
            margin: 0;
            font-family: "DM Sans", sans-serif;
            font-weight: 700;
            font-size: clamp(2.25rem, 4.8vw, 3.55rem);
            line-height: 1.0;
            letter-spacing: var(--type-heading-tracking);
            color: white;
        }

        /* H2: section headings */
        .type-h2 {
            margin: 0;
            font-family: "DM Sans", sans-serif;
            font-weight: 700;
            font-size: clamp(1.69rem, 3.6vw, 2.67rem);
            line-height: 1.05;
            letter-spacing: var(--type-subheading-tracking);
            color: white;
        }

        /* H3: card titles, panel headings */
        .type-h3 {
            margin: 0;
            font-family: "DM Sans", sans-serif;
            font-weight: 600;
            font-size: 1.69rem;
            line-height: 1.15;
            letter-spacing: var(--type-subheading-tracking);
            color: white;
        }

        /* Body: default text size for paragraphs */
        .type-body {
            font-family: "DM Sans", sans-serif;
            font-size: 1rem;
            line-height: 1.7;
            color: var(--muted);
        }

        /* Body large: when you need slightly more presence */
        .type-body-lg {
            font-family: "DM Sans", sans-serif;
            font-size: 1.125rem;
            line-height: 1.65;
            color: var(--muted);
        }

        /* Secondary: supporting text, captions */
        .type-secondary {
            font-family: "DM Sans", sans-serif;
            font-size: 0.875rem;
            line-height: 1.65;
            color: var(--muted);
        }

        /* Small: fine print, helper text */
        .type-small {
            font-family: "DM Sans", sans-serif;
            font-size: 0.813rem;
            line-height: 1.55;
            color: var(--muted);
        }

        /* Kicker: eyebrow labels, badges */
        .type-kicker,
        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            width: fit-content;
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(255, 255, 255, 0.08);
            color: rgba(255, 244, 239, 0.95);
            font-family: "DM Sans", sans-serif;
            font-size: 0.75rem;
            font-weight: 700;
            line-height: 1.2;
            letter-spacing: var(--type-kicker-tracking);
            text-transform: uppercase;
        }

        /* Label: form labels, meta labels */
        .type-label {
            display: block;
            margin-bottom: 6px;
            color: rgba(247, 245, 241, 0.62);
            font-family: "DM Sans", sans-serif;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: var(--type-label-tracking);
            line-height: 1.35;
            text-transform: uppercase;
        }
        .panel .type-label,
        .auth-card .type-label {
            color: rgba(17, 15, 13, 0.52);
        }

        /* Value: highlighted numbers, stats */
        .type-value {
            display: block;
            font-size: 1.125rem;
            font-weight: 700;
            line-height: 1.3;
            overflow-wrap: anywhere;
        }

        /* Technical: mono-spaced for IDs, timestamps, configs */
        .type-tech,
        .type-value--technical {
            font-family: "JetBrains Mono", monospace;
            font-size: 0.82rem;
            line-height: 1.45;
            letter-spacing: var(--type-tech-tracking);
        }
        .type-value--technical {
            font-size: 0.96rem;
            line-height: 1.46;
        }
        .type-tech--wrap {
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        /* Muted text (alias for secondary) */
        .type-muted {
            color: var(--muted-dark);
            line-height: 1.6;
        }
        .hint {
            color: var(--muted-dark);
            font-size: 0.875rem;
            line-height: 1.5;
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

        /* Improve body text readability with proper line length constraints */
        p.type-body, .type-body p { max-width: 68ch; }
        .type-body-lg { max-width: 72ch; }
        .hero-copy .type-body-lg { max-width: 36ch; }
        .story-copy .type-body, .story-copy .type-body-lg { max-width: 58ch; }

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
            font-family: "DM Sans", sans-serif;
            font-size: 1.05rem;
            font-weight: 700;
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
            font-family: "DM Sans", sans-serif;
            font-weight: 700;
            font-size: 0.938rem;
            padding: var(--space-3-5) var(--space-6);
            transition: transform 0.15s var(--ease-out-quart), box-shadow 0.15s var(--ease-out-quart), background 0.15s var(--ease-out-quart), border-color 0.15s var(--ease-out-quart);
            text-decoration: none;
            position: relative;
            overflow: hidden;
        }
        
        /* Hover state - lift and enhance */
        .button:hover, button:hover {
            transform: translateY(-2px);
        }
        
        /* Active/Click state - quick press feedback */
        .button:active, button:active {
            transform: translateY(-1px) scale(0.97);
            transition-duration: 0.08s;
        }
        
        /* Focus state - accessibility */
        .button:focus-visible, button:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 3px;
        }
        
        /* Primary button styles */
        .button--primary, button {
            background: var(--accent);
            color: white;
            box-shadow: 0 12px 30px rgba(255, 107, 53, 0.34);
        }
        .button--primary:hover, button:hover {
            background: var(--accent-dark);
            box-shadow: 0 16px 38px rgba(255, 107, 53, 0.42);
        }
        .button--primary:active, button:active {
            box-shadow: 0 8px 20px rgba(255, 107, 53, 0.28);
        }
        
        /* Secondary button styles */
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
        .button--secondary:active {
            background: rgba(255, 255, 255, 0.12);
        }
        
        /* Button ripple effect */
        .button__ripple {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: scale(0);
            animation: ripple-animation 0.5s var(--ease-out-quart);
            pointer-events: none;
        }
        @keyframes ripple-animation {
            to {
                transform: scale(2.5);
                opacity: 0;
            }
        }

        .eyebrow-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--accent);
            box-shadow: 0 0 0 6px rgba(255, 107, 53, 0.16);
        }

        h1, h2, h3, h4 { margin: 0; line-height: inherit; letter-spacing: inherit; }
        p { margin: 0; }
        p.type-body, .type-body p { max-width: 65ch; }

        .landing-page {
            display: grid;
            gap: 0;
        }
        .landing-hero {
            display: grid;
            grid-template-columns: minmax(0, 440px) minmax(0, 1fr);
            gap: clamp(32px, 5vw, 52px);
            align-items: center;
            width: min(1240px, calc(100% - 40px));
            margin: 0 auto;
            padding: 48px 0 80px;
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
        .hero-copy .type-display {
            margin-top: 0;
            max-width: 8.2ch;
        }
        .hero-support {
            display: grid;
            gap: 20px;
            max-width: 32rem;
            padding-bottom: 0;
        }
        .hero-copy .type-body-lg {
            margin-top: 0;
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
            gap: 12px;
            margin-top: 0;
            color: rgba(247, 245, 241, 0.78);
            font-size: 0.88rem;
        }
        .hero-proof span {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .proof-icon {
            width: 18px;
            height: 18px;
            color: var(--success);
            flex-shrink: 0;
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
            max-height: 220px;
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
            padding: 0 min(5vw, 56px);
            position: relative;
        }
        .section-shell .inner {
            width: min(1240px, calc(100% - 40px));
            margin: 0 auto;
        }
        .section-shell + .section-shell {
            margin-top: var(--space-12);
        }
        
        /* Section transition dividers */
        .section-divider {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            width: min(1240px, calc(100% - 40px));
            height: 1px;
            background: linear-gradient(90deg, 
                transparent 0%, 
                rgba(255, 255, 255, 0.06) 20%, 
                rgba(255, 255, 255, 0.06) 80%, 
                transparent 100%);
        }
        .section-divider--top {
            top: 0;
        }
        .section-divider--bottom {
            bottom: 0;
        }
        
        /* Section transition fade masks */
        .section-fade-bottom {
            position: absolute;
            bottom: -40px;
            left: 0;
            right: 0;
            height: 40px;
            background: linear-gradient(180deg, 
                transparent 0%, 
                var(--bg) 100%);
            pointer-events: none;
        }
        .section-fade-top {
            position: absolute;
            top: -40px;
            left: 0;
            right: 0;
            height: 40px;
            background: linear-gradient(0deg, 
                transparent 0%, 
                var(--bg) 100%);
            pointer-events: none;
        }
        
        /* Section connector dots */
        .section-connector {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: var(--space-3) 0;
        }
        .section-connector-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: rgba(255, 107, 53, 0.4);
        }
        .section-connector-dot--active {
            background: rgba(255, 107, 53, 0.8);
            animation: connector-pulse 3s var(--ease-out-quart) infinite;
        }
        @keyframes connector-pulse {
            0%, 100% {
                opacity: 0.8;
                transform: scale(1);
            }
            50% {
                opacity: 1;
                transform: scale(1.15);
            }
        }
        .section-connector-line {
            width: 40px;
            height: 1px;
            background: linear-gradient(90deg,
                transparent,
                rgba(255, 107, 53, 0.3));
        }
        .outcome-strip {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            padding: var(--space-8) 0;
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
            color: rgba(247, 245, 241, 0.56);
            font-family: "DM Sans", sans-serif;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.12em;
        }
        .outcome-card strong {
            display: block;
            margin-top: 10px;
            font-family: "DM Sans", sans-serif;
            font-weight: 700;
            font-size: 1.55rem;
            line-height: 1.15;
            color: white;
        }
        .outcome-card p {
            margin-top: 10px;
            font-size: 0.98rem;
            line-height: 1.65;
        }

        /* Trust Bar - Bold social proof section */
        .trust-section {
            padding-bottom: var(--space-12);
        }
        .trust-bar {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 48px;
            padding: 34px 42px;
            border-radius: 26px;
            background: linear-gradient(180deg, rgba(255,255,255,0.07), rgba(255,255,255,0.03));
            border: 1px solid rgba(255, 255, 255, 0.09);
            backdrop-filter: blur(10px);
        }
        .trust-stat {
            display: grid;
            gap: 6px;
            text-align: center;
        }
        .trust-stat .type-value {
            font-family: "DM Sans", sans-serif;
            font-weight: 800;
            font-size: 3.55rem;
            line-height: 0.9;
            letter-spacing: -0.04em;
            color: white;
            text-shadow: 0 2px 24px rgba(255, 107, 53, 0.24);
        }
        .trust-stat .type-label {
            color: rgba(247, 245, 241, 0.62);
            font-size: 0.813rem;
            text-transform: none;
            letter-spacing: 0;
            line-height: 1.4;
        }
        .trust-divider {
            width: 1px;
            height: 48px;
            background: linear-gradient(180deg, transparent, rgba(255, 255, 255, 0.14), transparent);
        }

        /* Testimonials Section */
        .testimonials-section {
            padding: var(--space-18) min(5vw, 56px) var(--space-8);
        }
        .testimonials-header {
            margin-bottom: 42px;
        }
        .testimonials-header .type-h2 {
            max-width: 14ch;
            margin-top: 14px;
        }
        .testimonials-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
        }
        .testimonial-card {
            display: grid;
            grid-template-rows: auto auto auto;
            gap: 18px;
            padding: 26px;
            border-radius: 24px;
            background: linear-gradient(180deg, rgba(255,255,255,0.07), rgba(255,255,255,0.03));
            border: 1px solid rgba(255, 255, 255, 0.08);
            transition: transform 0.2s ease, border-color 0.2s ease;
        }
        .testimonial-card:hover {
            transform: translateY(-3px);
            border-color: rgba(255, 107, 53, 0.28);
        }
        .testimonial-content {
            min-height: 84px;
        }
        .testimonial-quote {
            font-family: "DM Sans", sans-serif;
            font-size: 0.98rem;
            line-height: 1.65;
            color: rgba(247, 245, 241, 0.92);
            font-style: italic;
        }
        .testimonial-author {
            display: flex;
            align-items: center;
            gap: 14px;
            padding-top: 18px;
            border-top: 1px solid rgba(255, 255, 255, 0.06);
        }
        .author-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, rgba(255, 122, 70, 0.96), rgba(255, 107, 53, 0.9));
            color: white;
            font-family: "DM Sans", sans-serif;
            font-weight: 700;
            font-size: 0.9rem;
            box-shadow: 0 8px 20px rgba(255, 107, 53, 0.2);
        }
        .author-info {
            display: grid;
            gap: 3px;
        }
        .author-info strong {
            font-family: "DM Sans", sans-serif;
            font-weight: 600;
            font-size: 0.95rem;
            color: white;
        }
        .author-info span {
            font-family: "DM Sans", sans-serif;
            font-size: 0.813rem;
            color: rgba(247, 245, 241, 0.58);
        }
        .testimonial-metric {
            display: grid;
            gap: 4px;
            padding: 16px;
            border-radius: 16px;
            background: rgba(255, 107, 53, 0.08);
            border: 1px solid rgba(255, 107, 53, 0.18);
        }
        .metric-label {
            font-family: "DM Sans", sans-serif;
            font-size: 0.75rem;
            color: rgba(247, 245, 241, 0.62);
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .metric-value {
            font-family: "DM Sans", sans-serif;
            font-weight: 800;
            font-size: 2.25rem;
            line-height: 1;
            color: var(--accent);
            letter-spacing: -0.03em;
        }

        /* How It Fits Section - 3-column icon-based flow */
        .how-it-fits-section {
            padding: var(--space-18) min(5vw, 56px) var(--space-8);
        }
        .how-it-fits-inner {
            width: min(1240px, calc(100% - 40px));
            margin: 0 auto;
            display: grid;
            gap: var(--space-12);
        }
        .how-it-fits-header {
            max-width: 720px;
        }
        .how-it-fits-header .type-h2 {
            max-width: 14ch;
            margin-top: 14px;
        }
        .how-it-fits-header .type-body-lg {
            margin-top: 18px;
            max-width: 58ch;
        }

        /* Flow Diagram - 3-column icon-based layout */
        .flow-diagram--columns {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: var(--space-4);
        }
        .flow-step--column {
            display: grid;
            grid-template-rows: auto 1fr;
            gap: var(--space-4);
            padding: var(--space-6) var(--space-5);
            border-radius: 20px;
            background: linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.03));
            border: 1px solid rgba(255, 255, 255, 0.08);
            transition: border-color 0.2s ease-out-quart, transform 0.2s ease-out-quart;
        }
        .flow-step--column:hover {
            border-color: rgba(255, 107, 53, 0.22);
            transform: translateY(-3px);
        }
        .flow-step--column .flow-step-icon {
            width: 64px;
            height: 64px;
            border-radius: 18px;
            display: grid;
            place-items: center;
            flex-shrink: 0;
            background: linear-gradient(135deg, rgba(255, 122, 70, 0.96), rgba(255, 107, 53, 0.9));
            color: white;
            box-shadow: 0 12px 32px rgba(255, 107, 53, 0.32);
        }
        .flow-step--column .flow-step-icon svg {
            width: 32px;
            height: 32px;
            stroke-width: 2;
        }
        .flow-step--column .flow-step-content {
            display: grid;
            gap: 10px;
        }
        .flow-step--column .flow-step-title {
            font-family: "DM Sans", sans-serif;
            font-weight: 600;
            font-size: 1.15rem;
            color: white;
            line-height: 1.3;
            letter-spacing: -0.015em;
        }
        .flow-step--column .flow-step-desc {
            font-family: "DM Sans", sans-serif;
            font-size: 0.95rem;
            line-height: 1.65;
            color: rgba(247, 245, 241, 0.65);
        }
        .flow-step--column.flow-step--last {
            background: linear-gradient(180deg, rgba(255, 107, 53, 0.1), rgba(255, 107, 53, 0.04));
            border-color: rgba(255, 107, 53, 0.22);
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
        
        /* Metric-First Card Layout */
        .value-grid--metric-first {
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
        }
        .value-block--metric-first {
            display: grid;
            grid-template-rows: auto 1fr;
            gap: 0;
            padding: 0;
            overflow: hidden;
        }
        .value-metric--hero {
            display: grid;
            gap: 6px;
            padding: 22px 24px;
            background: linear-gradient(180deg, rgba(255, 107, 53, 0.12), rgba(255, 107, 53, 0.06));
            border-bottom: 1px solid rgba(255, 107, 53, 0.18);
        }
        .value-metric--hero .metric-value--large {
            font-family: "DM Sans", sans-serif;
            font-weight: 800;
            font-size: 3.55rem;
            line-height: 0.9;
            color: var(--accent);
            letter-spacing: -0.04em;
        }
        .value-metric--hero .metric-label {
            font-family: "DM Sans", sans-serif;
            font-size: 0.813rem;
            color: rgba(247, 245, 241, 0.68);
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        .value-block--metric-first .value-content {
            display: grid;
            gap: 10px;
            padding: 22px 24px;
        }
        .value-block--metric-first .type-label {
            color: rgba(255, 244, 239, 0.52);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }
        .value-block--metric-first .type-h3 {
            font-size: 1.25rem;
            line-height: 1.25;
        }
        .value-block--metric-first .type-body {
            font-size: 0.95rem;
            line-height: 1.65;
        }

        .final-cta {
            margin: var(--space-16) auto var(--space-18);
            width: min(1240px, calc(100% - 40px));
            border-radius: 34px;
            padding: 42px;
            background:
                radial-gradient(circle at 10% 20%, rgba(255, 107, 53, 0.28), transparent 40%),
                radial-gradient(circle at 90% 80%, rgba(255, 107, 53, 0.18), transparent 45%),
                linear-gradient(135deg, rgba(255, 107, 53, 0.25), rgba(255, 107, 53, 0.1)),
                rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.14);
            display: grid;
            grid-template-columns: minmax(0, 1.2fr) minmax(0, 0.8fr);
            gap: 42px;
            align-items: center;
            box-shadow: 0 24px 80px rgba(255, 107, 53, 0.18);
        }
        .final-cta-content {
            display: grid;
            gap: 22px;
        }
        .final-cta .type-h2 {
            max-width: 14ch;
        }
        .final-cta p {
            margin-top: 14px;
            max-width: 54ch;
        }
        .final-cta-proof {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin-top: 22px;
        }
        .proof-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-family: "DM Sans", sans-serif;
            font-size: 0.875rem;
            color: rgba(247, 245, 241, 0.78);
        }
        .proof-check {
            width: 20px;
            height: 20px;
            color: var(--success);
            flex-shrink: 0;
        }
        .final-actions {
            display: flex;
            flex-direction: column;
            gap: 12px;
            align-items: stretch;
        }
        .final-actions .button {
            width: 100%;
            justify-content: center;
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
        .auth-aside .type-h2 {
            margin-top: 18px;
            max-width: 42ch;
        }
        .auth-aside .type-body,
        .auth-aside .type-body-lg {
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
            font-family: "DM Sans", sans-serif;
            font-size: 0.813rem;
            font-weight: 500;
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
        .auth-metric .type-label {
            color: rgba(247, 245, 241, 0.58);
        }
        .auth-metric .type-value {
            margin-top: 8px;
            color: white;
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
        .auth-conversation-header .type-value {
            color: white;
        }
        .auth-conversation-header .type-label {
            color: rgba(247, 245, 241, 0.58);
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
        .auth-card .type-h3 {
            color: var(--ink-dark);
        }
        .auth-card .type-secondary,
        .auth-card .type-muted {
            color: var(--muted-dark);
        }
        .auth-card header {
            display: grid;
            gap: 10px;
            margin-bottom: 24px;
        }
        .auth-card .kicker {
            color: rgba(17, 15, 13, 0.48);
            font-family: "DM Sans", sans-serif;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: var(--type-kicker-tracking);
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
            font-family: "DM Sans", sans-serif;
            font-size: 0.875rem;
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
            font-family: "DM Sans", sans-serif;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
        }

        /* ── Entrance Animations ── */
        @keyframes fade-in-up {
            from {
                opacity: 0;
                transform: translateY(24px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        @keyframes fade-in-scale {
            from {
                opacity: 0;
                transform: scale(0.98);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        /* Animation base class */
        .animate-in {
            opacity: 0;
            animation: fade-in-up 0.6s var(--ease-out-quart) forwards;
            animation-fill-mode: both;
            animation-play-state: running;
        }

        /* Staggered delays for hero section choreography */
        .animate-delay-0 { animation-delay: 0ms; }
        .animate-delay-1 { animation-delay: 100ms; }
        .animate-delay-2 { animation-delay: 250ms; }
        .animate-delay-3 { animation-delay: 400ms; }
        .animate-delay-4 { animation-delay: 550ms; }
        .animate-delay-5 { animation-delay: 700ms; }

        /* Hero visual uses scale instead of slide */
        .hero-visual.animate-in {
            animation-name: fade-in-scale;
        }

        /* Product preview subtle float after entrance */
        .product-preview {
            animation: float 6s ease-in-out infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-8px); }
        }

        /* Easing variables */
        :root {
            --ease-out-quart: cubic-bezier(0.25, 1, 0.5, 1);
            --ease-out-quint: cubic-bezier(0.22, 1, 0.36, 1);
            --ease-out-expo: cubic-bezier(0.16, 1, 0.3, 1);
        }

        /* ── Reduced motion ── */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                transition-duration: 0.01ms !important;
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
            }
            .animate-in {
                opacity: 1 !important;
                animation: none !important;
            }
            .product-preview {
                animation: none !important;
            }
            .section-connector-dot--active {
                animation: none !important;
            }
        }

        @media (max-width: 1100px) {
            .landing-hero,
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
            .landing-hero {
                padding: 40px 0 60px;
            }
            .final-cta {
                grid-template-columns: 1fr;
                gap: 32px;
            }
            .final-actions {
                flex-direction: row;
            }
            .final-actions .button {
                width: auto;
                flex: 1 1 180px;
            }
            .testimonials-grid {
                grid-template-columns: 1fr;
            }
            .value-grid--metric-first {
                grid-template-columns: 1fr;
                gap: 14px;
            }
            .value-metric--hero {
                padding: 20px;
            }
            .value-metric--hero .metric-value--large {
                font-size: 2.67rem;
            }
            .value-block--metric-first .value-content {
                padding: 20px;
            }
        }

        @media (max-width: 900px) {
            .nav {
                padding-top: 18px;
            }
            .section-shell + .section-shell {
                margin-top: var(--space-10);
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
            .trust-bar {
                flex-direction: column;
                gap: 24px;
                padding: 28px 24px;
            }
            .trust-divider {
                width: 48px;
                height: 1px;
                background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.14), transparent);
            }
            .trust-stat .type-value {
                font-size: 2.67rem;
            }
            .section-connector {
                padding: var(--space-2) 0;
            }
            .section-connector-line {
                width: 30px;
            }
            .section-connector-dot {
                width: 5px;
                height: 5px;
            }
        }

        @media (max-width: 720px) {
            .container,
            .final-cta {
                width: min(100%, calc(100% - 28px));
            }
            .landing-hero {
                padding: 32px 0 48px;
            }
            .section-shell {
                padding-inline: 14px;
            }
            .section-shell + .section-shell {
                margin-top: var(--space-8);
            }
            .hero-copy .type-display {
                font-size: clamp(2.2rem, 10vw, 3.5rem);
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
            .final-cta {
                padding: 28px 24px;
            }
            .how-it-fits-section {
                padding: 48px 14px var(--space-8);
            }
            .flow-diagram--columns {
                grid-template-columns: 1fr;
                gap: var(--space-3);
            }
            .flow-step--column {
                padding: var(--space-4) var(--space-3);
                gap: var(--space-3);
            }
            .flow-step--column .flow-step-icon {
                width: 52px;
                height: 52px;
            }
            .flow-step--column .flow-step-icon svg {
                width: 26px;
                height: 26px;
            }
            .flow-step--column .flow-step-title {
                font-size: 1.08rem;
            }
            .flow-step--column .flow-step-desc {
                font-size: 0.92rem;
            }
            .final-cta-proof {
                flex-direction: column;
                gap: 10px;
            }
            .testimonials-section {
                padding: 48px 0 0;
            }
            .testimonials-header .type-h2 {
                max-width: 18ch;
            }
            .hero-proof {
                gap: 10px;
            }
            .proof-icon {
                width: 16px;
                height: 16px;
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

<script>
    // Button ripple effect
    document.querySelectorAll('.button, button').forEach(button => {
        button.addEventListener('click', function(e) {
            // Skip if user prefers reduced motion
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                return;
            }
            
            const rect = this.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            
            const ripple = document.createElement('span');
            ripple.className = 'button__ripple';
            ripple.style.width = ripple.style.height = Math.max(rect.width, rect.height) + 'px';
            ripple.style.left = (x - ripple.offsetWidth / 2) + 'px';
            ripple.style.top = (y - ripple.offsetHeight / 2) + 'px';
            
            this.appendChild(ripple);
            
            ripple.addEventListener('animationend', () => {
                ripple.remove();
            });
        });
    });

    // Metric value counting animation
    const animateCounter = (element, target, duration = 1500) => {
        // Skip if user prefers reduced motion - show final value immediately
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            const prefix = element.dataset.prefix || '';
            const suffix = element.dataset.suffix || '';
            element.textContent = prefix + target + suffix;
            return;
        }
        
        const startTime = performance.now();
        const startValue = 0;
        const prefix = element.dataset.prefix || '';
        const suffix = element.dataset.suffix || '';
        
        const easeOutQuart = (t) => 1 - Math.pow(1 - t, 4);
        
        const updateCounter = (currentTime) => {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const easedProgress = easeOutQuart(progress);
            const currentValue = Math.floor(startValue + (target - startValue) * easedProgress);
            
            element.textContent = prefix + currentValue + suffix;
            
            if (progress < 1) {
                requestAnimationFrame(updateCounter);
            } else {
                // Ensure final value is exact
                element.textContent = prefix + target + suffix;
            }
        };
        
        requestAnimationFrame(updateCounter);
    };

    // Intersection Observer for scroll-triggered counter animation
    const counterObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting && !entry.target.classList.contains('counted')) {
                const element = entry.target;
                const targetValue = parseInt(element.dataset.countTo, 10);
                
                if (!isNaN(targetValue)) {
                    element.classList.add('counted');
                    animateCounter(element, targetValue, 1500);
                }
            }
        });
    }, {
        threshold: 0.5,
        rootMargin: '0px'
    });

    // Observe all metric values with data-count-to attribute
    document.querySelectorAll('.metric-value[data-count-to]').forEach(metric => {
        counterObserver.observe(metric);
    });
</script>

</body>
</html>
