# Codex Agent Prompt — 05: Blade-First Onboarding Wizard

## Context

The current Sync360 app is a Laravel monolith with Blade views and minimal frontend JS.

Build the onboarding wizard inside the existing product UI rather than assuming React,
Vue, a separate frontend repo, or token auth.

## Task

Build a 6-step onboarding wizard page that feels simple and non-technical for customers.

Suggested structure:

- Blade view: `resources/views/onboarding/show.blade.php`
- small JS module: `resources/js/onboarding.js`
- use same-origin `fetch()` requests to the authenticated onboarding JSON endpoints

## Tone Rules

Customer copy must avoid:

- `OpenClaw`
- `LiteLLM`
- `deployment`
- `runtime`
- `SSH`
- `agent files`

Use:

- `digital employee`
- `setup`
- `business details`
- `go live`
- `connect WhatsApp`

---

## Wizard Steps

### Step 1 — Business Website

Ask for website URL.

If extraction fails, offer a simple manual path with wording like:

`No problem — you can enter your business details manually.`

### Step 2 — Business Info

Editable prefilled form:

- business name
- description
- industry
- services
- contact email
- contact phone
- tagline
- address / city

### Step 3 — Personality

Single choice:

- Friendly & Warm
- Professional
- Formal
- Casual & Fun

### Step 4 — What Should It Handle?

Multi-select toggles:

- answer common questions
- take messages
- handle complaints
- after-hours replies
- booking help
- pricing questions

### Step 5 — Connect A Channel

Large channel cards:

- WhatsApp
- Telegram

Only ask for the minimum credentials needed for the selected channel.

### Step 6 — Go Live

Show a friendly confirmation screen.

If `provisioning_status !== ready`, show:

`We're just finishing your workspace in the background. This usually takes less than a minute.`

Poll until it is ready, then allow the final action.

---

## Practical Frontend Behaviour

### On page load

Call `GET /onboarding/state`.

- if complete, redirect to `/dashboard`
- otherwise restore saved step data
- support `?step=` only for returning to already-completed steps

### Progress indicator

Show:

- completed steps
- current step
- next required step

Do not let users jump forward past incomplete steps.

### Validation

Keep validation friendly and plain-language.

Example:

- bad: `Capability selection invalid`
- good: `Choose at least one thing you want your digital employee to handle.`

### Buttons

Use customer-friendly labels:

- `Continue`
- `Back`
- `Connect WhatsApp`
- `Go Live`

Avoid labels like:

- `Deploy`
- `Sync files`
- `Provision`

---

## Dashboard Resume Behaviour

If onboarding is incomplete, the dashboard should link back into:

```text
/onboarding?step={resume_from_step}
```

The wizard must be able to reopen in the correct place using the saved server-side state.

## Acceptance Criteria

- built inside the existing Blade app
- no dependency on React/Vue unless the repo later adopts one
- all step actions use the authenticated JSON endpoints from prompt 04
- customer-facing language stays non-technical

