# Release Notes

> [!IMPORTANT]
> Canonical historical changelog. Use this file to understand what changed and when, not as the primary source for current architecture. For current truth, start with [`artifacts/MEMORY.md`](MEMORY.md) and [`artifacts/ARCHITECTURE.md`](ARCHITECTURE.md). Older entries may describe Telegram webhook-era experiments or broader WhatsApp plans that no longer match the implemented system.

This file tracks product and engineering changes for the Sync360 Control App.

Newest updates appear first.

## 2026-04-22 — Fix: Reliable Custom Skill Tool Contracts

Date: 2026-04-22
Status: Implemented

### Overview

Hardened the Inbox Triage skill and shared custom-skill authoring guidance after live runtime verification showed the agent could classify a high-value lead but misuse Telegram, Drive, and analytics identifiers.

### What Changed

- bumped `inbox-triage` through `1.5.7`
- added a strict Telegram notification tool contract for normal sends and forbade poll-only fields on high-value lead alerts
- simplified Drive triage logging to a deterministic local markdown file and the minimal `gog drive upload <localPath>` command shape
- clarified that internal inbox-triggered skill work must not use public web research unless the owner asks
- strengthened analytics guidance so qualified-lead analytics are attempted independently even when Telegram or Drive logging fails
- updated custom skill authoring and generated tenant workspace guidance with reusable Telegram and `gog` tool-contract guardrails
- front-loaded the critical runtime contracts after live replay showed the agent initially read only the top of `SKILL.md`, and forbade workspace patch/file-edit tools as a substitute for external Drive logging
- made the Gmail message id the primary lead id for Gmail-triggered events, requiring exact `Lead ref: <gmail_message_id>` wording and a helper-valid analytics payload with `event_id` and required outcome fields
- front-loaded the high-value Telegram gate so medium/ambiguous leads do not notify Telegram, while concrete commercial quote/request-for-service emails with site scope, timeline, contact details, or urgency are treated as high intent unless the tenant profile excludes them

## 2026-04-22 — Fix: Inbox Triage Same-Thread Polling And Workspace Skill Resync

Date: 2026-04-22
Status: Implemented

### Overview

Fixed two live inbox-triage issues: new emails in an already-known Gmail thread could be skipped as duplicates before the skill saw them, and workspace-only resyncs could update `HEARTBEAT.md` without refreshing the materialized workspace skill files.

### What Changed

- changed inbox-triage polling to de-dupe on the actual Gmail message id returned by `gog gmail get`, not the raw Gmail search summary id
- added regression coverage for a new business email arriving in an existing thread and still reaching the assigned skill
- updated `goLive()` / workspace-only resync to write materialized workspace skill files under `.openclaw/workspace/skills/<skill-id>/...`, not just the top-level markdown files
- added regression coverage proving go-live workspace sync now refreshes the live `inbox-triage` skill file alongside `HEARTBEAT.md`
- tightened the inbox trigger prompt so hook-driven inbox-triage runs are framed as internal operating tasks that must execute the required workflow now instead of only returning an assessment or plan

## 2026-04-22 — Fix: Lead Reference For Inbox Triage Follow-Ups

Date: 2026-04-22
Status: Implemented

### Overview

Fixed the practical Telegram follow-up path for Inbox Triage by adding a visible Gmail lead reference to high-value notifications and teaching owner follow-up flows to reuse that exact reference.

### What Changed

- bumped `inbox-triage` to `1.5.3`
- updated the skill so high-value Telegram notifications must include `Lead ref: <gmail_message_id>` and optional thread reference
- updated owner follow-up guidance so replies like `Get from email` and `Generate a quote` reopen the original Gmail message with `gog gmail get <Lead ref>`
- added fallback guidance to ask the owner to reply to the original lead notification again when no lead reference is available
- added regression coverage for the generated heartbeat guidance and aligned inbox-triage test fixtures to the new skill version

## 2026-04-22 — Fix: Inbox Triage Skill Workflow Contract

Date: 2026-04-22
Status: Implemented

### Overview

Tightened the `inbox-triage` custom skill after live verification showed Telegram and analytics completed but the vague Google Drive logging bullet was skipped.

### What Changed

- bumped `inbox-triage` to `1.5.2`
- replaced loose behavior bullets with an ordered workflow covering evaluation, stable lead id, Telegram, Google Drive triage logging, analytics, and final reporting
- added concrete Drive triage-log guidance for folder/file naming, idempotency, minimum fields, privacy boundaries, `gog drive` command shape, validation, and failure handling
- updated the custom skill authoring prompt so future skills must define required side effects with tool guidance, de-dupe keys, success validation, and failure reporting instead of vague action bullets

## 2026-04-21 — Fix: Inbox Triage Private Gateway Delivery

Date: 2026-04-21
Status: Implemented

### Overview

Fixed Inbox Triage delivery after live debugging showed Gmail polling detected the high-value email but failed to wake the tenant agent because Sync360 was posting to a non-existent private `/chat` route.

### What Changed

- changed `TenantWorkspaceMessenger` to send neutral inbox events through OpenClaw's private `/hooks/agent` ingress with `deliver=false`
- added private hook authorization headers to local and SSH gateway HTTP requests
- updated tenant config composition to enable OpenClaw `hooks` using a stable token that is distinct from the gateway auth token
- updated the inbox trigger prompt to route agents to the workspace skill file at `./skills/inbox-triage/SKILL.md` and avoid the bundled `/app/skills` path
- ensured go-live workspace writes create parent directories for nested helper files such as `.sync360/bin/log-skill-conversion` without broadening go-live beyond workspace files
- added regression coverage for hook delivery payloads and generated hook config

## 2026-04-21 — Feature: Admin System Health Panel

Date: 2026-04-21
Status: Implemented

### Overview

Added portable scheduler and queue-worker visibility to the Admin Overview so operators can tell whether scheduler-backed work such as Inbox Triage polling and skill analytics sync is actually moving.

### What Changed

- added durable `system_health_signals` for scheduler, queue worker, and critical scheduled-command health
- added `sync360:system-health-heartbeat`, scheduled every minute, to record scheduler activity and dispatch a lightweight queue-worker heartbeat job
- instrumented critical scheduled commands with start/success/failure health hooks
- added database queue backlog metrics for pending, reserved, failed, and oldest pending jobs when the active queue driver is `database`
- added `GET /admin/system-health/status` and a polling System Health panel on Admin Overview
- documented the admin UI pattern in the Blade design-system source of truth

## 2026-04-21 — Feature: Inbox Triage Polling Trigger

Date: 2026-04-21
Status: Implemented

### Overview

Implemented Sync360's proactive Gmail polling trigger for the `inbox-triage` skill while preserving the agreed boundary that the skill, not Sync360, decides final lead quality and actions.

### What Changed

- added per-tenant inbox monitor state and processed-message metadata tables for enabled/status/backoff/error tracking, de-dupe, delivery attempts, skip reasons, and operational timestamps
- added `sync360:poll-inbox-triage`, scheduled every five minutes, plus queued per-tenant `ProcessTenantInboxTriage` jobs for live/ready tenants with verified Google Workspace and an enabled `inbox-triage` assignment
- added tenant Gmail polling through the tenant container's configured `gog` runtime, using `gmail search` and `gmail get` without changing `goLive()` or full runtime sync behavior
- added mechanical business-importance filtering that skips obvious promotions/social/spam/trash, no-reply/system traffic, newsletters, delivery failures, and auto-generated/list mail before invoking the agent
- routed only business-plausible Gmail events to the tenant agent through `TenantWorkspaceMessenger` on the private gateway with the neutral `sync360-inbox-monitor` contract
- kept Telegram destination as optional context only; Sync360 does not send Telegram notifications, classify high-value leads, or reinterpret agent outcomes
- bumped `inbox-triage` to `1.5.1` and updated its instructions so `sync360-inbox-monitor` events route through `skills/inbox-triage/SKILL.md`

