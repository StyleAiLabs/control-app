# Release Notes

> [!IMPORTANT]
> Canonical historical changelog. Use this file to understand what changed and when, not as the primary source for current architecture. For current truth, start with [`artifacts/MEMORY.md`](MEMORY.md) and [`artifacts/ARCHITECTURE.md`](ARCHITECTURE.md). Older entries may describe Telegram webhook-era experiments or broader WhatsApp plans that no longer match the implemented system.

This file tracks product and engineering changes for the Sync360 Control App.

Newest updates appear first.

## 2026-04-17 — Reprovisioning Fix For Deleted Tenant Slug Reuse

Date: 2026-04-17
Status: Implemented

### Overview

Fixed a provisioning failure that occurred when a customer deleted a tenant, signed up again with the same business name, and the runtime host still had a stale Docker container using the old slug-derived container name.

### What Changed

- added explicit stale-container cleanup for the fixed tenant container name (`sync360-<slug>`) during provisioning cleanup before `docker compose up`
- added the same explicit named-container cleanup to tenant deletion so runtime teardown is resilient even when compose-managed cleanup was incomplete
- covered the reprovisioning and deletion paths with focused tests so delete-and-recreate flows remain safe

## 2026-04-17 — Onboarding Flow Friction Fixes

Date: 2026-04-17
Status: Implemented

### Overview

Smoothed the onboarding wizard so background state refreshes no longer wipe in-progress edits, successful saves move customers straight into the next step, and the Telegram channel setup no longer collapses while someone is entering their bot token.

### What Changed

- preserved in-progress website, business-details, communication-style, capabilities, and channel selections while the onboarding state poll refreshes the page state
- stopped the client-side refresh from clearing unsaved tone and capability choices before they are submitted
- advanced the wizard automatically after successful website read, business save, communication-style save, capability save, and channel connect actions
- preserved unsaved Telegram selection and bot-token entry UI while the onboarding state poll refreshes the page state
- stopped the client-side refresh from clearing the local channel radio selection when the server has not yet saved a channel
- reset the local channel draft only after an explicit disconnect action
- replaced the repeated `Telegram is available now...` note with a contextual helper message shown only when Telegram is selected and still needs a bot token

## 2026-04-17 — Local Runtime Reachability Fixes + Tenant Google Smoke Test

Date: 2026-04-17
Status: Implemented

### Overview

Fixed the local Docker development runtime path so tenant health checks, admin workspace status, Google auth reseeding, and tenant-side GOG smoke tests work from inside the Laravel app container instead of failing against the wrong Docker/gateway assumptions.

### What Changed

- updated the local Docker Compose stack to force:
  - `SYNC360_INFRASTRUCTURE_DRIVER=local`
  - `SYNC360_LOCAL_DOCKER_COMPOSE_BIN=docker-compose`
  - `SYNC360_HOST_PORT_PROBE_HOST=host.docker.internal`
- removed local-only hardcoded `docker compose` assumptions from dashboard/admin/runtime-smoke paths and switched them to the configured local compose binary
- changed local private gateway access so `TenantRuntimeService::gatewayBaseUrl()` uses the configured Docker-host alias rather than container-local `127.0.0.1`
- updated local readiness checks to use that same gateway base URL during provisioning and later health checks
- corrected Google runtime reload behavior so tenant container env changes require a recreate (`up -d --force-recreate`) instead of a plain restart
- added no-cache headers to the authenticated dashboard response so the workspace status card does not stay stuck on stale browser renders after a local runtime state change

### Verification

- local host Docker confirmed `style-software` was running and healthy
- direct tenant health check now reports `healthy` / `running`
- `sync360:test-google-workspace style-software` passed from inside the tenant runtime with:
  - connected account `gayan.ssw@gmail.com`
  - Gmail profile access
  - Calendar list access
  - expected `XDG_CONFIG_HOME=/home/node/.openclaw/.openclaw`

## 2026-04-16 — Optional Google Workspace Onboarding + DB-Backed OAuth Sync

Date: 2026-04-16
Status: Implemented

### Overview

Added an optional Google Workspace connect step to onboarding and moved Google auth ownership into the Sync360 control plane. The database now holds the canonical Google refresh/access tokens and runtime `gog` auth files are treated as disposable cache that can be re-seeded after restart, rebuild, or reprovision.

### What Changed

- expanded onboarding from six steps to seven:
  - `1 Website`
  - `2 Business Info`
  - `3 Tone`
  - `4 Skills`
  - `5 Channel`
  - `6 Google Workspace`
  - `7 Go Live`