## 2026-04-21 — Fix: Remote Skill Analytics Sync Transport

Date: 2026-04-21
Status: Implemented

### Overview

Fixed remote skill analytics import without changing the per-tenant runtime SQLite design. The control plane now reads tenant analytics from inside the live tenant container instead of requiring `sqlite3` to be installed on the client VPS host.

### What Changed

- added a dedicated runtime-storage service for tenant skill analytics reads, row counts, and prune operations
- replaced remote host `sqlite3` usage with `docker exec ... node --input-type=module` against the tenant container's mounted analytics DB
- made `sync360:sync-skill-conversions` resilient per tenant so one remote transport failure no longer aborts the whole sync run
- persisted per-tenant sync failure state with last failure time and message
- extended `sync360:inspect-tenant-skills` to report runtime SQLite row count, last imported runtime row id, last synced time, and last sync failure details

## 2026-04-21 — Fix: Skill Rollout And Apply Visibility

Date: 2026-04-21
Status: Implemented

### Overview

Clarified the admin skill-version workflow so publish, rollout, and runtime apply are shown as separate state transitions, while keeping tenant skill assignments version-pinned until an explicit rollout.

### What Changed

- tenant skills now show assigned version and latest published version separately, plus `Update available` / `Up to date`
- skill detail now shows `Tenants On This Version`, `Outdated Tenants`, rollout candidate lists, and guarded `Roll Out To Selected Tenants` / `Roll Out To All Outdated Tenants` actions
- rollout now supports a guarded `scope=all_outdated` path that upgrades only outdated enabled assignments and queues one runtime apply job per affected tenant
- save/apply success banners now describe the exact state transition instead of implying publish or draft save changed tenant runtime state
- admin skill and tenant screens now poll lightweight JSON endpoints to show rollout/apply progress while jobs are queued or running

## 2026-04-21 — Sync360 Custom Skill Runtime Adapter

Date: 2026-04-21
Status: Implemented

### Overview

Made Sync360 the explicit source of truth for custom skill catalog and materialization while aligning runtime activation with OpenClaw's documented workspace skill loader.

### What Changed

- added explicit `runtime_type` manifest validation for `sync360_workspace`, `openclaw_native`, and `runtime_capability`
- kept hello-world, conversion-test, and inbox-triage as `sync360_workspace` skills with matching OpenClaw workspace skill IDs
- included runtime type and OpenClaw skill IDs in generated tenant `AGENTS.md` assigned-skill guidance
- added `sync360:inspect-tenant-skills <tenant>` to report assigned skills, materialized workspace files, analytics registry state, SQLite DB state, OpenClaw config allowlists, runtime-visible skills when available, and mismatch warnings
- updated authoring and architecture docs so Sync360 custom skills are loaded from `.openclaw/workspace/skills/<skill-id>/`, while `/app/skills` remains a bundled/managed skill location

## 2026-04-21 — Fix: Markdown Custom Skills Avoid OpenClaw Built-In Loader

Date: 2026-04-21
Status: Implemented

### Overview

Fixed markdown-only Sync360 custom skills so assigning them does not make OpenClaw try to read missing bundled skill files from `/app/skills/<skill-id>/SKILL.md`.

### What Changed

- changed default catalog normalization so raw OpenClaw skill IDs are opt-in instead of defaulting to the Sync360 skill key
- removed raw OpenClaw skill registration from the hello-world, conversion-test, and inbox-triage packs
- updated agent instructions so the workflow is self-contained and does not ask the agent to read `/app/skills/...`
- updated custom skill authoring guidance to reserve `openclaw_skill_ids` and `default_agent_skill_ids` for real bundled OpenClaw skills only

## 2026-04-21 — Fix: Skill Analytics Runtime DB Initialization

Date: 2026-04-21
Status: Implemented

### Overview

Made tenant skill analytics storage explicit and observable so analytics-enabled skills do not appear to run without a persistent runtime SQLite database.

### What Changed

- added `--init-only` and JSON status output to the Sync360 `log-skill-conversion` runtime helper
- initialized `.openclaw/data/analytics/skill-events.sqlite` during go-live and tenant customization apply for tenants with enabled analytics skills
- added `sync360:init-skill-analytics` to repair already-live tenant runtimes
- warned during `sync360:sync-skill-conversions` when an analytics-enabled tenant has no runtime DB
- tightened `conversion-test` instructions so agents only report success after the helper returns an `event_id`

## 2026-04-21 — Bugfix: Skill Assignability Requires Published Version

Date: 2026-04-21
Status: Implemented

### Overview

Fixed the admin Skill Catalog status so imported skills are not marked assignable until they have an active published catalog version.

### What Changed

- stopped repo import from setting `skill_catalog_items.is_assignable` before publish
- made publish/archive resync item assignability from active published versions
- added a migration to repair stale DB rows where unpublished skills were already flagged assignable
- kept tenant assignment guarded by the existing active-published-version requirement

## 2026-04-21 — Feature: Skill Packs Require Versioned Release Notes

Date: 2026-04-21
Status: Implemented

### Overview

Added a required pack-level `RELEASE_NOTES.md` and tightened the custom skill authoring contract so every skill info, instruction, metadata, analytics, privacy, or supporting-doc change increments the manifest version.

### What Changed

- made skill scan/import reject missing, empty, or stale root `RELEASE_NOTES.md` files that do not mention the current manifest version
- renamed the reference custom skill from `appointment-booking` to `hello-world` with label `Hello World (by Sync360)`
- bumped the hello-world skill pack to `1.0.4`
- added hello-world release notes describing the runtime agent-instruction change
- updated custom skill authoring guidance to require version bumps and release note entries for even small skill-info changes

## 2026-04-21 — Feature: Skill Agent Instructions Inject Into Tenant `AGENTS.md`

Date: 2026-04-21
Status: Implemented

### Overview

Added `agent-instructions.md` as a required root file for catalog-managed custom skills so assigned skills can steer tenant agent behavior in `AGENTS.md`, not only through their materialized `SKILL.md` files.

### What Changed

- made skill scan/import reject missing or empty root `agent-instructions.md`
- materialized `agent-instructions.md` into `.openclaw/workspace/skills/<skill-id>/`
- injected enabled skills' `agent-instructions.md` content into generated tenant `AGENTS.md`
- kept removal automatic by rebuilding `AGENTS.md` from enabled assignments only, so disabled/unassigned skills drop their injected instructions

## 2026-04-21 — Fix: Calendar Booking Uses Correct `gog` Create Shape

Date: 2026-04-21
Status: Implemented

### Overview

Fixed the generated Google Workspace guidance that let the tenant assistant invent unsupported Calendar booking/reminder flags even though the tenant `gog` runtime was healthy.

### What Changed

- added explicit `gog calendar create <calendarId> --summary ... --from ... --to ... --reminder ...` guidance for Calendar booking/reminder requests
- warned against unsupported `gog calendar event create`, `--title`, `--start`, `--end`, and `--calendar` forms that the pinned `gog` CLI rejects
- added regression coverage for the generated Calendar write command shape

## 2026-04-21 — Cleanup: Admin Overview Uses Sidebar Navigation Only

Date: 2026-04-21
Status: Implemented

### Overview

Removed duplicate primary admin destination buttons from the Admin Overview header now that those routes are available in the shared sidebar.

### What Changed

- removed `View Users`, `View Tenants`, `Skill Analytics`, and `View Jobs` header buttons from the admin overview
- kept the sidebar and mobile drawer as the primary navigation for admin destinations

## 2026-04-21 — Fix: Skill Analytics Added To Sidebar Navigation

Date: 2026-04-21
Status: Implemented

### Overview

Made the skill analytics surfaces discoverable from the shared authenticated navigation instead of only through direct URLs or dashboard/admin content.

### What Changed

- tenant users now have a `Skill Outcomes` sidebar link that jumps to the dashboard analytics panel
- admins now have a `Skill Analytics` sidebar link to `/admin/analytics/skills`
- mobile hamburger navigation includes the same tenant and admin analytics links as desktop

## 2026-04-20 — Fix: Skill Analytics Helper Scripts Moved To Templates

Date: 2026-04-20
Status: Implemented

### Overview

Moved the tenant skill analytics helper shell and Node script bodies out of `TenantRuntimeCustomizationComposer` and into resource templates so PHP tooling no longer has to parse a large embedded JavaScript nowdoc.

### What Changed

- `TenantRuntimeCustomizationComposer` now reads the deployed analytics helper scripts from `resources/runtime-helpers/sync360/`
- the generated tenant workspace files remain `.sync360/bin/log-skill-conversion` and `.sync360/bin/log-skill-conversion.mjs`
- kept the runtime helper behavior unchanged while eliminating the editor/parser syntax warnings around the embedded helper script

## 2026-04-20 — Cleanup: Custom Skill Pack Layout Uses Root Skill Files Plus Docs Folder

Date: 2026-04-20
Status: Implemented

### Overview

Simplified the repo-authored hello-world skill layout so the local source tree matches the runtime materialization model.

### What Changed

- moved supporting hello-world guidance under `resources/skill-packs/hello-world/docs/`
- removed the confusing nested `resources/skill-packs/hello-world/skills/hello-world/` scaffold
- added `resources/skill-packs/CUSTOM_SKILL_AUTHORING_PROMPT.md` as the reusable guideline prompt for creating platform-compliant custom skills with analytics and tracking
- added regression coverage that tenant runtime materialization creates `skills/hello-world/docs/...` and does not recreate nested `skills/hello-world/skills/hello-world/...`

## 2026-04-20 — Feat: Custom Skill Conversion Analytics Via Runtime SQLite Sync

Date: 2026-04-20
Status: Implemented

### Overview

Implemented v1 custom-skill conversion analytics with a Sync360-owned runtime SQLite database, a shared workspace helper for skill-emitted success events, scheduled control-plane sync, and tenant/admin reporting surfaces.

### What Changed

- analytics-enabled skill manifests now declare a required `analytics` contract and catalog import rejects incomplete analytics definitions
- tenant runtime customization now always deploys `.sync360/bin/log-skill-conversion`, its Node implementation, and `.sync360/skill-analytics-registry.json`
- skills can emit `conversion_succeeded` events into `.openclaw/data/analytics/skill-events.sqlite`; helper initialization enables SQLite WAL mode and validates required payload fields against the runtime registry
- added control-plane ingestion with per-tenant cursoring and 7-day runtime-row pruning through `sync360:sync-skill-conversions`, scheduled every 30 minutes
- added `tenant_skill_conversion_events` and `tenant_skill_analytics_sync_states` for central storage
- tenant dashboard now shows a `Skill Outcomes` panel with estimated conversions, time saved, productivity score, and ROI, while admin now has both a global `Skill Analytics` page and a tenant-detail `Analytics` tab
- updated the reference `hello-world` skill to include the analytics contract and guidance for emitting success events

## 2026-04-20 — Hardening: LiteLLM Key Generation Is Now Provisioning-Only

Date: 2026-04-20
Status: Implemented

### Overview

Continued the LiteLLM token refresh investigation and added a service-level guard so missing LiteLLM keys cannot be silently generated from onboarding-adjacent code paths. Provisioning is still the only intended credential-generation path; onboarding, Google runtime sync, initial Google sync, runtime-capability sync, and go-live/resync all continue to use the existing tenant DB key only.

### What Changed

- `LiteLlmTenantKeyService::ensureTenantKey()` now throws if asked to generate a missing key while the tenant is not in active `provisioning` status
- added focused regression coverage proving the ready Google callback sync path and the queued initial Google sync job do not call LiteLLM `/key/generate`
- kept the existing compose-regeneration rule that `OPENAI_API_KEY` must come from `tenants.litellm_virtual_key`, never from a local runtime `.env`

## 2026-04-20 — Fix: Live Onboarding Resync Button Now Shows Progress

Date: 2026-04-20
Status: Implemented

### Overview

Fixed the Step 7 `Resync Assistant` button for already-live tenants. The button looked like a primary action, but the client and controller were still using the pre-live `workspace.go_live_ready` flag, which is intentionally false once the agent is already live.

### What Changed

- live tenants can now submit Step 7 as a resync action through the existing onboarding go-live endpoint
- the button remains enabled for `agent_status=live` and shows `Resyncing Assistant...` while the request runs
- successful live resyncs now return resync-specific success copy instead of the first-time go-live message
- added onboarding regression coverage for the enabled live-resync button and endpoint behavior

## 2026-04-20 — Fix: Onboarding Preserves LiteLLM Keys And Locks Busy Wizard Steps

Date: 2026-04-20
Status: Implemented

### Overview

Fixed onboarding journey bugs where runtime sync paths could reuse stale local credentials and where customers could navigate between wizard steps while save/connect/go-live work was still running. Saved Telegram channel setup is now tracked truthfully as saved-but-not-connected until runtime config has actually been applied.

### What Changed

- runtime compose regeneration now uses `Tenant::litellm_virtual_key` as the `OPENAI_API_KEY` source of truth and fails clearly if that key is missing
- gateway token recovery falls back from local runtime `.env` to `config/openclaw.json`, while LiteLLM base URL prefers the configured service URL
- onboarding channel state now distinguishes `pending`, `saved`, and `connected`, with `telegram.runtime_configured` indicating whether runtime Telegram config exists
- provisioning completion and Go Live now replay saved Telegram config into `config/openclaw.json` when the runtime is ready
- onboarding async actions now share a visible operation-progress lock that disables wizard navigation/actions and prevents accidental step changes while a request is in flight
- added regression coverage for LiteLLM key preservation, compose credential precedence, channel replay, and the wizard operation-lock hooks

## 2026-04-20 — Fix: Hamburger Drawer Not Rendering And Button Showing Wrong Color

Date: 2026-04-20
Status: Implemented

### Overview

Two bugs in the hamburger mobile nav: (1) the `.mobile-drawer` never appeared when the hamburger was tapped because the desktop `overflow-y: auto` on `.sidebar` was clipping the absolutely-positioned drawer; (2) the hamburger button was rendering orange instead of translucent-white because the global `button { background: var(--accent) }` tag rule was overriding `.hamburger-btn` in some cascade scenarios.

### What Changed

- added `overflow: visible` to `.sidebar` inside the `@media (max-width: 980px)` block — the desktop `overflow-y: auto` would clip any `position: absolute` child that overflows the sticky bar
- added `.sidebar .hamburger-btn` and `.sidebar .hamburger-btn:hover` rules with higher specificity (descendant + class, 0,2,0) to guarantee the translucent-white background wins over the `button { background: var(--accent) }` tag rule (0,0,1)
- increased hamburger bar width from 16px to 18px for easier tap target

## 2026-04-20 — Feat: Hamburger Mobile Navigation With Full Element Preservation

Date: 2026-04-20
Status: Implemented

### Overview