- added `tenant_google_credentials` as the source-of-truth table for Google auth state, encrypted tokens, scopes, runtime sync status, and transient OAuth state/PKCE values
- added `TenantGoogleCredential`, `GoogleWorkspaceOAuthService`, `GogAuthStorageService`, and `GoogleOAuthController`
- added direct Google OAuth config in `config/services.php` using `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, and optional `GOOGLE_PROJECT_ID`
- implemented Sync360-owned OAuth start and callback routes instead of delegating the web flow to `gog`
- added onboarding Step 6 UI with connect, skip, disconnect, reconnect state, runtime-sync messaging, and non-blocking progression to Step 7
- added the explicit v1 scope set:
  - identity: `openid`, `email`, `profile`
  - Gmail: `gmail.readonly`, `gmail.send`, `gmail.compose`
  - Calendar: `calendar`
  - Drive: `drive.file`
  - Contacts: `contacts.readonly`
  - Sheets: `spreadsheets`
  - Docs: `documents`
- added `TenantAgentSyncService::configureGoogleWorkspace()`, `disconnectGoogleWorkspace()`, and `syncConnectedGoogleWorkspace()` to write tenant `.openclaw/gogcli/` auth artifacts from DB state and restart the runtime when needed
- provisioned file-backed GOG keyring settings into tenant runtime env so the mounted runtime path holds the auth cache across normal container restarts
- added automatic Google auth reseeding after OAuth callback when the runtime is ready, after provisioning completes, and after later profile/go-live sync paths

### Important Invariants Preserved

- `TenantAgentSyncService::goLive()` still syncs workspace markdown files only and does not perform a full runtime sync
- Google auth sync uses targeted runner file writes under `.openclaw/gogcli/`; it does not use `syncRuntime()` and is not coupled to `goLive()`
- conversation history remains session-log based through `sync360:sync-replies`
- private runtime access still goes through the control plane's private gateway helpers

### Notes

- v1 relies on `gog` self-refreshing the runtime access token from the runtime keyring refresh token during normal operation
- the DB refresh token is retained as the long-lived recovery source for reseeding runtime auth after rebuilds or deletes
- rollout can use Google OAuth test-user mode while verification is in progress by adding early customers as allowed test users in Google Cloud Console
- the current `gog` storage contract is isolated in `GogAuthStorageService` so fixture-based tests can fail loudly if upstream storage expectations change

## 2026-04-16 — Onboarding UX Copy and Progress Alignment

Date: 2026-04-16
Branch: `cdx-feature/customer-onobarding-uxpolish`
Status: Implemented

### Overview

Finished the remaining onboarding UX polish by aligning active customer-facing surfaces with the shipped Phase 1 owner↔assistant model and the current Telegram-only onboarding flow.

### What Changed

- added `OnboardingStepCatalog` as the shared source of truth for the six onboarding step labels used by the wizard and dashboard
- updated dashboard progress summaries to use `Website`, `Business Info`, `Tone`, `Skills`, `Channel`, and `Go Live`
- rewrote dashboard, setup-ready, and conversation-browser copy so it describes owner conversations with the digital employee instead of public/customer-facing messaging
- kept Telegram as the only live channel path in customer-facing copy and treated WhatsApp as a disabled placeholder rather than an active connection
- removed the stale `onboarding UX refinement` open-work note from `artifacts/MEMORY.md`

## 2026-04-16 — Password Reset Delivery Fixed To Use Brevo

Date: 2026-04-16
Branch: `cdx-feature/tenant-password-reset`
Status: Implemented

### Overview

Fixed the password reset email delivery path after discovering that reset links were not actually being sent. The reset flow itself was working, but the broker was still using Laravel's default notification mail path while the environment had `MAIL_MAILER=log`, so reset emails were written to logs instead of delivered.

### Root Cause

- the app already had Brevo wired for other transactional emails through direct HTTP API services
- password reset was using Laravel's default `ResetPassword` notification path
- `MAIL_MAILER` was set to `log`, so no real outbound mail transport was used for reset emails

### Fix

- added `PasswordResetEmailService`
- overrode `User::sendPasswordResetNotification()` to use that service
- when Brevo is enabled, reset emails now go through Brevo's `/smtp/email` API
- if Brevo is unavailable or disabled, the code falls back to Laravel's standard reset notification
- added test coverage proving the forgot-password flow hits Brevo when enabled

## 2026-04-15 — Self-Serve Client Password Reset

Date: 2026-04-15
Branch: `cdx-feature/tenant-password-reset`
Status: Implemented

### Overview

Added Laravel's standard self-serve password reset flow for customer accounts so forgotten passwords no longer require manual intervention. The flow uses the existing mail setup, so reset emails go through the configured mail driver and fit the current auth stack instead of introducing a separate support-only recovery path.

### What Changed

- added guest routes for:
  - `GET /forgot-password`
  - `POST /forgot-password`
  - `GET /reset-password/{token}`
  - `POST /reset-password`
- added `ForgotPasswordController` to request reset links through the Laravel password broker
- added `ResetPasswordController` to validate tokens, update the password, rotate the remember token, and redirect back to login
- added branded guest views for the forgot-password and reset-password screens
- added a recovery link to the existing login screen

### Notes

- reset tokens use the existing `password_reset_tokens` table
- delivery uses the existing application mail configuration, which already points at the deployed mail provider setup
- the implementation follows the current guest auth design system rather than introducing a starter-kit UI

## 2026-04-15 — Canonical Docs Refresh, Archive Cleanup, and Local Doc Guardrails

Date: 2026-04-15
Branch: `cdx-feature/project-memory-update`
Status: Ready for merge

### Overview

Refreshed the repo’s canonical documentation set so a new chat can recover current project truth from `README.md`, `artifacts/MEMORY.md`, and `artifacts/ARCHITECTURE.md` without relying on older plans first. This pass also moved superseded planning material into a dedicated archive area and added local git hook guardrails so core implementation changes require canonical doc updates before commit or push.

### Canonical Docs

- tightened `README.md` into a repo entrypoint with a docs map and doc-status guidance
- kept `artifacts/MEMORY.md` focused on durable new-chat startup context
- kept `artifacts/ARCHITECTURE.md` as the code-backed as-built technical reference
- clarified `artifacts/RELEASE_NOTES.md` as the historical changelog rather than the primary architecture source

### Archive Cleanup

- moved older plans, TODO-style implementation notes, client-server planning docs, and the onboarding prompt pack into `artifacts/archive/`
- preserved the founder HTML architecture artifact separately instead of treating it as canonical current architecture
- reduced duplicate onboarding and webhook-era guidance from the repo root so canonical docs and code are easier to trust first

### Local Documentation Guardrails

- added `scripts/check-canonical-docs.sh`
- added repo-local `.githooks/pre-commit` and `.githooks/pre-push`
- added `composer docs:check` and `composer hooks:install`
- enforced that core code changes under `app/`, `routes/`, `config/`, or `resources/views/` must be accompanied by updates to:
  - `artifacts/MEMORY.md`
  - `artifacts/ARCHITECTURE.md`
  - `artifacts/RELEASE_NOTES.md` with a descriptive new entry

### Product Impact

No runtime routes, APIs, or schema changed in this pass. The main effect is better project memory hygiene, clearer trust boundaries between canonical and historical docs, and stronger local discipline around keeping docs current with implementation changes.

## 2026-04-14 — Session-Grouped Conversation History + AI Summaries

Date: 2026-04-14
Branch: `cdx-feature/hot-fixes` → `codex/control-app-prod-deploy`
Status: Deployed to production

### Overview

Replaced the flat per-message conversation log view with a session-aware, AI-summarised conversation history system. Each conversation thread is now grouped by its OpenClaw session UUID, and a one-sentence AI summary is generated per session so customers can immediately understand the context of any past conversation.

### Architecture Shift — Polling Mode

**OpenClaw does not use a Telegram webhook.** It runs in native `getUpdates` long-polling mode, meaning no `webhookUrl` exists in `openclaw.json`. Messages flow:

```
Telegram → OpenClaw polling → workspace agent → reply → session log written to
  {runtime_path}/.openclaw/workspace/memory/*.md
```

The control-app `WebhookController` is not involved in this flow. Conversation logs are backfilled from the session files by the `sync360:sync-replies` scheduler command.

### Database Schema

New migration: `2026_04_14_000001_add_session_id_and_summary_to_conversation_logs`

- `session_id` (VARCHAR, nullable, indexed) — OpenClaw session UUID extracted from `# Session:` headers in workspace memory files
- `ai_summary` (TEXT, nullable) — one-sentence business-outcome summary generated per session by the LLM

### Session Log Parser — `WorkspaceSessionLogReader`

Rewritten to split workspace memory Markdown files on `# Session:` block headers. Each message turn is mapped to its exact session UUID. Previous implementation ignored session structure entirely.

### Sync Command — `SyncConversationReplies`

Updated flow:

1. Reads all session log files from the tenant workspace VPS over SSH
2. Groups messages by `session_id`
3. Upserts `ConversationLog` records (creates new, updates `message_out` if replied, skips unchanged)
4. After upsert, checks all sessions missing an `ai_summary`
5. Calls `ConversationSummaryService::summarise()` per incomplete session
6. Writes summary back to all records in that session

Scheduled every 10 minutes in `routes/console.php`.

### AI Summary Service — `ConversationSummaryService`

- Calls LiteLLM at `LITELLM_BASE_URL` using the platform `LITELLM_VIRTUAL_KEY`
- Model: `claude-sonnet-4-6` (only model the platform virtual key permits)
- System prompt: produces ≤25-word summary focused on business outcome
- Failure is silent (returns `null`, records kept without summary until next sync)

### Conversations UI

- Redesigned from flat message list → compact session summary cards
- Each card: channel badge, sender, `Replied`/`No reply` status badge, AI summary paragraph, date, message count, short session UUID
- No chat thread expanded inline — summary only
- Custom pagination view (`resources/views/vendor/pagination/tailwind.blade.php`) — fixes oversized SVG arrows that appeared when Tailwind classes were not loaded; uses project button design with explicit 14×14 SVG icons and accent-coloured active page numbers

### Scheduler Container Fix

The `docker-compose.prod.yml` was missing a scheduler service — `sync360:sync-replies` and all other scheduled commands were **never running automatically** in production.

Added:
- `scheduler` service in `docker-compose.prod.yml`
- `docker/start-prod-scheduler.sh` — waits for DB/Redis/app health then runs `php artisan schedule:work` (foreground scheduler, no cron required)

Verification:
- `scheduler` container confirmed running: `Up 18 seconds`
- `session_id` populated for 3 sessions (382e48e9, daded3a9, dd393720)
- AI summaries generated for all 3 sessions:
  - `Gayan Hewage greeted the assistant, but no business need or outcome was identified`
  - `Gayan inquired about automation services and Style Software's contact...`
  - `Painting business owner needed a website similar to nzcpm.co.nz; arranged consultation`

### Files Changed

- `database/migrations/2026_04_14_000001_add_session_id_and_summary_to_conversation_logs.php`
- `app/Models/ConversationLog.php` — `session_id`, `ai_summary` added to `$fillable`
- `app/Services/WorkspaceSessionLogReader.php` — session-block parser rewrite
- `app/Services/ConversationSummaryService.php` — LiteLLM `claude-sonnet-4-6` summariser
- `app/Console/Commands/SyncConversationReplies.php` — session grouping + summary trigger
- `app/Http/Controllers/ConversationsController.php` — paginate by session, not by message
- `resources/views/conversations/index.blade.php` — summary card UI
- `resources/views/vendor/pagination/tailwind.blade.php` — custom pagination (NEW)
- `docker-compose.prod.yml` — added `scheduler` service
- `docker/start-prod-scheduler.sh` — scheduler container entrypoint (NEW)

---

## 2026-04-14 — Global Workspace Alert (All Pages)

Date: 2026-04-14
Branch: `cdx-feature/hot-fixes` → `codex/control-app-prod-deploy` (commit `e7fdf3b`)
Status: Deployed to production

### Overview

Workspace offline alerts were only showing on the Dashboard (where the live Docker check runs). Conversations, Profile, Setup, and all future pages showed a clean bell with no badge even when the workspace was down.

### Root Cause

The alert was injected as a `request()->attributes` value by `DashboardController`, which only lives for the duration of that single request. The `AppServiceProvider` View composer reads from this attribute and merges it with DB-level alerts — but on every other page (no DashboardController), the attribute was never set.

### Fix

`DashboardController::index()` now **persists the live Docker check result to the DB**:

- If workspace is **running** → clears `last_health_check_status` and `health_check_message` so stale alerts disappear everywhere.
- If workspace is **stopped / unknown** → writes `last_health_check_status = 'failed'` and `health_check_message` to the tenant row, then the `AppServiceProvider` View composer already reads this on every page and shows the bell alert.

No live Docker call is made on any page other than the dashboard — the persisted DB value acts as the cached state across all pages.

### File changed

- `app/Http/Controllers/DashboardController.php` — adds DB persistence of workspace health check result in `index()`

---

## 2026-04-14 — Telegram Conversation Logging Pipeline Fix

Date: 2026-04-14
Branch: `cdx-feature/hot-fixes` → `codex/control-app-prod-deploy` (commits `6f60009`, `781aa40`)
Status: Deployed to production

### Overview

Investigated and resolved a multi-layer issue causing Telegram conversations to not appear in the Control App's Conversations dashboard. Root cause was that incoming messages were handled entirely by the OpenClaw workspace (bypassing the control-app `WebhookController`), so zero `ConversationLog` records were ever created.

### Root Causes Found

1. **Telegram webhook was blank** — The bot had no webhook registered at all. OpenClaw's gateway uses long-polling, not webhooks, to receive messages natively and replies without involving the control-app.
2. **OpenClaw overrides webhook on every restart** — `configureChannel()` writes `botToken` into `openclaw.json`. When the gateway starts, it registers its own workspace URL (e.g. `style-software.workspace.sync360.co.nz`) as the Telegram webhook, bypassing the control-app entirely.
3. **Apache stale config** — The Let's Encrypt SSL cert for `app.sync360.co.nz` existed under `/etc/letsencrypt/live/` but Apache was running on a cached config referencing it incorrectly. Telegram's strict TLS checks caused `setWebhook` to fail with "Failed to resolve host" — not a DNS issue, but a TLS validation failure.
4. **Webhook URL in onboarding UI was wrong** — `OnboardingController::channelSetupPayload()` used `route()` to generate webhook URLs. Requests proxied through the workspace VPS (`89.116.28.191`) don't set `X-Forwarded-Host` correctly, causing `route()` to return the workspace subdomain URL instead of `app.sync360.co.nz`.

### Changes Made

#### `app/Services/TenantAgentSyncService.php`
- Added public `registerTelegramWebhook(Tenant $tenant)` method
- Calls Telegram's `setWebhook` API after `configureChannel()` completes (both local and production)
- Points webhook at `{APP_URL}/webhooks/telegram/{tenant_id}`
- Non-fatal — logs warning/error and continues if Telegram API call fails

#### `app/Http/Controllers/OnboardingController.php`
- Replaced `route('webhooks.telegram.handle', ...)` with `config('app.url') . '/webhooks/telegram/' . $tenant->tenant_id`
- Same fix for WhatsApp webhook URL
- Prevents workspace proxy from polluting the displayed URL

#### Production (manual ops)
- Reloaded Apache with `sudo systemctl reload apache2` to pick up valid cert config
- Manually registered Telegram webhook via `setWebhook` API:
  ```
  https://app.sync360.co.nz/webhooks/telegram/01KP0JG8P5KA1ZMQCPD2X8GE2G
  ```
- Deployed latest code: `git pull` + `docker compose restart app worker`

### Conversation Flow (now correct)

```
Telegram user → POST https://app.sync360.co.nz/webhooks/telegram/{tenant_id}
  → WebhookController::handleTelegram()
  → ProcessIncomingMessage job (queued)
  → TenantWorkspaceMessenger::send() → workspace
  → Reply sent back via Telegram API
  → ConversationLog::create()
```

---

## 2026-04-14 — Live Workspace Status, AI Usage Refresh & Notification Bell

Date: 2026-04-14
Branch: `cdx-feature/hot-fixes` (commit `8ee02f6`)
Status: Pending merge to `codex/control-app-prod-deploy`

### Overview

A set of UX and correctness improvements built on top of the Trial Lifecycle Management feature.
Fixes a stale workspace status shown to clients, adds on-demand AI credit refresh, corrects a confusing admin label, and introduces a persistent notification bell in the sidebar.

### Live Workspace State on Client Dashboard

**Problem:** The client dashboard was reading `provisioning_status` from the database (a cached value), which always said "Workspace is live" even when the actual Docker container was stopped. The superadmin tenants table showed "stopped" correctly because it makes a live Docker API call.

**Fix:** `DashboardController` now runs the same live Docker container state check (`workspaceState()` helper) as `AdminController`. The result is:
- **Workspace stat card** now reflects the real Docker state (`running` / `stopped` / `missing_config`)
- When stopped, the card shows "Workspace is stopped" in amber with a contact prompt

### Manual AI Credit Refresh Button

New ↻ refresh icon in the Trial & AI Usage panel header on the client dashboard.
- POST to `dashboard/refresh-trial-usage` → calls LiteLLM `/key/info` live
- Fetches real spend, caches it on the tenant record, returns updated data as JSON
- All UI values (spend label, progress bars, urgency badge, cached-at footer) update **in-place** without a page reload
- Icon spins during the request; error message appears inline on failure

**Route:** `POST /dashboard/refresh-trial-usage` (name: `dashboard.refresh-trial-usage`)

**Controller:** `DashboardController::refreshTrialUsage()`

### Admin Trial Time Label Fix

The superadmin tenant detail view was showing `7.1% of 14 days used` which is meaningless.

**Fixed to:** `1 of 14 days elapsed — 12 remaining` — plain day counts on both sides.

### Sidebar Notification Bell

A persistent notification bell is now rendered in the sidebar footer on every authenticated page.

**Design:**
- Bell icon + "Alerts" label
- Red dot badge + red count pill when alerts are active
- Click opens a dark card dropdown listing each alert with title, message, and CTA link
- Closes on outside click

**Alert sources (two-layer system):**

| Layer | Source | What triggers it |
|---|---|---|
| DB-level (all pages) | `AppServiceProvider` View composer | Trial expired, trial urgency `critical`, health check `failed` |
| Live Docker (dashboard only) | `DashboardController` → request attributes | Workspace `stopped` / `missing_config` / `unknown` |

The dashboard injects workspace alerts via `$request->attributes` before the view renders; the View composer in `AppServiceProvider` merges them into `$sidebarAlerts` when it runs.

### Files Changed

- `app/Http/Controllers/DashboardController.php` — `workspaceState()`, `refreshTrialUsage()`, request-attribute workspace alert injection
- `app/Providers/AppServiceProvider.php` — View composer registering `$sidebarAlerts`
- `resources/views/components/layouts/app.blade.php` — notification bell CSS + HTML + JS toggle
- `resources/views/dashboard.blade.php` — Workspace stat card live state, refresh button, removed full-width workspace banner
- `resources/views/admin/tenant-show.blade.php` — time label fix
- `routes/web.php` — `dashboard.refresh-trial-usage` route

---

## 2026-04-14 — Trial Backfill, Defensive Fallbacks & Admin Trial Metrics

Date: 2026-04-14
Branch: `codex/control-app-prod-deploy` (commit `6202226`)
Status: Released

### Overview

Follow-up to the Trial Lifecycle feature. Patches two correctness gaps for tenants that
pre-date the `trial_ends_at` column, adds code-level defensive fallbacks throughout, and
surfaces trial/spend metrics on both admin views for superadmin oversight.

### Backfill Migration

New migration: `2026_04_13_125055_backfill_trial_ends_at_for_existing_tenants`

- Sets `trial_ends_at = created_at + 14 days` for every existing tenant where the column is `NULL`
- Uses a PHP `lazyById()` loop (not raw SQL) for **SQLite + PostgreSQL** compatibility
- If `created_at + 14 days` is already in the past the scheduler will expire the tenant on its next 30-min run

### Defensive Fallbacks

Two code locations now fall back to `created_at + 14 days` if `trial_ends_at` is `NULL`:

- **`Tenant::trialDaysLeft()`** — returns correct remaining days instead of `0` for pre-backfill tenants
- **`sync360:check-trial-expiry` command** — `$trialEndsAt = $tenant->trial_ends_at ?? $tenant->created_at->copy()->addDays(14)` so the time-expiry check always fires correctly

### Admin Trial Metrics

**Tenants list (`/admin/tenants`) — new Trial column:**
- `expired` red badge when trial has ended
- `Nd left` badge colour-coded by urgency (green / amber / red)
- `$X.XX / $5.00` spend hint beneath the badge

**Tenant detail (`/admin/tenants/{slug}`) — new Trial & AI Usage section:**
- Dual progress bars (AI Credit + Trial Time) with urgency colour
- Full metadata grid: trial status, `trial_ends_at`, cached spend (4dp), `litellm_spend_cached_at`
- All three notification guard timestamps (`trial_80pct_notified_at`, `trial_3day_notified_at`, `trial_expired_notified_at`)
- LiteLLM plan name

---

## 2026-04-13 — Trial Lifecycle Management

Date: 2026-04-13
Branch: `cdx-feature/onbord-trial-management` → merged to `codex/control-app-prod-deploy`
Status: Released

### Overview

Enforces trial expiry under two independent conditions (whichever occurs first): 14-day time limit OR $5.00 AI credit exhausted. No grace period. Introduces the AI Usage dashboard widget and automated email notifications.

### Schema

- New migration: `add_trial_and_spend_fields_to_tenants`
- 6 new columns on `tenants`: `trial_ends_at`, `litellm_spend`, `litellm_spend_cached_at`, `trial_80pct_notified_at`, `trial_3day_notified_at`, `trial_expired_notified_at`
- `trial_ends_at` is set at signup = `created_at + 14 days` (UTC)

### RegisterController

- `trial_ends_at` is now set on Tenant creation at signup

### LiteLlmTenantKeyService

- Added `getKeyInfo(Tenant): array` — calls `GET /key/info` and returns `spend`, `max_budget`, `budget_reset_at`

### Scheduler Command — `sync360:check-trial-expiry`

- Runs every 30 minutes (defined in `routes/console.php`)
- Per active trial tenant: fetches live spend → caches on tenant → evaluates both expiry conditions → expires + suspends if triggered → sends email notifications at thresholds
- All notification events are idempotent (each email type fires at most once per tenant)

### TrialNotificationEmailService (NEW)

Three Brevo transactional emails:

| Trigger | Email Subject |
|---|---|
| Spend ≥ 80% of budget | "Your AI credit is almost used up" |
| ≤ 3 days remaining | "Your trial ends in N days" |
| Either condition expired | "Your Sync360 trial has ended" |

Expired email includes whether `budget` or `time` triggered expiry. CTA = `mailto:hello@sync360.co.nz`.

### Tenant Model

Five new accessor methods: `isTrialExpired()`, `trialDaysLeft()`, `trialBudgetPercent()`, `trialTimePercent()`, `trialUrgency()`.

### Dashboard

- New **Trial & AI Usage** panel with two colour-coded progress bars (AI Credit + Trial Time)
- Urgency badge: green (`ok`) / amber (`warning` ≥60%) / red (`critical` ≥85%)
- "Usage data as of X" footer showing staleness of cached spend
- Trial stat card now shows days remaining and live spend/budget instead of static text
- **Expired state:** full-width red top banner + "Trial ended" stat card

### Onboarding

- Step 6 Go Live button conditionally disabled when `isTrialExpired() === true`
- Error note with "Contact us" mailto link shown instead of the submission form

---

## 2026-04-13 — Onboarding UX Polish & Owner↔Assistant Framing

Date: 2026-04-13
Branch: `cdx-feature/hot-fixes` → merged to `codex/control-app-prod-deploy`
Status: Released

### Product Design Decision — Channel Scope (Phase 1)

**The Telegram and WhatsApp channel connection in this phase is strictly owner↔assistant. There are no public-facing customers in this phase.**

- The business owner connects their personal Telegram or WhatsApp account to their digital employee
- All messages on that channel are between the business owner and the AI digital employee
- The `ConversationLog` records therefore represent owner-initiated sessions, not inbound customer queries
- This is an explicit product decision for Phase 1 and must be preserved in copy, docs, and any future feature design

### Signup Redirect Fix
- `RegisterController::store()` now redirects to `/onboarding` immediately after signup instead of `/tenant/setup`
- Workspace provisioning continues in the background via the queue — the owner starts onboarding while the workspace is being prepared
- Updated `SignupFlowTest` to assert the new redirect target

### Onboarding Step Bar Label Shortening
- `OnboardingController::statePayload()` step labels updated for narrower screens:
  - `Business Website` → `Website`
  - `Personality` → `Tone`
  - `Capabilities` → `Skills`
- Updated all matching label assertions in `OnboardingFlowTest`

### Onboarding & Go-Live Copy Reframe
- All Step 5 (Channel) and Step 6 (Go Live) copy rewritten to reflect the owner↔assistant model
- Telegram setup guide updated: no longer implies customers message the bot — describes the owner connecting to their digital employee
- Step 6 workspace-not-ready messaging improved from "Still preparing" to "Setting up… this usually takes a few minutes"
- JS status strings for `goLiveNote` and `channelStatusNote` updated to match
- Button labels tightened: `Bring My Assistant Live` → `Go Live`, `Resync Live Assistant` → `Resync Assistant`, `Save Channel Connection` → `Connect Channel`
- Step 4 button: `Save Capabilities` → `Save & Prepare Files`

### Copy-to-Clipboard for Webhook URLs
- Added `Copy` buttons next to both the Telegram and WhatsApp webhook URL readonly inputs
- Implemented `copyField()` JS helper with clipboard API fallback

### Conversations Page Reframe
- Page headline changed to `Messages with Your Digital Employee`
- Sub-copy updated to describe owner↔assistant session log
- Stats renamed: `Matched` → `Sessions`, `Replied` → `Responded`
- Empty-state copy updated to reflect the owner-initiated model
- Added `Detailed session view coming soon.` note
- Channel filter dropdown now only shows the tenant's connected channel; unconnected channels show a disabled `No channel connected yet` option
- Per-channel stat widgets show `Not connected` in muted text if that channel is not the tenant's active channel

### Dashboard Activation Polish
- Conversation Activity widget gains a 4th `Channel` stat showing the connected channel with its brand icon (Telegram blue / WhatsApp green), or `Not connected` with a direct link to onboarding
- Channel display in the Digital Employee Status panel now renders inline SVG brand icons
- Post-live secondary CTA switches from `Edit Business Profile` to `View Messages` when agent is live
- Setup Progress badge for the active next step now renders in orange with `Next →` to distinguish it from completed and future pending steps
- Completed steps show `Done ✓` badge

---

## 2026-04-12 — LiteLLM Key Hardening & OpenClaw Provider Routing

Date: 2026-04-12
Branch: `codex/control-app-prod-deploy`
Status: Released

Summary:
- Hardened LiteLLM virtual key generation to enforce team and model restrictions on all tenant keys
- Lowered the default trial budget from $25 to $5/month
- Configured OpenClaw to route all AI calls through the LiteLLM proxy instead of directly to `api.openai.com`
- Set `gpt-4o` as the default agent model for all tenant OpenClaw instances
- Added self-healing config migration in `configureChannel()` so pre-existing tenants are automatically upgraded

### LiteLLM Key Restrictions

- Updated [LiteLlmTenantKeyService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/LiteLlmTenantKeyService.php) so `POST /key/generate` now includes `team_id` and `models` restrictions from centralized config
- Added `LITELLM_TEAM_ID` and `LITELLM_DEFAULT_MODELS` to [config/sync360.php](/Users/gayanhewage/Projects/openclaw-saas/config/sync360.php), [.env.example](/Users/gayanhewage/Projects/openclaw-saas/.env.example), and [.env.production.example](/Users/gayanhewage/Projects/openclaw-saas/.env.production.example)
- Updated `LITELLM_TRIAL_MAX_BUDGET` default from `25` to `5`

### OpenClaw Model & Provider Configuration

- Updated [OpenClawProvisioner.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/OpenClawProvisioner.php) to write `agents.defaults.model` and `models.providers.openai` config in the generated `openclaw.json`
- The `models` block uses `mode: "replace"` with `providers.openai.baseUrl` pointing to `litellm.stylesoftware.co.nz/v1`, overriding OpenClaw's built-in provider catalog that defaults to `api.openai.com`
- The `models.providers.openai.models` array uses the `[{id, name}]` object format required by OpenClaw's config schema
- Added `OPENCLAW_DEFAULT_AGENT_MODEL` to [config/sync360.php](/Users/gayanhewage/Projects/openclaw-saas/config/sync360.php), [.env.example](/Users/gayanhewage/Projects/openclaw-saas/.env.example), and [.env.production.example](/Users/gayanhewage/Projects/openclaw-saas/.env.production.example)
- Removed unused `OPENCLAW_MODEL` env var from `compose.yaml` template (OpenClaw does not read this env var)

### Channel Configuration Self-Healing

- Updated [TenantAgentSyncService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/TenantAgentSyncService.php) `configureChannel()` to:
  - Remove any stale top-level `agent` key written by earlier versions (invalid OpenClaw config key)
  - Inject `agents.defaults.model` if missing
  - Inject full `models.providers.openai` block pointing to LiteLLM if missing
  - This ensures pre-existing tenants are self-healed to the correct config on the next channel update

### Key Discoveries

- `OPENCLAW_MODEL` env var — **not recognized** by OpenClaw; has no effect
- `agent.model` in `openclaw.json` — **invalid top-level key**; causes gateway config validation failure
- `agents.defaults.model` in `openclaw.json` — **correct path** for setting the default agent model
- `models.providers.openai.baseUrl` in `openclaw.json` — **required** to override OpenClaw's built-in `api.openai.com` base URL
- LiteLLM team model restrictions and key model restrictions **must both allow the same model** or requests are rejected with 401

Verification:
- Direct `curl` to LiteLLM with tenant virtual key returns HTTP 200 with `gpt-4o` response
- OpenClaw gateway starts with `agent model: openai/gpt-4o` (confirmed in container logs)
- Telegram bot responds to messages successfully via `gpt-4o` through LiteLLM
- Gateway readiness endpoint returns `{"ready": true}`
- No config validation errors in OpenClaw startup logs

Operational notes:
- The LiteLLM team `Sync360` (`00a47146-4a89-4775-abff-57ef53ef20b5`) must have `gpt-4o` in its allowed models list
- Tenants provisioned before this change require a `configureChannel()` call or manual `openclaw.json` update to get the correct `models` config
- The `style-software` tenant was manually updated and verified working in this session

---

## Released

Date: 2026-04-12
Branch: `cdx-feature/tenant-workspace-login`
Status: In progress

Summary:
- Changed tenant workspace URLs so they now open Sync360 login/dashboard instead of the public OpenClaw gateway
- Moved tenant gateway `/chat` and `/readyz` access to private control-plane requests over the existing SSH channel
- Changed tenant Caddy routing so tenant subdomains reverse proxy to the Sync360 control app upstream, not the OpenClaw container
- Added strict tenant-host access rules so one signed-in customer cannot use another tenant’s workspace subdomain
- Added a dedicated super-admin tenant detail page and simplified the tenant list into a compact overview
- Added strict permanent tenant deletion with full infrastructure teardown, LiteLLM key removal, and linked customer-user deletion
- Added local development bypass for tenant deletion so localhost runtimes are removed without SSH
- Added the first end-to-end managed onboarding flow for Sync360 customers without exposing OpenClaw internals
- Added AI-assisted business extraction and assistant file generation using the control app LiteLLM virtual key
- Added channel connection, go-live sync, webhook routing, and conversation logging for WhatsApp and Telegram
- Added a richer customer dashboard, dedicated conversation browsing, editable business profile management, live assistant resync, and tenant health tooling
- Polished channel onboarding so customers now get tenant-specific webhook setup details directly inside the guided flow
- Added managed Telegram channel connection — bot token is written directly to `openclaw.json` and the gateway is restarted automatically
- Added channel disconnect flow with config cleanup and gateway restart
- Simplified onboarding UX by removing all OpenClaw/CLI references and adding brand icons
- Fixed local development SSH bypass for admin workspace start/stop/restart actions

### 2026-04-12 — Tenant Workspace URL Now Lands In Sync360

**Customer Workspace URL Behavior**
- `tenants.workspace_url` remains the canonical customer URL, but it now represents the Sync360 entrypoint rather than the public OpenClaw gateway
- Added [LandingController.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/LandingController.php) so `https://<slug>.workspace...`:
  - redirects guests to login
  - redirects matching signed-in customers to dashboard
  - blocks mismatched signed-in users from opening another tenant’s host
- Added [EnsureWorkspaceTenantAccess.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Middleware/EnsureWorkspaceTenantAccess.php), [WorkspaceHostResolver.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/WorkspaceHostResolver.php), and [workspace-access.blade.php](/Users/gayanhewage/Projects/openclaw-saas/resources/views/errors/workspace-access.blade.php) for strict tenant-subdomain enforcement
- Updated customer-facing CTAs and ready-email copy so they consistently describe opening the Sync360 workspace instead of opening the gateway

**Private Gateway Access**
- Extended [DockerComposeRunner.php](/Users/gayanhewage/Projects/openclaw-saas/app/Contracts/DockerComposeRunner.php) with private HTTP request support
- Added [TenantGatewayService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/TenantGatewayService.php)
- Updated [TenantWorkspaceMessenger.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/TenantWorkspaceMessenger.php) and [TenantHealthCheckService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/TenantHealthCheckService.php) to use tenant-local `http://127.0.0.1:<assigned_port>` gateway access instead of the public workspace URL
- Implemented SSH-backed private gateway requests in [SshDockerComposeRunner.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/SshDockerComposeRunner.php) and matching local behavior in [LocalDockerComposeRunner.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/LocalDockerComposeRunner.php)

**Provisioning And Routing**
- Updated [OpenClawProvisioner.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/OpenClawProvisioner.php) so generated `workspace.caddy` files reverse proxy tenant subdomains to the Sync360 control app upstream instead of the OpenClaw container
- Added `SYNC360_WORKSPACE_CONTROL_APP_UPSTREAM` in [config/sync360.php](/Users/gayanhewage/Projects/openclaw-saas/config/sync360.php), [.env.example](/Users/gayanhewage/Projects/openclaw-saas/.env.example), and [.env.production.example](/Users/gayanhewage/Projects/openclaw-saas/.env.production.example)
- Changed public provisioning verification from `https://<tenant>/readyz` to `https://<tenant>/login`
- Disabled public OpenClaw control UI exposure in generated `openclaw.json`
- Updated production example session sharing to `.sync360.co.nz` so auth can work across `app.sync360.co.nz` and tenant workspace subdomains

Verification:
- `php artisan test` passed with `66 passed` and `528 assertions`
- Added [WorkspaceHostAccessTest.php](/Users/gayanhewage/Projects/openclaw-saas/tests/Feature/WorkspaceHostAccessTest.php) covering guest redirects, matching-tenant access, and mismatched-tenant blocking

### 2026-04-12 — Superadmin Tenant Detail & Permanent Delete

**Admin Tenant UX**
- Slimmed [admin/tenants.blade.php](/Users/gayanhewage/Projects/openclaw-saas/resources/views/admin/tenants.blade.php) into a compact list that keeps only the key operational signals in each row
- Added tenant detail route support in [routes/web.php](/Users/gayanhewage/Projects/openclaw-saas/routes/web.php) and [AdminController.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/AdminController.php) with `GET /admin/tenants/{tenant}`
- Added [tenant-show.blade.php](/Users/gayanhewage/Projects/openclaw-saas/resources/views/admin/tenant-show.blade.php) with grouped sections for customer details, runtime metadata, onboarding summary, latest job state, support actions, and a danger zone

**Permanent Tenant Delete**
- Added [TenantDeletionService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/TenantDeletionService.php) to orchestrate strict synchronous deletion
- Permanent delete now:
  - tears down the tenant Docker Compose project
  - removes the tenant Caddy config and reloads Caddy when managed
  - deletes the remote tenant runtime directory
  - deletes the local staged runtime directory
  - deletes the LiteLLM virtual key
  - deletes the linked non-admin customer account, letting tenant-owned records cascade from the database
- Added delete route `DELETE /admin/tenants/{tenant}` in [routes/web.php](/Users/gayanhewage/Projects/openclaw-saas/routes/web.php)
- Added slug-confirmation protection on the detail page before permanent deletion is enabled
- Deletion now blocks if remote cleanup fails, if LiteLLM key deletion fails, or if the tenant is linked to an admin account

**Infrastructure Support**
- Extended [DockerComposeRunner.php](/Users/gayanhewage/Projects/openclaw-saas/app/Contracts/DockerComposeRunner.php) with remote directory removal support
- Implemented `removeDirectory()` in [LocalDockerComposeRunner.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/LocalDockerComposeRunner.php) and [SshDockerComposeRunner.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/SshDockerComposeRunner.php)
- Added local-development deletion bypass in [TenantDeletionService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/TenantDeletionService.php) so localhost tenant deletion uses local `docker compose down` instead of SSH

Verification:
- `php artisan test --filter=AdminTenantDeletionTest` passed
- `php artisan test` passed with `63 passed` and `521 assertions`

### 2026-04-12 — Managed Channel Connection & Admin SSH Bypass

**Managed Telegram Connection**
- Added `configureChannel()` in [TenantAgentSyncService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/TenantAgentSyncService.php) — reads the tenant's `openclaw.json`, merges `channels.telegram` config (`enabled`, `botToken`, `dmPolicy: "open"`), writes it back, and restarts the gateway container
- Added `removeChannelConfig()` in [TenantAgentSyncService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/TenantAgentSyncService.php) — removes the `channels` key from `openclaw.json` on disconnect and restarts the gateway
- Updated `saveChannel()` in [OnboardingController.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/OnboardingController.php) to call `configureChannel()` after saving to DB, with graceful fallback if config write fails
- Added `disconnectChannel()` endpoint and route `POST /onboarding/channel/disconnect` in [OnboardingController.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/OnboardingController.php) and [routes/web.php](/Users/gayanhewage/Projects/openclaw-saas/routes/web.php)

**Channel UI Overhaul**
- Added connected status panel in [show.blade.php](/Users/gayanhewage/Projects/openclaw-saas/resources/views/onboarding/show.blade.php) — shows channel icon + green "Connected" badge + "Disconnect" button when a channel is active; hides the connection form
- Added WhatsApp "Coming Soon" badge (disabled, greyed out) — always visible in both connected and disconnected states
- Added SVG brand icons for WhatsApp (green) and Telegram (blue) throughout the channel step
- Rewrote all channel setup guides to be customer-friendly — no CLI commands, no OpenClaw references, no technical jargon
- Rewrote all status note messages to plain language ("Telegram is connected", "Your assistant is ready to go")

**Bug Fixes**
- Fixed `channel_config` column type mismatch — migrated from `json` to `text` in [change_channel_config_to_text_on_tenants_table](/Users/gayanhewage/Projects/openclaw-saas/database/migrations/2026_04_12_071749_change_channel_config_to_text_on_tenants_table.php) to fix incompatibility between `encrypted:array` cast and PostgreSQL's JSON column validation

**Admin SSH Bypass (Local Dev)**
- Updated `startWorkspace()`, `stopWorkspace()`, `restartWorkspace()`, and `workspaceStateFor()` in [AdminController.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/AdminController.php) to bypass SSH and run `docker compose` commands locally when `APP_ENV=local`
- Added `localDockerCompose()` helper method for running compose commands against the local runtime directory
- Injected [TenantRuntimeService](/Users/gayanhewage/Projects/openclaw-saas/app/Services/TenantRuntimeService.php) into AdminController for local path resolution

Verification:
- 57/57 tests passing (459 assertions)
- Telegram bot token successfully written to `openclaw.json` on save
- Channel disconnect removes `channels` key from `openclaw.json`
- Admin workspace start/stop/restart working on localhost without SSH

Notable changes:
- Added onboarding data model and tenant state for guided activation:
  - [create_business_profiles_table](/Users/gayanhewage/Projects/openclaw-saas/database/migrations/2026_04_12_090000_create_business_profiles_table.php)
  - [create_business_profile_files_table](/Users/gayanhewage/Projects/openclaw-saas/database/migrations/2026_04_12_090100_create_business_profile_files_table.php)
  - [add_onboarding_fields_to_tenants_table](/Users/gayanhewage/Projects/openclaw-saas/database/migrations/2026_04_12_090200_add_onboarding_fields_to_tenants_table.php)
- Added onboarding models and relations in [BusinessProfile.php](/Users/gayanhewage/Projects/openclaw-saas/app/Models/BusinessProfile.php), [BusinessProfileFiles.php](/Users/gayanhewage/Projects/openclaw-saas/app/Models/BusinessProfileFiles.php), and [Tenant.php](/Users/gayanhewage/Projects/openclaw-saas/app/Models/Tenant.php)
- Extended [RegisterController.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/Auth/RegisterController.php) so signup now seeds onboarding records while keeping the existing provisioning flow and `/tenant/setup` redirect intact
- Added the 6-step onboarding flow in [OnboardingController.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/OnboardingController.php), [show.blade.php](/Users/gayanhewage/Projects/openclaw-saas/resources/views/onboarding/show.blade.php), and [routes/web.php](/Users/gayanhewage/Projects/openclaw-saas/routes/web.php)
- Added [BusinessExtractionService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/BusinessExtractionService.php) to:
  - extract business details from a website
  - generate assistant identity, soul, user, bootstrap, profile, and heartbeat files
  - use `LITELLM_VIRTUAL_KEY` for control-app AI calls with a deterministic local fallback when AI is unavailable
- Added Step 5 and Step 6 onboarding activation through [TenantAgentSyncService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/TenantAgentSyncService.php) and [TenantRuntimeService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/TenantRuntimeService.php)
- Added public WhatsApp and Telegram webhook handling in [WebhookController.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/WebhookController.php), outbound senders in [WhatsAppSender.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/Channels/WhatsAppSender.php) and [TelegramSender.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/Channels/TelegramSender.php), and workspace message forwarding in [TenantWorkspaceMessenger.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/TenantWorkspaceMessenger.php)
- Added queued inbound message processing in [ProcessIncomingMessage.php](/Users/gayanhewage/Projects/openclaw-saas/app/Jobs/ProcessIncomingMessage.php)
- Added conversation logging with [ConversationLog.php](/Users/gayanhewage/Projects/openclaw-saas/app/Models/ConversationLog.php) and [create_conversation_logs_table](/Users/gayanhewage/Projects/openclaw-saas/database/migrations/2026_04_12_120000_create_conversation_logs_table.php)
- Added customer dashboard onboarding and activity visibility in [DashboardController.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/DashboardController.php) and [dashboard.blade.php](/Users/gayanhewage/Projects/openclaw-saas/resources/views/dashboard.blade.php)
- Added a dedicated customer conversation browser in [ConversationsController.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/ConversationsController.php), [conversations/index.blade.php](/Users/gayanhewage/Projects/openclaw-saas/resources/views/conversations/index.blade.php), and [ConversationBrowserFlowTest.php](/Users/gayanhewage/Projects/openclaw-saas/tests/Feature/ConversationBrowserFlowTest.php)
- Added customer profile editing and live assistant resync in [ProfileController.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/ProfileController.php), [show.blade.php](/Users/gayanhewage/Projects/openclaw-saas/resources/views/profile/show.blade.php), and [TenantProfileSyncService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/TenantProfileSyncService.php)
- Added tenant health checks and support actions in [TenantHealthCheckService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/TenantHealthCheckService.php), [AdminController.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/AdminController.php), [admin/tenants.blade.php](/Users/gayanhewage/Projects/openclaw-saas/resources/views/admin/tenants.blade.php), and scheduled `tenants:health-check` in [routes/console.php](/Users/gayanhewage/Projects/openclaw-saas/routes/console.php)
- Polished Step 5 channel onboarding in [OnboardingController.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/OnboardingController.php) and [show.blade.php](/Users/gayanhewage/Projects/openclaw-saas/resources/views/onboarding/show.blade.php) so WhatsApp verify tokens can auto-generate and customers can see their tenant-specific WhatsApp and Telegram webhook setup details in the guided setup
- Updated config and environment examples for LiteLLM and channel integrations in [config/services.php](/Users/gayanhewage/Projects/openclaw-saas/config/services.php), [config/sync360.php](/Users/gayanhewage/Projects/openclaw-saas/config/sync360.php), [.env.example](/Users/gayanhewage/Projects/openclaw-saas/.env.example), and [.env.production.example](/Users/gayanhewage/Projects/openclaw-saas/.env.production.example)
- Exempted `webhooks/*` from CSRF validation in [bootstrap/app.php](/Users/gayanhewage/Projects/openclaw-saas/bootstrap/app.php) so external providers can deliver inbound messages safely

Verification:
- `php artisan test --filter=OnboardingFlowTest` passed
- `php artisan test --filter=WebhookFlowTest` passed
- `php artisan test --filter=DashboardFlowTest` passed
- `php artisan test --filter=ProfileFlowTest` passed
- `php artisan test --filter=ConversationBrowserFlowTest` passed
- `php artisan test --filter=TenantHealthCheckFlowTest` passed
- `php artisan test --filter=AdminTenantOperationsTest` passed
- `php artisan test --filter=SignupFlowTest` passed
- `php artisan test --filter=AdminDebugTest` passed
- `php artisan test --filter=ProvisioningFlowTest` passed
- `php artisan test --filter=WebhookFlowTest` passed
- `php artisan test --filter=WebScraperServiceTest` passed (8 unit tests — Jina success, link discovery, exclusion, fallback, truncation, external links, API key)
- `php artisan test --filter=OnboardingFlowTest` passed after adding Jina Reader HTTP fakes (20 tests, 107 assertions)
- `docker compose exec -T app php artisan migrate --force` applied the new onboarding and conversation-log tables in the local runtime
- Live end-to-end pipeline tested against `stylesoftware.co.nz`: 6 pages scraped (30,617 chars), 18 services extracted, all profile fields populated correctly

Operational notes:
- Control-app AI features now use `LITELLM_VIRTUAL_KEY`
- Tenant key creation and management continue to use `LITELLM_MASTER_KEY`
- Deploying this slice requires running Laravel migrations before using the new dashboard, onboarding, profile, or webhook flows
- Test suites are stable when run sequentially; running multiple suites in parallel can still hit the shared temp-directory collision in [tests/TestCase.php](/Users/gayanhewage/Projects/openclaw-saas/tests/TestCase.php)
- `JINA_BASE_URL` and `JINA_API_KEY` added to `.env.example` and `.env.production.example`; no key is required for the free Jina tier (covers thousands of onboardings/month)
- LiteLLM proxy had `vector_store_ids: []` set on the `claude-sonnet-4-6` model config — removed via the LiteLLM admin API; all Anthropic calls now succeed
- Pre-existing tenants (created before this migration) will have their `business_profiles` and `business_profile_files` rows created automatically on first use of any onboarding step

Extra notable changes (onboarding extraction fix, follow-up to `94a4b68`):
- Added [WebScraperService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/WebScraperService.php) — smart multi-page business website scraper using Jina Reader. Fetches homepage then discovers and scores internal links (`about=10`, `services=10`, `contact=8`, `faq=8`, `pricing=8` etc.), fetching up to 5 additional pages. Falls back to direct HTTP + HTML stripping. Output capped at 100k chars.
- Added [WebScrapingFailedException.php](/Users/gayanhewage/Projects/openclaw-saas/app/Exceptions/WebScrapingFailedException.php)
- Added [WebScraperServiceTest.php](/Users/gayanhewage/Projects/openclaw-saas/tests/Unit/WebScraperServiceTest.php) — 8 unit tests
- Fixed [BusinessExtractionService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/BusinessExtractionService.php) — was sending a raw URL to Claude (which has no web browsing). Now uses two-stage pipeline: scrape via `WebScraperService` → send scraped markdown to Claude. Prompt placeholder changed from `{{URL}}` to `{{PAGE_CONTENT}}`.
- Fixed [OnboardingController.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/OnboardingController.php) — `extractBusiness`, `saveBusinessInfo`, `savePersonality`, and `saveCapabilities` now use `firstOrCreate` for `BusinessProfile` and `BusinessProfileFiles` so pre-existing tenants without these rows are handled gracefully instead of silently discarding data
- Updated [show.blade.php](/Users/gayanhewage/Projects/openclaw-saas/resources/views/onboarding/show.blade.php) — Step 1 now shows an animated three-stage progress indicator (spinning SVG + step labels) while the 30–45s scrape and extraction runs. Button disables during fetch and re-enables on completion or error.

## 2026-04-11 - Workspace Emails And Control App Deploy

Date: 2026-04-11
Branch: `codex/control-app-prod-deploy`
Status: Released

Summary:
- Added Brevo-based workspace-ready email delivery
- Added a super-admin-safe control-app deploy trigger for the primary server
- Hardened the control-app deploy status and remote shell handling for production
- Added realtime deploy status polling and latest deployed commit visibility in the super-admin UI
- Updated documentation and production env examples for both features

Notable changes:
- Added [WorkspaceReadyEmailService](/Users/gayanhewage/Projects/openclaw-saas/app/Services/WorkspaceReadyEmailService.php) to send a confirmation email after successful provisioning
- The workspace-ready email now includes:
  - confirmation that the workspace has been created
  - workspace link
  - username
  - initial password
- Initial passwords are stored encrypted in the provisioning job payload and removed after a successful email send
- Brevo failures are logged without marking an already-ready tenant as failed
- Added [ControlAppDeploymentService](/Users/gayanhewage/Projects/openclaw-saas/app/Services/ControlAppDeploymentService.php) for safe super-admin-triggered control-plane deployments
- Added host-side deploy script [run-control-app-deploy.sh](/Users/gayanhewage/Projects/openclaw-saas/deploy/scripts/run-control-app-deploy.sh)
- Added `/admin` deploy controls, live status polling, latest commit visibility, and status/log visibility in [admin/index.blade.php](/Users/gayanhewage/Projects/openclaw-saas/resources/views/admin/index.blade.php)
- Added deploy status JSON endpoint in [routes/web.php](/Users/gayanhewage/Projects/openclaw-saas/routes/web.php) and [AdminController.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/AdminController.php)
- Hardened deploy status reads so `/admin` stays available when deploy secrets are missing or misconfigured
- Fixed remote deploy shell execution by removing the extra `sh -lc` wrapping in [ControlAppDeploymentService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/ControlAppDeploymentService.php)
- Fixed the deploy status probe command to use proper statement separators for the remote shell
- Made the host-side deploy status file the source of truth for the latest deployed commit SHA and commit message, so the admin panel stays aligned with the code that was actually deployed
- Added branch-tip comparison for the deploy panel, so it now shows `Up-to-date` when production already matches `codex/control-app-prod-deploy` and only shows `Fetch Latest And Deploy` when a newer branch commit is available
- Hardened the host deploy script so it resolves the remote branch head first, fast-forwards to that exact commit, and fails the deployment if the checked-out HEAD does not match the intended remote commit
- Changed the UI deploy trigger to fetch the latest deploy script from `FETCH_HEAD` and execute that fetched script directly, so deploy-script updates take effect immediately instead of waiting for a separate manual bootstrap run
- Added deploy-related env config in [.env.example](/Users/gayanhewage/Projects/openclaw-saas/.env.example), [.env.production.example](/Users/gayanhewage/Projects/openclaw-saas/.env.production.example), and [config/sync360.php](/Users/gayanhewage/Projects/openclaw-saas/config/sync360.php)
- Updated [README.md](/Users/gayanhewage/Projects/openclaw-saas/README.md) and [ARCHITECTURE.md](/Users/gayanhewage/Projects/openclaw-saas/ARCHITECTURE.md)

Verification:
- `php artisan test` passes with `16 passed` and `171 assertions`
- `php artisan test --filter=AdminDebugTest --stop-on-failure` passes
- `php artisan test --filter=ControlAppDeploymentServiceTest --stop-on-failure` passes
- `sh -n deploy/scripts/run-control-app-deploy.sh` passes
- PHP syntax checks pass for the new deploy and email services
- Live Brevo test emails were accepted for delivery to `gayan.c@outlook.com`

Operational notes:
- Local sender is now configured as `hello@sync360.co.nz`
- Super-admin deploy requires the primary server env values for `SYNC360_CONTROL_DEPLOY_*`
- The `/admin` deploy panel now degrades gracefully when deploy SSH secrets are missing or misconfigured in production, instead of throwing a 500
- The deploy card now polls automatically, shows the latest deployed commit SHA and subject, and keeps status/log output fresh without a page reload
- The deploy script now records the fetched-and-deployed commit metadata after `git pull`, and the status endpoint only falls back to a live git lookup when no deploy status file exists yet
- The deploy status endpoint now also checks the current `origin/<branch>` tip and exposes whether production is already up to date, which drives the deploy button label and disabled state in the super-admin UI
- The host deploy script now verifies that the local checked-out HEAD exactly matches the target remote branch head before it rebuilds containers, preventing false-success deploys when the branch was not actually advanced
- The SSH trigger now bootstraps deployment from the just-fetched branch content, avoiding the self-update trap where an older checked-out deploy script would keep running outdated logic

## 2026-04-11 - Production Deployment Packaging

Date: 2026-04-11
Branch: `codex/control-app-prod-deploy`
Commit: `1de117e`

Summary:
- Added a production-ready packaging path for the control app

Notable changes:
- Added [docker-compose.prod.yml](/Users/gayanhewage/Projects/openclaw-saas/docker-compose.prod.yml)
- Added production startup scripts:
  - [start-prod-app.sh](/Users/gayanhewage/Projects/openclaw-saas/docker/start-prod-app.sh)
  - [start-prod-worker.sh](/Users/gayanhewage/Projects/openclaw-saas/docker/start-prod-worker.sh)
- Updated [Dockerfile](/Users/gayanhewage/Projects/openclaw-saas/Dockerfile) with a production image target
- Added [.env.production.example](/Users/gayanhewage/Projects/openclaw-saas/.env.production.example)
- Added Apache reverse-proxy template [app.sync360.co.nz.conf](/Users/gayanhewage/Projects/openclaw-saas/deploy/apache/app.sync360.co.nz.conf)
- Added trusted-proxy handling in [bootstrap/app.php](/Users/gayanhewage/Projects/openclaw-saas/bootstrap/app.php)
- Made super-admin seeding production-safe in [DatabaseSeeder.php](/Users/gayanhewage/Projects/openclaw-saas/database/seeders/DatabaseSeeder.php)

Verification:
- `php artisan test` passed
- `docker compose -f docker-compose.prod.yml config` passed
- `docker build --target production -t sync360-control-app:prod-test .` passed

## 2026-04-11 - VPS-Ready Client Deployment Architecture

Date: 2026-04-11
Branch: `codex/vps-ready-architecture`
Commit: `92d95ee`

Summary:
- Refactored the app for primary-server plus client-VPS provisioning

Notable changes:
- Added server-aware tenant placement and provisioning
- Added SSH-based remote Docker orchestration to the client VPS
- Added tenant-specific Caddy routing and public HTTPS readiness checks
- Added production-like validation for remote provisioning to `89.116.28.191`
- Updated architecture and deployment documentation

Verification:
- Automated tests passed
- Remote client VPS bootstrap and tenant provisioning were validated successfully

## 2026-04-11 - Sync360 Control App MVP

Date: 2026-04-11
Branch: `main`
Commit: `498fb67`

Summary:
- Initial Laravel MVP for the Sync360 Control App

Notable changes:
- Landing page, signup, login, dashboard, tenant setup, and admin/debug flows
- User, tenant, provisioning job, and server data model
- Queue-backed provisioning flow with Redis and PostgreSQL
- Local Docker Compose development stack
- Blade-first UI and local runtime generation

Verification:
- Core end-to-end local flow was implemented and tested