Added a hamburger menu for the authenticated app layout on viewports ≤980px. The sidebar collapses to a sticky top bar (brand left, hamburger right); tapping the hamburger reveals a `.mobile-drawer` containing every navigation element — alerts bell, all nav links, user name, and logout button. Nothing is dropped or hidden.

### What Changed

- `.sidebar` becomes a sticky `flex-direction: row` top bar at ≤980px; brand left, `.hamburger-btn` right
- desktop `<nav>` and `.sidebar-footer` are hidden on mobile; replaced by `.mobile-drawer` containing all elements
- `.mobile-drawer` expands below the top bar when `.nav-open` is set on the sidebar; contains alerts bell (with dropdown), all nav section labels + links, and a footer row with user name + logout
- `.hamburger-btn` three-bar icon animates to × via CSS `nth-child` transforms when `.nav-open` is active
- `toggleMobileNav()` handles `nav-open` class + `aria-expanded` on the hamburger button
- `toggleNotifBell(dropdownId)` now accepts an explicit dropdown ID — desktop bell opens `#notif-dropdown`, mobile bell opens `#notif-dropdown-mobile`, so they are fully independent
- desktop `.sidebar-footer` now includes the alerts bell (restored), user info, and logout button — nothing was removed from desktop
- outside-click handler closes each bell dropdown independently and closes the mobile drawer when clicking outside the sidebar
- `≤480px` phone breakpoint tightens content and panel padding
- updated §10 Responsive Behavior in `DESIGN_SYSTEM.md` to document the hamburger pattern

## 2026-04-20 — Design System: Dark Mode Strategy, Motion Guards, Form Validation, And Token Migration

Date: 2026-04-20
Status: Implemented

### Overview

Completed the remaining design-system gaps: documented the dark-mode strategy, added `prefers-reduced-motion` guards to both shared layouts, defined field-level form validation CSS primitives, added three new doc sections, and migrated core component rules to `--space-*`, `--radius-*`, and `--shadow-*` tokens.

### What Changed

- added §11 Dark-Mode Strategy to `artifacts/DESIGN_SYSTEM.md` explaining that the guest layout is always dark by design (not OS-responsive), the app layout is always light, and how to add dark mode cleanly if needed later
- added §12 Icon, Illustration, And Motion documenting the inline SVG icon approach, illustration conventions, current motion (0.15s/0.18s transitions + pulse keyframe), and rules for new motion
- added `@media (prefers-reduced-motion: reduce)` guard to both shared layouts to suppress all transitions and animations for users who prefer reduced motion
- added §13 Form Validation States documenting page-level error patterns (`.note.error` on app, `.alert.alert--error` on guest) and field-level patterns (`aria-invalid`, `.field-error`)
- added `input/select/textarea[aria-invalid="true"]` CSS and `.field-error` class to both shared layouts for field-level validation state
- migrated core component rules to design tokens: `.panel`, `.stat`, `.badge`, `.button`, `.meta`, `.meta-item`, `.note`, `th/td` in app layout; `.button`, `.hero-card`, `.panel` in guest layout now reference `--space-*`, `--radius-*`, and `--shadow-*` tokens instead of raw pixel values

## 2026-04-19 — Design System: Responsive Rules Documented And Radius/Elevation Tokenized

Date: 2026-04-19
Status: Implemented

### Overview

Extended `artifacts/DESIGN_SYSTEM.md` with a new §10 Responsive Behavior section documenting the actual breakpoints used in the shared Blade layouts, and tokenized the corner-radius and shadow values that were previously described only as prose.

### What Changed

- added §10 Responsive Behavior to `artifacts/DESIGN_SYSTEM.md` documenting the authenticated app layout's single `≤980px` breakpoint and the guest layout's three-tier breakpoints (`≤1100px`, `≤900px`, `≤720px`) with per-tier collapse rules and rules for adding new responsive CSS
- added `--radius-sm/md/lg/xl/2xl/[3xl]/pill/circle` tokens to both shared Blade layouts, with values tuned per surface (app uses tighter corners; guest uses softer, larger corners)
- added semantic elevation tokens: `--shadow-panel` for default app panels, `--shadow-focus` for keyboard focus halos on both surfaces, and `--shadow-elevated` in guest aliasing the existing `--shadow` for hero/auth cards
- replaced the prose Radius And Shadow subsection in §5 Design Tokens with two tokenized subsections (Radius Scale, Elevation) documenting per-surface tables and migration guidance
- noted brand-colored button halos remain inline (not tokenized yet) because only two variants exist; will tokenize when a third lands

## 2026-04-19 — Visible Focus Ring Restored On Form Controls And Buttons

Date: 2026-04-19
Status: Implemented

### Overview

Replaced the `outline: none` rules on focused inputs, selects, textareas, and buttons in the shared Blade layouts with `:focus-visible` rings drawn using the brand accent. Keyboard users can now see which control has focus, resolving a WCAG 2.4.7 (Focus Visible) violation. Mouse clicks do not trigger the ring because `:focus-visible` scopes it to keyboard and assistive-tech focus.

### What Changed

- switched authenticated app layout input/select/textarea focus styling from `:focus` with `outline: none` to `:focus-visible` with a 2px accent outline while preserving the existing border and box-shadow glow
- switched guest layout input/select focus styling to the same `:focus-visible` accent outline approach
- added `:focus-visible` outline rings for `.button`, bare `button`, and `.nav-link` in the authenticated layout and for `.button`/bare `button` in the guest layout so keyboard navigation across primary actions is also visible

## 2026-04-19 — DM Sans Typography System Applied Across Blade UI

Date: 2026-04-19
Status: Implemented

### Overview

Applied the repo-wide typography polish so shared Blade UI surfaces use `DM Sans` as the product voice and `JetBrains Mono` only as a technical accent. This turns the design-system document into implemented UI behavior across app, guest, onboarding, dashboard, conversation, auth, workspace-ready, and admin surfaces.

### What Changed

- changed Tailwind font tokens and shared app/guest layout font imports from the old mono pairing to `DM Sans` plus `JetBrains Mono`
- added reusable typography classes and spacing tokens to the shared layouts so page titles, section headings, body copy, labels, values, technical text, badges, and long strings follow one contract
- reduced decorative mono usage across admin and customer-facing views, keeping mono for IDs, URLs, timestamps, ports, job names, runtime values, logs, and compact technical status chips
- improved admin tenant-detail readability by using calmer labels, clearer value hierarchy, and better wrapping/leading for long operational strings
- added typography rendering coverage for guest and authenticated layouts, and refreshed affected view assertions
- ignored local `.claude/` and `.superpowers/` workflow directories so agent scratch files do not enter project commits

## 2026-04-19 — Canonical Design System Document Added

Date: 2026-04-19
Status: Implemented

### Overview

Added `artifacts/DESIGN_SYSTEM.md` as the canonical UI/design-system reference for current Sync360 Blade surfaces. Future UI work should align to this code-backed design-system truth instead of relying on historical redesign artifacts or implicit layout knowledge alone.

### What Changed

- documented the current design principles, brand voice, surface split, color/typography/spacing/radius/shadow tokens, component patterns, and usage examples in `artifacts/DESIGN_SYSTEM.md`
- made the DM Sans + JetBrains Mono typography contract explicit, including when mono is appropriate for IDs, timestamps, ports, runtime strings, log output, and technical status tokens
- promoted the design-system document into the canonical docs map, durable memory, and architecture references so UI/design truth has a stable home
- updated the canonical-doc guardrail so frontend presentation changes under `resources/views/` or `resources/css/` require `artifacts/DESIGN_SYSTEM.md`, while unrelated backend-only changes do not
- taught the worktree docs check to include untracked files, so newly added canonical docs are visible before staging
- clarified that historical redesign artifacts are reference-only and that current code plus the canonical docs define the active design contract
- added a 4px-base spacing token scale (`--space-0-5` through `--space-12`, plus `--space-16`/`--space-18` on guest) to both shared Blade layouts so new CSS can reference tokens instead of raw pixel values; migration of existing rules is gradual
- added an Accessibility section (WCAG 2.1 AA targets) and a Badge State Map to `artifacts/DESIGN_SYSTEM.md`, and flagged the existing missing-focus-ring bug on form inputs as a known gap to fix in code

## 2026-04-19 — Google Verification Now Gates Customer-Ready Workspace Success And Go Live

Date: 2026-04-19
Status: Implemented

### Overview

Separated private runtime readiness from customer-facing readiness. Sync360 still marks a tenant runtime `ready` after private/public provisioning checks, but not-yet-live tenants now need a connected and `verified` Google Workspace before they can reach the workspace-ready success state or execute Go Live.

### What Changed

- added a shared tenant workspace readiness calculator that derives runtime-ready, customer-ready, go-live-ready, blocking reason, next action, and Google live-access labels from preloaded tenant + initial Google sync state
- preserved the existing `workspace.ready` payload meaning for backward safety, while adding explicit `workspace.runtime_ready`, `workspace.customer_ready`, `workspace.go_live_ready`, `workspace.blocking_code`, `workspace.blocking_message`, and `workspace.next_action`
- updated onboarding Step 6/Step 7 so Google Workspace is no longer treated as optional for not-yet-live tenants when the feature is available
- removed the onboarding controller's old best-effort Google sync call from `POST /onboarding/go-live`, keeping the existing invariant that Go Live syncs workspace markdown files only
- added an explicit server-side go-live guard so direct API calls are blocked until the readiness calculator reports `go_live_ready`
- changed `TenantSetupController::show()`, `status()`, `ready()`, and the workspace-ready Blade to use customer-ready gating plus clearer Google-specific next steps
- grandfathered already-live / already-complete skipped tenants so this deploy does not strand existing tenants behind the new Google gate
- changed the post-provisioning Brevo email copy to a neutral "workspace created / continue setup" message instead of implying the customer workspace is fully ready immediately after provisioning
- added feature and unit coverage for the new readiness gating, blocked workspace-ready route, grandfathered skipped tenants, go-live guard, and calculator purity contract

## 2026-04-19 — Tenant Assigned Skill Guidance Now Lives In Generated AGENTS.md

Date: 2026-04-19
Status: Implemented

### Overview

Added a generated tenant-runtime `AGENTS.md` file that reflects the current enabled tenant skill assignments. This gives the assistant an assignment-scoped guidance surface without mixing skill routing hints into `BOOTSTRAP.md`, and it lets admins inspect the effective file directly from the `Agent Runtime` tab.

### What Changed

- added generated `.openclaw/workspace/AGENTS.md` output to the tenant runtime composer
- rendered an `Assigned Skill Guidance` section in `AGENTS.md` only when one or more tenant skills are currently enabled
- removed the assigned-skill guidance automatically when a tenant later unassigns those skills, so the generated `AGENTS.md` always follows current assignment state
- updated the admin `Agent Runtime` preview to show `Current AGENTS.md` as a read-only generated file alongside the other current markdown/runtime files
- expanded unit and feature coverage to prove the `AGENTS.md` guidance appears for enabled assignments, disappears when no assignments are enabled, and is visible in the admin preview

## 2026-04-18 — Tenant Skill Unassign Now Disables Runtime Eligibility Without Removing Installed Skill Files

Date: 2026-04-18
Status: Implemented

### Overview

Fixed a live tenant-runtime bug where unassigning a published catalog skill and applying the deployment did not explicitly disable the previously installed skill in `openclaw.json`. Because the skill files stayed installed on the tenant runtime, `openclaw skills list --eligible` could still show the skill even though the tenant had unassigned it.

### What Changed

- preserved installed tenant skill files on the runtime so temporarily unassigned published skills can be re-assigned later without treating unassignment as an uninstall operation
- updated the apply/config regression coverage to prove remote applies write previously assigned skills back into `openclaw.json` as `enabled: false` when they are later unassigned, which matches OpenClaw's runtime eligibility contract
- kept the existing single apply pipeline and workspace-only sync contract intact while separating "installed on runtime" from "eligible in config"
- documented the skill installation decision rule in the canonical architecture and memory docs so plain repo-authored skills and host-managed runtime-capability skills stay clearly separated for future rollout work

## 2026-04-18 — Tenant Admin Skills Moved Out Of Agent Runtime

Date: 2026-04-18
Status: Implemented

### Overview

Refined the local-only super-admin tenant detail page so tenant skill management is no longer mixed into prompt/runtime behavior editing. The page now has a dedicated `Skills` tab for pack assignment and default skill IDs, while `Agent Runtime` focuses on model and markdown behavior.

### What Changed

- added a dedicated `Skills` tab to `admin/tenants/{tenant}` between `Google` and `Agent Runtime`
- moved `Skill Packs`, `Default Skill IDs`, skill apply/revert actions, and apply history out of `Agent Runtime` and into the new `Skills` screen
- narrowed the `Agent Runtime` screen to model defaults, prompt overrides, current markdown previews, preview output, and runtime-scoped apply/revert controls
- updated the admin controller so customization saves are scoped by tab, preventing a `Skills` save from wiping prompt/model data and preventing an `Agent Runtime` save from wiping assigned skill packs or default skill IDs
- expanded admin feature coverage to prove the new tab layout, tab-aware redirects, and cross-tab preservation behavior
- changed the `Skills` history area to a human-readable skill change log showing pack enable/disable events and default-skill additions/removals, while moving the raw apply audit back under `Agent Runtime`

## 2026-04-18 — Tenant Admin Detail Became Tabbed + Canonical Docs Hook Was Hardened

Date: 2026-04-18
Status: Implemented

### Overview

Refined the local-only super-admin tenant detail workflow so it scales better as the operator surface grows, fixed the `Default Skill IDs` draft-save contract in the new agent-runtime area, and removed the last reason to bypass the canonical-docs hooks. The tenant page now behaves like a set of focused subscreens on one route, the runtime customization panel accepts the comma-separated skill-id input it asks admins to type, and the docs check now fails cleanly on macOS Bash instead of crashing when required docs are missing.

### What Changed

- reorganized `GET /admin/tenants/{tenant}` into same-route tabbed subscreens selected by `?tab=overview|workspace|google|agent-runtime|support`
- preserved the active tenant tab across admin actions so Google operations return to `google`, agent-runtime actions return to `agent-runtime`, and support actions return to `support`
- compacted the tenant-detail sidebar navigation, added clearer active-tab treatment, and labeled the top tenant-status chips so operators can tell which status belongs to provisioning, agent health, workspace state, and Google state
- added current effective prompt-file previews to the `Agent Runtime` tab so admins can see the tenant's existing `IDENTITY.md`, `SOUL.md`, `USER.md`, and `BOOTSTRAP.md` content before appending or replacing overrides
- fixed the `Default Skill IDs` draft form to submit the comma-separated text value the UI presents and normalize it into the canonical saved `default_skill_ids` array before validation and persistence
- made the admin tenant detail page degrade gracefully when `tenant_agent_customizations` tables are not present locally, showing a setup-needed message instead of throwing a database exception
- hardened `scripts/check-canonical-docs.sh` so its missing-doc failure path stays compatible with Bash 3 `set -u` behavior and reports the actual canonical-doc requirement instead of crashing on an empty-array expansion
- refreshed the canonical docs to capture the tabbed tenant-detail IA, the admin runtime-customization surface, and the expectation that normal commits should pass the docs hook without `--no-verify`

## 2026-04-18 — Admin Tenant Pages Now Show Google Sync Timeline + Requeue Action

Date: 2026-04-18
Status: Implemented

### Overview

Extended the local-only super-admin tenant views so operators can see the real Google Workspace state for every tenant without cross-referencing onboarding or the database manually. The admin UI now surfaces both the credential state and the initial Google sync lifecycle, and it exposes a first-class way to re-queue that durable sync job when a tenant needs another pass.

### What Changed

- added a Google column to the admin tenants list that shows connection state, live-access label, connected email, latest relevant timestamp, and the latest recorded runtime error for each tenant
- expanded the admin tenant detail page with a dedicated Google Workspace section covering connection state, live-access state, connected/disconnected/synced timestamps, latest initial sync job status, and latest runtime/job errors
- added a `POST /admin/tenants/{tenant}/google/sync` operator action that reuses `TenantAgentSyncService::dispatchInitialGoogleWorkspaceSync()` to queue the existing durable Google sync job for a connected ready tenant
- kept Google repair/testing aligned with existing architecture by leaving runtime-capability sync and smoke testing as the same command-backed actions rather than introducing a second repair path in the controller
- added admin feature coverage proving the richer Google state is visible and that a failed tenant can re-queue the initial Google sync job from the admin panel

## 2026-04-18 — Google Workspace Connect Now Queues An Initial Tenant Sync

Date: 2026-04-18
Status: Implemented

### Overview

Closed the last onboarding gap between a successful Google OAuth callback and a workspace that is actually ready to use Google tools. Instead of relying on an inline best-effort sync during connect or later manual intervention, Sync360 now persists and dispatches a dedicated initial Google Workspace sync job and surfaces that progress back to the customer.

### What Changed

- changed the Google callback and tenant provisioning completion path to create a durable `initial_google_workspace_sync` job in `provisioning_jobs` whenever a connected tenant runtime is ready for its first Google reseed and verification pass
- added a dedicated queue job that runs the existing Google auth reseed plus tenant-side smoke verification flow, marks the job `queued` / `running` / `completed` / `failed`, and keeps `runtime_sync_status` / `last_error` aligned with the real outcome
- updated onboarding state payloads and Step 6 UI copy so newly connected tenants now move through `waiting for workspace`, `queued`, `syncing`, `checking`, `ready`, or `needs attention` instead of showing only the coarse runtime status
- added focused coverage proving both the OAuth callback and the provisioning-ready path dispatch the initial Google sync automatically and expose the queued progress state to the customer

## 2026-04-18 — Channel Step Always Shows A Manual Path To Google Workspace

Date: 2026-04-18
Status: Implemented

### Overview

Removed another onboarding friction point in the wizard. Step 5 relied on save-time auto-advance and, once a channel was connected, could leave customers without an obvious way to move on to Google Workspace. The Channel step now always keeps a visible manual forward action.

### What Changed

- added an explicit `Continue To Google Workspace` button to the Step 5 wizard navigation
- kept the existing auto-advance behavior after successful channel saves, but no longer depends on it as the only way to reach Step 6
- added onboarding coverage proving the manual forward action is visible from the channel step, including when Google Workspace is already connected later in the flow
- corrected the initial rollout so the forward button is rendered on the actual Step 5 channel-panel navigation rather than an earlier wizard section

## 2026-04-18 — Google Workspace Step 6 Copy Softened For Customers

Date: 2026-04-18
Status: Implemented

### Overview

Smoothed the Google Workspace onboarding language after the runtime path stabilized. Step 6 already had the correct connected/synced/verified/failed state model, but some of the customer-facing copy sounded too operational and nudged people toward reconnecting before the runtime error actually called for that.

### What Changed

- changed the Step 6 status label from `Runtime Sync` to `Live Access`
- rewrote the connected, pending-sync, pending-verification, verified, and needs-attention note copy to sound more like progress through live access rather than backend troubleshooting
- kept the raw runtime error visible below the calmer summary note so operators and customers can still see the precise failure when something genuinely needs attention
- updated onboarding feature coverage for the new verified-state wording

## 2026-04-18 — RFC3394 AES Key-Wrap Compatibility For `gog` Keyring Tokens

Date: 2026-04-18
Status: Implemented

### Overview

Closed the next live Google Workspace runtime gap after the encrypted file-keyring rollout. Sync360 was already generating a JWE-like encrypted keyring token, but the wrapped content-encryption key did not explicitly use the RFC3394 default wrap IV that the live `gog` / `99designs/keyring` path expects. That let local round-trip tests pass while the live Gmail CLI still failed with `aes.KeyUnwrap(): integrity check failed`.

### What Changed

- changed `GogAuthStorageService` so AES-128 key wrapping now explicitly uses the RFC3394 default wrap IV when generating tenant `keyring/token:default:<email>` artifacts
- aligned the `GogAuthStorageService` unit test to unwrap the generated CEK with the same RFC3394-compatible IV so the fixture validates the real live contract instead of PHP-only local behavior
- kept the existing encrypted file-keyring/token-cache split intact; this change fixes key-wrap compatibility rather than changing the broader runtime auth model

## 2026-04-18 — Runtime Capability Repair Now Re-Seeds `gog` Auth Artifacts

Date: 2026-04-18
Status: Implemented

### Overview

Closed the next repair-path gap for live Google Workspace tenants. `sync360:sync-runtime-capabilities ... gog` previously repaired the host binary, compose, and OpenClaw config, then immediately verified Gmail/Calendar access without first rewriting the tenant `.openclaw/gogcli/` auth files. That left older bad keyring/token artifacts in place even after deploying the file-keyring compatibility fix.

### What Changed

- changed `sync360:sync-runtime-capabilities` so `gog` repairs now call the same Google runtime auth reseed path used by onboarding/profile sync before running CLI verification
- this means connected tenants now get fresh `.openclaw/gogcli/credentials.json`, `config.json`, keyring token, and token-cache artifacts during the runtime repair flow
- kept the existing compose/config regeneration, workspace-guidance refresh, and smoke-verification behavior intact
- added coverage proving the runtime-capability repair command now uploads Google auth artifacts as part of the `gog` repair path

## 2026-04-18 — `gog` Token Cache / File-Keyring Compatibility Fix

Date: 2026-04-18
Status: Implemented

### Overview

Closed the next live Google Workspace runtime gap by fixing the remaining mismatch between Sync360's re-seeded tenant auth artifacts and the upstream `gog` file-keyring contract. Before this change, Sync360 wrote a plaintext JSON token directly into the keyring path, which caused the live Gmail CLI to fail with base64/keyring decode errors even after reconnecting Google successfully.

### What Changed

- changed `GogAuthStorageService` so tenant `credentials.json` now includes the top-level `client_id` / `client_secret` fields expected by the live `gog` CLI in addition to the nested `installed` payload
- changed tenant keyring token generation so `.openclaw/gogcli/keyring/token:default:<email>` is now written as an encrypted file-keyring payload compatible with the upstream `gog` / `99designs/keyring` file backend instead of plaintext JSON
- kept `token_<safe-email>.json` as the plain authorized-user cache used for refresh-token/API smoke preflight
- updated the Google smoke script so it no longer tries to JSON-parse the encrypted keyring file; it now validates the token cache and client credentials before running the real native `gog` CLI probes
- added focused regression coverage proving the generated keyring artifact is encrypted/JWE-like and that the smoke script no longer treats that keyring file as plain JSON

## 2026-04-18 — Google Smoke Failures Now Ignore SSH Host-Key Noise

Date: 2026-04-18
Status: Implemented

### Overview

Fixed a misleading Google Workspace failure path on SSH-managed tenants where the onboarding/admin UI could show the benign SSH known-host warning instead of the real runtime verification error.

### What Changed

- updated Google Workspace smoke-failure translation to strip the harmless `Warning: Permanently added ... to the list of known hosts.` SSH transport line before surfacing a failure
- preserved the real remote runtime or CLI stderr line when one exists, so operators see the actual `gog` / Google verification error
- added regression coverage proving known-host warning noise is ignored and a neutral remote-execution message is used when SSH emitted no meaningful failure detail beyond the warning

## 2026-04-18 — Native Direct `gog` Runtime Contract + CLI Smoke Verification

Date: 2026-04-18
Status: Implemented

### Overview

Closed the next Google Workspace runtime gap by moving from prompt-only `gog` assumptions to a thin shared runtime contract: tenant runtimes still use raw direct `gog` CLI commands, but Sync360 now keeps the allowlist env, default-account env, smoke probes, and generated guidance aligned so runtime health and assistant behavior validate the same native command surface.

### What Changed

- added a thin shared `GogCommandCatalogService` that centralizes only the `gog` allowlist, tenant runtime env, non-mutating CLI probes, and generated guidance strings
- extended the `gog` capability metadata so tenant compose generation now includes `GOG_ENABLE_COMMANDS` for the allowlisted direct service surface and `GOG_ACCOUNT` for connected tenants
- kept tenant usage native and direct: Sync360 does not add wrapper commands around normal `gog` usage and still forbids tenant-side `gog auth ...` mutation during normal owner requests
- expanded `sync360:test-google-workspace` so it now verifies the real native direct `gog` CLI surface inside the tenant container:
  - `GOG_ENABLE_COMMANDS` / `GOG_ACCOUNT`
  - Gmail CLI
  - Calendar CLI
  - Drive CLI
  - Contacts CLI
  - broader allowlisted help probes
  - existing refresh-token, Gmail API, and Calendar API smoke checks
- changed smoke failures to surface stage-specific errors so invalid native `gog` command usage no longer collapses into a generic `credentials.json` diagnosis
- updated generated `TOOLS.md`, `PROFILE.md`, and `HEARTBEAT.md` to bias the tenant assistant toward raw direct `gog` CLI usage, default-account behavior, and scope-aware error explanations
- updated Google disconnect so compose is regenerated when needed and `GOG_ACCOUNT` is removed from the tenant runtime env
- changed `sync360:sync-runtime-capabilities` so live-tenant `gog` repairs also refresh workspace guidance, keeping runtime state and prompt guidance aligned

## 2026-04-17 — Google Verification Now Clears Stale Failure Memory

Date: 2026-04-17
Status: Implemented

### Overview

Closed the next Google Workspace runtime gap by teaching tenant workspace guidance a stricter read-only Gmail workflow and clearing the known stale Gmail/account failure memory files after a real successful verification. This prevents older reconnect/account-selection issue summaries from continuing to steer the assistant after `gog` and runtime auth are healthy again.

### What Changed

- updated generated `TOOLS.md` guidance to push a help-first, read-only Gmail workflow for owner inbox requests
- explicitly told the assistant not to rewrite `gog` account configuration during a normal email request and to use a safe query such as `in:inbox newer_than:30d` when the chosen Gmail command requires one
- changed successful Google verification to remove the known stale Gmail/account failure memory files from tenant `.openclaw/workspace/memory/`
- applied that cleanup both to the Google verification path used after OAuth/runtime sync and to the explicit `sync360:test-google-workspace` operator command
- added focused coverage proving successful Google verification removes the known stale failure-memory files while leaving unrelated memory/session files intact

## 2026-04-17 — Tenant Google Workspace Guidance Hardened For Default Account Behavior

Date: 2026-04-17
Status: Implemented

### Overview

Tightened the generated tenant workspace guidance so assistants stop asking the owner to pick a Gmail account or blindly suggesting reconnect steps when Sync360 already knows the connected Google account and the runtime smoke test is healthy.

### What Changed

- updated generated `TOOLS.md`, `PROFILE.md`, and `HEARTBEAT.md` guidance to treat the connected Google email as the default account
- instructed the tenant assistant not to ask the owner which account to use unless tooling explicitly reports multiple configured accounts or a missing default account
- instructed the tenant assistant to suggest reconnecting only when a real `gog` error explicitly indicates invalid, expired, or unauthorized credentials
- explicitly discouraged hallucinated setup advice such as telling the owner to replace `credentials.json` or redo Google API Console setup without a real auth error
- added focused assertions covering the new default-account and reconnect-guardrail language in generated workspace artifacts

## 2026-04-17 — Admin Tenant Panel Runtime Actions

Date: 2026-04-17
Status: Implemented

### Overview

Extended the local-only super-admin tenant detail page so operators can run the key `gog` repair and verification flows for an individual tenant directly from the control panel instead of dropping to the CLI for every step.

### What Changed

- added tenant-scoped admin actions for client-VPS bootstrap, runtime-capability sync, and Google Workspace smoke testing
- wired those admin actions to the existing artisan command paths instead of duplicating runtime-capability logic in the controller
- updated the tenant detail page to show the new buttons alongside the existing health, resync, and workspace controls
- surfaced Google Workspace runtime status and the latest recorded runtime error on the tenant detail page so operators can see whether the tenant is connected, verified, or needs attention before running repairs
- preserved the existing behavior boundary: these panel actions are wrappers around the same operator commands and do not change the `goLive()` workspace-files-only invariant

## 2026-04-17 — Host-Managed Runtime Capabilities For Tenant Skills

Date: 2026-04-17
Status: Implemented

### Overview

Added a host-managed runtime capability system so Sync360 can deliver external tenant runtime dependencies without changing the base OpenClaw image or breaking the existing `goLive()` workspace-sync invariant. The first shipped capability is `gog`, now installed as a pinned Linux release binary on each SSH-managed client VPS and mounted read-only into every tenant container.

### What Changed

- added a runtime capability catalog in `config/sync360.php` with pinned `gog` metadata, including version, GitHub release URL, checksum, install path, container mount, and verification commands
- introduced `TenantRuntimeCapabilityService` to centralize capability catalog lookup, OpenClaw skill wiring, compose/config mutation, host installation, and host/container verification
- extended `sync360:bootstrap-client-vps` so client VPS preparation now also installs pinned host-managed runtime capabilities with version-aware idempotency
- added `sync360:sync-runtime-capabilities {tenantSelector?} {capability?}` as the SSH-only repair path for existing ready tenants
- changed compose generation so host-managed capability mounts are generated deterministically and included unconditionally; `gog` now mounts from `/usr/local/bin/gog` on the host into `/usr/local/bin/gog` in every tenant container
- changed Google runtime verification to preflight both host capability checks and host-side `docker exec` container-binary checks before auth artifact and Gmail/Calendar smoke validation
- corrected failure handling so Google runtime status is explicitly marked `failed` with a precise `last_error` when capability verification breaks, even if the tenant was previously marked `verified`
- kept `goLive()` workspace-files-only; host binaries and runtime config repair remain separate operator flows

### Operator Workflow

For the current `gog` capability:

1. run `php artisan sync360:bootstrap-client-vps <server>` to install or update the pinned host binary on each SSH-managed client VPS
2. run `php artisan sync360:sync-runtime-capabilities <tenant-or-scope> gog` to regenerate tenant compose/config, push changes, and recreate runtimes where needed
3. run `php artisan sync360:test-google-workspace <tenant>` to confirm host capability, container binary, runtime artifacts, and live Google smoke checks all pass

## 2026-04-17 — `gog` Skill Enabled In Tenant OpenClaw Config

Date: 2026-04-17
Status: Implemented

### Overview

Fixed the remaining Google Workspace runtime gap by enabling the bundled `gog` skill in tenant `openclaw.json` and appending `gog` to agent skill allowlists. Before this change, tenants could have verified Google auth and prompt guidance but still fail owner email requests because the runtime had not exposed the actual `gog` skill to the agent.

### What Changed

- updated tenant OpenClaw provisioning to write `skills.entries.gog.enabled = true` into generated `config/openclaw.json`
- normalized tenant agent skill allowlists so `gog` is appended to `agents.defaults.skills` and any existing `agents.list[].skills` entries without clobbering other configured skills
- updated later Google Workspace runtime syncs to re-apply that `openclaw.json` skill wiring so existing live tenants can be repaired during resync
- added focused assertions proving both initial provisioning and Google runtime sync write the expected `gog` skill config
- preserved the existing workspace-file-only `goLive()` invariant; this fix updates runtime config only in the targeted provisioning/Google sync paths that already own `openclaw.json`

## 2026-04-17 — Tenant `TOOLS.md` Google Workspace Guidance

Date: 2026-04-17
Status: Implemented

### Overview

Added a generated tenant `TOOLS.md` file so live assistants receive environment-specific operational guidance for Google Workspace access instead of relying only on profile/heartbeat prompt language plus synced auth files.

### What Changed

- extended the workspace artifact set to include `TOOLS.md` alongside `IDENTITY.md`, `SOUL.md`, `USER.md`, `BOOTSTRAP.md`, `PROFILE.md`, and `HEARTBEAT.md`
- added explicit guidance telling the assistant to use exec plus the preconfigured `gog` CLI for owner Gmail, Calendar, Drive, Contacts, Sheets, and Docs requests
- instructed the assistant to inspect `gog --help` and product-specific help such as `gog gmail --help` when it needs to discover the correct read/list command instead of defaulting to a conversational refusal
- kept the disconnected-tenant path explicit so the assistant still explains that Google Workspace setup must be completed in Sync360 when no connection exists
- added focused test coverage proving `TOOLS.md` is generated during go-live and later live profile syncs
- preserved the existing invariant that these changes are delivered through workspace-file-only syncs rather than a full runtime sync

## 2026-04-17 — Live Tenant Workspace Resync Command + Profile Sync Progress Feedback

Date: 2026-04-17
Status: Implemented

### Overview

Added an operator-safe way to push regenerated workspace instructions to already-live tenants after control-plane prompt changes, and improved the Business Profile sync experience so customers can see live sync progress and completion instead of guessing whether anything is happening.

### What Changed

- added `sync360:resync-live-tenants` to regenerate and resync workspace instructions for eligible live tenants without reprovisioning the tenant runtime
- allowed the command to target all eligible live tenants or a single tenant by numeric id, `tenant_id`, or slug
- kept the resync path grounded in `TenantProfileSyncService::regenerateAndSync()` so it preserves the existing invariant that `goLive()` syncs workspace files only and never does a full runtime sync
- updated the Business Profile page so `Sync Assistant Now` and live profile saves disable repeat clicks, show staged progress labels while sync is running, and surface an in-page completion note after redirect
- added focused coverage for the new console command and the live-tenant profile sync feedback on `/profile`

## 2026-04-17 — Google Workspace Verification Status + Owner Tool Guidance

Date: 2026-04-17
Status: Implemented

### Overview

Adjusted Google Workspace onboarding so Sync360 no longer treats a credential sync as proof that the live tenant assistant can actually use Gmail and Calendar. The onboarding UI now surfaces real runtime verification state, and the generated tenant workspace instructions explicitly tell the assistant to use connected Google Workspace tools for owner requests instead of falling back to a generic refusal.

### What Changed

- extended Google runtime status from a simple `pending/synced/failed` flow to `pending/synced/verified/failed`
- updated Step 6 onboarding copy so customers can see whether Google is merely connected, synced into the runtime, verified inside the live tenant container, or needs attention
- surfaced `last_error` in the onboarding state/UI so failed runtime sync or verification issues are visible instead of hidden behind a success-looking connect state
- changed the Google callback and later resync paths to run the existing tenant-side Google smoke test after a successful runtime sync when the workspace is ready
- kept the tenant-side smoke test as the code-backed definition of "verified" by checking refresh-token exchange plus live Gmail and Calendar API access from inside the tenant runtime
- updated generated `PROFILE.md` and `HEARTBEAT.md` so owner requests about inboxes, calendars, files, contacts, sheets, and docs are treated as internal operating tasks that should use connected Google Workspace tools when available
- added focused onboarding coverage for verified state, visible attention/error state, and the new owner-facing workspace guidance in live sync artifacts

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

- added an explicit wizard progress card and a background-setup status card so customers can see where they are in the flow and what is still happening behind the scenes
- preserved in-progress website, business-details, communication-style, capabilities, and channel selections while the onboarding state poll refreshes the page state
- stopped the client-side refresh from clearing unsaved tone and capability choices before they are submitted
- advanced the wizard automatically after successful website read, business save, communication-style save, capability save, and channel connect actions
- removed the extra save-then-next friction on the main setup steps by auto-advancing after save and dropping the redundant Next actions there
- preserved unsaved Telegram selection and bot-token entry UI while the onboarding state poll refreshes the page state
- stopped the client-side refresh from clearing the local channel radio selection when the server has not yet saved a channel
- reset the local channel draft only after an explicit disconnect action
- replaced the repeated `Telegram is available now...` note with a contextual helper message shown only when Telegram is selected and still needs a bot token
- added onboarding coverage confirming that viewing and polling the wizard does not regenerate or mutate an existing tenant LiteLLM key

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
## 2026-04-18 - Skill Catalog, Tenant Skill Assignment, and Runtime Skill Diagnostics

Date: 2026-04-18
Branch: `cdx-feature/V1SkillCatalogTenantAssignment`
Commit: `uncommitted`

Summary:
- Added a scan-first Sync360 skill catalog, first-class tenant skill assignments, and tenant runtime skill diagnostics.

Notable changes:
- Added DB-backed skill catalog and tenant assignment models, migrations, and admin catalog screens.
- Split tenant `Skills` management out from prompt/runtime customization while keeping one tenant apply pipeline.
- Changed live custom skill materialization toward OpenClaw-native workspace `skills/` layout and removed legacy skill-pack delivery from the authoritative path.
- Added scan/import commands and admin UI for repo-authored skills with `production_ready` manifest gating.
- Added tenant `Runtime Available Skills` diagnostics using `openclaw skills list --eligible` with a refresh action in the tenant `Skills` tab.
- Added the analytics discovery command and written finding gate instead of assuming runtime skill-usage logs already exist.

Verification:
- `php artisan test` passed with `129 passed (1039 assertions)`
- `bash scripts/check-canonical-docs.sh --worktree` passes after the canonical doc updates in this release note set

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
## 2026-04-21 - Faster Skill Conversion Sync Cadence

Date: 2026-04-21
Branch: `codex/control-app-prod-deploy`

Summary:
- Reduced the automatic skill conversion analytics sync cadence from every 30 minutes to every 5 minutes.

Notable changes:
- Updated [routes/console.php](/Users/gayanhewage/Projects/openclaw-saas/routes/console.php) so `sync360:sync-skill-conversions` now runs every five minutes.
- This shortens the delay between tenant runtime SQLite writes and control-plane dashboard visibility.

Verification:
- `php -l routes/console.php` passed

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
