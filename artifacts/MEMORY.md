# Sync360 Control App Memory

Last verified: `2026-04-20`

This memory is based on the current repo code and current canonical docs. It is not a guarantee about live production state.

Shared Blade layouts (`resources/views/components/layouts/app.blade.php` and `guest.blade.php`) use `:focus-visible` rings on inputs, selects, textareas, buttons, and nav links so keyboard focus is visible (WCAG 2.4.7).

## 1. What this project is

Sync360 Control App is a Laravel control plane for provisioning and managing tenant-specific OpenClaw workspaces.

The control plane owns:

- signup, login, password reset, dashboard, onboarding, and profile flows
- tenant creation and server placement
- async tenant provisioning through queues
- private runtime management for each tenant workspace
- conversation-log sync and summaries
- trial lifecycle tracking and notification emails
- local-only super-admin monitoring and operational controls

It is not just a landing page plus provisioner anymore; onboarding, runtime sync, conversation visibility, health checks, and trial enforcement are all part of the implemented product.

## 2. Read this first

Use the docs in this order:

1. this file
2. [`ARCHITECTURE.md`](ARCHITECTURE.md)
3. [`DESIGN_SYSTEM.md`](DESIGN_SYSTEM.md) for UI/design work
4. [`RELEASE_NOTES.md`](RELEASE_NOTES.md)

Canonical docs:

- `artifacts/MEMORY.md` is the durable new-chat starter
- `artifacts/DESIGN_SYSTEM.md` is the UI/design-system source of truth for Blade surfaces
- `artifacts/ARCHITECTURE.md` is the technical deep dive
- `artifacts/RELEASE_NOTES.md` is the historical changelog

Historical/reference material only:

- `artifacts/archive/`
- `artifacts/walkthrough/`
- `artifacts/founder_roadmap.md`

If those files conflict with the codebase, trust:

1. code
2. canonical docs
3. release notes

## 3. Current system snapshot

- App shape: Laravel monolith with Blade, PostgreSQL, Redis, queues, scheduler-backed commands, and Laravel password-broker auth recovery
- Design system shape: `artifacts/DESIGN_SYSTEM.md` is the canonical design-system reference for current Blade UI truth; shared app/guest layouts own the active tokens (color, typography, spacing `--space-*`, radius `--radius-*`, elevation `--shadow-*`), typography classes, component patterns, field-level validation CSS (`aria-invalid`, `.field-error`), and `prefers-reduced-motion` guards. App surface is always light; guest surface is always dark atmospheric (not OS-responsive). §10 Responsive, §11 Dark-Mode, §12 Icon/Motion, §13 Form Validation are documented. Mobile nav (≤980px): sidebar collapses to a sticky top bar (brand left, hamburger right); tapping the hamburger reveals a `.mobile-drawer` containing alerts bell, all nav links, user name, and logout — nothing is dropped. Desktop sidebar-footer retains the bell, user info, and logout unchanged. The `.sidebar` must have `overflow: visible` in the mobile query or the absolute-positioned drawer gets clipped.
- Typography shape: shared app/guest layouts load `DM Sans` as the product voice and `JetBrains Mono` as the technical accent; Blade views should use shared `type-*` classes and reserve mono for IDs, timestamps, ports, runtime strings, logs, and compact technical tokens
- Authenticated dashboard responses are now sent with no-cache headers so workspace status cards do not get stuck on stale browser snapshots
- Password reset delivery: uses Brevo's HTTP email API when Brevo is enabled; falls back to Laravel's default notification pipeline otherwise
- Infrastructure modes: `local` and `ssh`
- Tenant runtime staging: `runtime/tenants/<slug>/`
- Tenant runtime deployment: one OpenClaw runtime per tenant
- Workspace URL model: customer-facing Sync360 URL on the tenant hostname; private gateway stays behind the control plane
- Local dev runtime model: the Docker Compose dev stack forces `SYNC360_INFRASTRUCTURE_DRIVER=local`, uses `docker-compose` inside the app/worker containers, and reaches tenant host ports through `host.docker.internal`
- Host-managed runtime capability model: external tenant runtime dependencies are declared in `config/sync360.php` under `runtime_capabilities`; Sync360 installs pinned host binaries on SSH-managed client VPS hosts, bind-mounts them read-only into every tenant container, and verifies both the host binary and in-container visibility before treating the runtime as healthy
- Onboarding model: signup provisions the runtime in the background, while the customer completes a seven-step setup flow ending in required Google Workspace verification and Go Live for not-yet-live tenants when the Google feature is available; the onboarding UI now separates runtime-ready from customer-ready/go-live-ready workspace state, shows explicit blocking reasons/next actions, polls server state without wiping in-progress drafts, advances automatically after successful saves on the main setup steps, locks wizard navigation while async operations are running, lets already-live tenants use Step 7's `Resync Assistant` action, and distinguishes Google Workspace `waiting for workspace`, `queued`, `syncing`, `checking`, `ready`, and `needs attention` live-access states on top of the persisted runtime sync result
- Onboarding credential model: wizard actions must never generate or overwrite a tenant LiteLLM key. Runtime compose regeneration uses `tenants.litellm_virtual_key` as the `OPENAI_API_KEY` source of truth, recovers the gateway token from local `.env` or `config/openclaw.json`, and fails clearly if the tenant DB key is missing. `LiteLlmTenantKeyService::ensureTenantKey()` now rejects generation unless the tenant is in active `provisioning` status, so future onboarding, Google sync, initial sync, go-live/resync, or runtime-capability callers cannot silently create a missing key.
- Onboarding channel replay model: saved Telegram configuration is valid progress before runtime readiness but is not treated as connected until `config/openclaw.json` contains enabled Telegram config. Provisioning completion and Go Live replay saved channel config when the runtime config exists.
- Channel-step navigation model: Step 5 now renders an explicit `Continue To Google Workspace` button in the channel panel navigation itself, even if the channel is already connected or the customer wants to skip ahead and come back later, so the wizard never traps them on the channel panel
- Google Workspace messaging model: Step 6 customer-facing copy now frames runtime status as live-access progress (`connected`, checking, ready, needs attention) with calmer wording, so connected accounts are not immediately framed as broken or in need of reconnect unless the actual runtime error says so
- Profile sync model: the Business Profile page now shows in-page assistant sync progress while a save/manual sync is running, shows the completion result after redirect, and live-tenant workspace prompt/tool-guidance changes can be pushed later with `sync360:resync-live-tenants` without reprovisioning the tenant
- Google auth model: Sync360 owns the Google OAuth web flow; `tenant_google_credentials` is the source of truth and tenant `.openclaw/gogcli/` auth artifacts are a re-seedable runtime cache
- Google auth artifact model: tenant `.openclaw/gogcli/credentials.json` is generated by Sync360 and now includes both top-level OAuth `client_id` / `client_secret` and the nested `installed` block so the live `gog` CLI and Sync360 smoke preflight validate the same credentials shape
- Google keyring model: the tenant `.openclaw/gogcli/keyring/token:default:<email>` file is now written in the encrypted file-keyring format expected by the live `gog` CLI, including RFC3394-compatible AES key wrap for the wrapped content-encryption key, while `token_<email>.json` remains the plain authorized-user token cache used for refresh-token/API smoke preflight
- Google runtime tool model: tenant `config/openclaw.json` now explicitly enables the bundled `gog` skill and appends `gog` to agent skill allowlists so connected workspaces can actually expose Google tooling to the agent
- Admin tenant-detail IA model: `admin/tenants/{tenant}` now uses one route with deep-linkable query tabs for `Overview`, `Workspace`, `Google`, `Skills`, `Agent Runtime`, and `Support` rather than one long blended screen
- Admin tenant customization split model: tenant skills and `Default Skill IDs` now live under the dedicated `Skills` tab, while `Agent Runtime` owns model defaults, prompt overrides, current markdown previews, preview output, and runtime-scoped apply/revert controls
- Admin customization save-scope model: the controller still writes prompt/model draft state to one `tenant_agent_customizations` record, but tenant skill assignment now lives in first-class `tenant_skill_assignments`; tab-scoped saves preserve the opposite surface
- Skill catalog model: repo-authored skills now live under `resources/skill-packs/<skill-id>/` with root `manifest.json`, root OpenClaw `SKILL.md`, root `agent-instructions.md`, and root `RELEASE_NOTES.md`; optional richer supporting docs live under `docs/`, never under a nested `skills/<skill-id>/` folder. `resources/skill-packs/CUSTOM_SKILL_AUTHORING_PROMPT.md` is the reusable prompt/checklist for creating skills that meet Sync360 catalog, runtime, analytics, tracking, privacy, versioning, and release-note criteria. Sync360 imports skill packs into `skill_catalog_items` / `skill_catalog_versions`, tracks orphaned repo deletions as warnings, and exposes admin-only catalog pages under `admin/skills`. Markdown-only Sync360 workspace skills leave `openclaw_skill_ids` and `default_agent_skill_ids` empty so OpenClaw does not try to load nonexistent `/app/skills/<skill-id>/SKILL.md` bundles.
- Skill catalog assignability model: importing a repo skill registers catalog rows but does not make the skill assignable. `skill_catalog_items.is_assignable` is true only when the item is not orphaned and has an active, non-archived published version; publishing/archiving versions resyncs that flag, and tenant assignment still requires an active published version.
- Reference custom skill model: the previous `appointment-booking` reference pack has been renamed to `hello-world`, with catalog label `Hello World (by Sync360)`. The rename migration updates catalog items, catalog versions and manifest JSON, tenant assignments, synced skill analytics rows, and customization/apply snapshots that still contain the old key.
- Skill installation decision rule: if a skill is only a repo-authored OpenClaw skill folder, it should use the catalog-managed `resources/skill-packs -> scan/import -> publish -> assign -> rollout` path; if it depends on an external CLI/binary/auth/config runtime dependency, it should be modeled as a host-managed runtime capability like `gog`, not as a plain catalog skill
- Tenant skill runtime model: live custom skills are materialized into `.openclaw/workspace/skills/<skill-id>/...`, including root `SKILL.md`, root `agent-instructions.md`, root `RELEASE_NOTES.md`, and optional `docs/`; the old `.openclaw/workspace/skill-packs/...` path is cleaned up locally during tenant apply, while already-installed remote skill folders can remain on the tenant runtime and are made ineligible by writing `skills.entries.<skill>.enabled = false` plus removing them from agent skill allowlists when a tenant unassigns a skill
- Tenant assigned-skill guidance model: the runtime now generates `.openclaw/workspace/AGENTS.md` from the current enabled tenant skill assignments; the `Assigned Skill Guidance` section appears when skills are assigned, includes the assigned skill's `agent-instructions.md` content, disappears when skills are unassigned, and is previewable from the admin `Agent Runtime` tab as a generated read-only file rather than a manual prompt-override surface
- Tenant skill rollout model: tenant skill assignment stays version-pinned until an operator explicitly rolls out a selected published version; bulk rollout reuses the existing `ProvisioningJob` + `ApplyTenantAgentCustomization` pipeline with one queued job per selected tenant
- Skill analytics model: analytics-enabled custom skills now declare an explicit manifest contract, emit `conversion_succeeded` events through the Sync360-owned workspace helper at `.openclaw/workspace/.sync360/bin/log-skill-conversion`, write tenant-local rows into `.openclaw/data/analytics/skill-events.sqlite`, and are synced back into the control plane every 30 minutes through `sync360:sync-skill-conversions`; the deployed helper shell/Node script bodies are maintained as resource templates under `resources/runtime-helpers/sync360/`. Sync360 pre-initializes the tenant SQLite database for analytics-enabled assignments during go-live/customization apply, exposes `sync360:init-skill-analytics` for already-live tenants, and warns during sync when an analytics-enabled tenant has no runtime DB.
- Skill analytics reporting model: tenant dashboards now show a `Skill Outcomes` panel with estimated conversion/time/ROI summaries, tenant users can reach it from the sidebar via a dashboard anchor link, admin has a cross-tenant `admin/analytics/skills` page linked from the admin sidebar, and tenant detail has an `Analytics` tab; value metrics are omitted when the skill contract does not provide a value default or event override
- Skill analytics authoring rule: `skill_key` in analytics events must equal catalog `skill.id`, `skill_version` must equal the catalog version string exactly, `conversion_id` is only unique within that skill, and dashboard copy must use estimated language because manifest effort/value defaults are Sync360 benchmarks rather than measured tenant workflow truth
- Skill versioning authoring rule: every repo-authored skill info, behavior, metadata, analytics, privacy, or supporting-doc change must increment `manifest.json.version` and add a concise `RELEASE_NOTES.md` entry containing that version; tenant assignments stay pinned to their current catalog version until an operator rolls out the newer published version
- Admin history model: the `Skills` tab now shows human-readable skill change history derived from applied snapshots, while the raw apply audit with action/status/hash details lives under `Agent Runtime`
- Google runtime contract model: tenant compose generation now injects `GOG_ENABLE_COMMANDS` for the allowlisted direct `gog` service surface and `GOG_ACCOUNT` for the connected Google email, while Sync360 still owns OAuth/account mutation and the tenant runtime uses raw direct `gog` commands rather than Sync360 wrappers
- Google tool-guidance model: generated tenant workspace instructions now treat the connected Google email as the default account, tell the agent to use raw direct `gog` CLI paths, tell the agent not to ask the owner to choose an account unless tooling explicitly reports multiple accounts or a missing default, and only suggest reconnecting when a real tool error indicates invalid/expired/unauthorized credentials
- Google Calendar write-guidance model: generated tenant `TOOLS.md` now includes the pinned `gog calendar create <calendarId> --summary ... --from ... --to ... --reminder ...` command shape and explicitly warns against the unsupported `gog calendar event create`, `--title`, `--start`, `--end`, and `--calendar` forms that caused booking/reminder failures in the live tenant assistant.
- Google failure-memory cleanup model: after a successful Google Workspace verification or smoke test, Sync360 clears the known stale Gmail/account failure memory files for today/yesterday from tenant `.openclaw/workspace/memory/` so old reconnect/account-selection summaries do not keep biasing the live assistant after the runtime is healthy again
- Google smoke-error translation model: when a remote Google smoke run fails over SSH, Sync360 now strips the benign `Warning: Permanently added ... to the list of known hosts.` transport line before surfacing the failure, so onboarding/admin views show the real runtime error instead of SSH host-key noise
- Conversation model: Telegram history is synced from workspace session logs with AI summaries; the control plane no longer exposes channel webhook ingress
- Trial model: 14-day / budget-capped trial with scheduled expiry checks and email notifications
- Admin model: super-admin area is behind `auth`, `admin`, and `local.only` middleware
- Admin operator surface: the tenants list now shows each tenant's Google connection state, live-access/sync label, latest relevant timestamp, and latest recorded runtime error; the tenant detail screen now uses same-route tabbed subscreens (`overview`, `workspace`, `google`, `agent-runtime`, `support`) with query-string deep links, active-tab-preserving redirects, labeled global status badges, and tenant-scoped buttons for client-VPS bootstrap, runtime-capability sync, Google Workspace smoke testing, and re-queueing the existing initial Google sync job without introducing a second repair implementation; the admin overview relies on the shared sidebar for primary admin destinations instead of duplicating those links in the page header
- Admin agent-runtime customization model: `PATCH/POST /admin/tenants/{tenant}/agent-customization*` stores DB-backed drafts for prompt overrides and agent defaults, previews the current effective workspace markdown plus `openclaw.json`, queues apply/revert through `ApplyTenantAgentCustomization`, and degrades gracefully with a setup-needed message when the customization tables are not migrated locally
- Admin agent-runtime input contract: the tenant runtime customization form treats `Default Skill IDs` as one comma-separated text field, and the server normalizes that submitted text into the saved `agent_defaults.default_skill_ids` list before validation/composition so draft save matches the UI contract

## 4. Core flows at a glance

### Signup and provisioning

1. `RegisterController` creates `User`, `Tenant`, `BusinessProfile`, `BusinessProfileFiles`, and `ProvisioningJob`.
2. `ServerPlacementService` selects a server.
3. `ProcessTenantProvisioning` dispatches after commit.
4. `OpenClawProvisioner` allocates a port, ensures a LiteLLM tenant key, prepares the runtime, writes OpenClaw and Compose config, syncs the runtime, starts the tenant container, checks private readiness through `TenantRuntimeService::gatewayBaseUrl()`, then checks the public workspace login URL.
5. `WorkspaceReadyEmailService` sends a neutral "workspace created / continue setup" email after provisioning succeeds.
6. In `ssh` mode, operators bootstrap each client VPS with `sync360:bootstrap-client-vps`, which now also installs any pinned host-managed runtime capabilities declared in config, such as the `gog` binary used by Google Workspace tooling.

### Onboarding and go-live

1. Customer works through `/onboarding` steps for website extraction, business info, tone, capabilities, channel setup, Google Workspace connect, and Go Live.
2. The onboarding Blade surfaces explicit step progress plus a background-setup status card so customers can see what step they are on and whether workspace provisioning is still running behind the scenes.
3. The onboarding Blade polls `/onboarding/state`, but the client preserves unsaved local drafts so background refreshes do not collapse or clear in-progress setup.
4. Successful saves on website extraction, business info, tone, capabilities, and channel setup advance the wizard to the next step automatically, so the customer does not need to save and then click Next separately.
5. While a wizard request is running, the client shows an operation note, disables step navigation/action controls, prevents step changes, and pauses refresh-driven UI application until the request finishes.
6. Navigating back and forth through onboarding or polling `/onboarding/state` does not regenerate the tenant LiteLLM key; key creation remains part of provisioning only, and compose regeneration reads the tenant DB key rather than a possibly stale local runtime `.env`.
6. `BusinessExtractionService` handles website extraction and initial markdown generation.
7. `GoogleOAuthController` and `GoogleWorkspaceOAuthService` own the Google OAuth flow, store encrypted tokens in `tenant_google_credentials`, and enqueue a durable initial Google sync job as soon as the tenant runtime is ready.
8. The initial Google sync job is persisted in `provisioning_jobs` as `initial_google_workspace_sync`, moves through `queued` / `running` / `completed` / `failed`, and runs the same Google auth reseed plus tenant-side smoke verification path used elsewhere.
9. Google auth reseeding writes `.openclaw/gogcli/` artifacts from DB state, including an encrypted `gog`-compatible keyring token plus the token cache file, then the control plane can run the tenant-side Google smoke test to promote runtime status from `synced` to `verified`.
10. Provisioning now also writes the bundled `gog` skill into tenant `config/openclaw.json` and ensures agent skill allowlists include `gog`; later Google runtime syncs re-apply that config so older tenants can be repaired during resync.
11. A shared customer-readiness calculator now determines runtime-ready, customer-ready, go-live-ready, blocking reason, and next action from preloaded tenant + Google sync state; already-live/already-complete skipped tenants are grandfathered so they stay unblocked after deploy.
12. `TenantAgentSyncService::goLive()` writes the full workspace artifact set into `.openclaw/workspace/` (`IDENTITY.md`, `SOUL.md`, `USER.md`, `BOOTSTRAP.md`, `TOOLS.md`, `PROFILE.md`, and `HEARTBEAT.md`) and syncs only workspace markdown files; already-live tenants reuse the same endpoint from Step 7 as a visible `Resync Assistant` action.
13. The generated workspace artifacts now explicitly tell the tenant agent to use connected Google Workspace tools for owner requests about inboxes, calendars, files, contacts, sheets, and docs instead of giving a generic refusal.
14. `TOOLS.md` now gives the tenant agent explicit environment-specific `gog` guidance, including using exec, checking `gog --help` / service help, using native direct `gog` CLI paths, and avoiding generic refusals when Google Workspace is connected.
15. Successful Google verification and the explicit Google smoke-test command now clear the known stale Gmail/account failure memory files from the tenant workspace memory directory after the runtime proves healthy, so older reconnect/account-selection issue summaries stop lingering.
16. `goLive()` still only syncs workspace markdown files and restarts the tenant without overwriting provisioned credentials, and the onboarding controller no longer piggybacks Google sync/verification onto the Go Live request.
17. Existing live tenants do not automatically receive new generated workspace instructions when only the control app is deployed; operators can resync those prompt/workspace-file changes with `php artisan sync360:resync-live-tenants` after deploy.
18. Existing ready tenants that predate a new host-managed runtime capability can be repaired with `php artisan sync360:sync-runtime-capabilities {tenantSelector?} {capability?}`, which installs/verifies the host binary, re-seeds tenant `.openclaw/gogcli/` auth artifacts for connected Google tenants, regenerates full staged compose/config files, pushes changed files, refreshes workspace guidance for live tenants, recreates the tenant when compose changed, corrects Google runtime status to `failed` if verification still breaks, and now clears the known stale Gmail/account failure memory files after a successful verification.

### Conversation logging and summaries

1. Telegram is currently configured in `TenantAgentSyncService::configureChannel()` as an OpenClaw polling-mode channel using `botToken` in `openclaw.json`, not as a control-app webhook-first channel.
2. Live tenant conversation history is treated as session-based history.
3. `sync360:sync-replies` reads workspace session logs through `WorkspaceSessionLogReader`.
4. The command upserts `ConversationLog` records and groups them by `session_id`.
5. `ConversationSummaryService` generates one short AI summary per session and stores it on the session’s records.

### Trial lifecycle

1. Signup sets `trial_ends_at`.
2. `sync360:check-trial-expiry` refreshes LiteLLM spend, evaluates time and budget expiry, sends warning/expired emails, and suspends expired tenants.
3. Dashboard and admin views read the cached trial fields from `Tenant`.

## 5. Critical invariants / gotchas

- `goLive()` must never do a full runtime sync. It must use `DockerComposeRunner::syncWorkspaceFiles()` and not `syncRuntime()`, or provisioned credentials in `compose.yaml` and `config/openclaw.json` can be overwritten.
- Compose regeneration must use `Tenant::litellm_virtual_key` for `OPENAI_API_KEY`; local `.env` can be stale and must not be treated as the LiteLLM source of truth.
- Missing-key generation is now hard-blocked outside active provisioning. If a non-provisioning path reaches `LiteLlmTenantKeyService::ensureTenantKey()` with no stored key, it throws instead of generating a new credential.
- Google connect/callback, initial Google sync, Google disconnect, and `sync360:sync-runtime-capabilities` may rewrite staged runtime compose/config files, but they must do so only from the stored tenant DB LiteLLM key and must never call LiteLLM `/key/generate`.
- Saved Telegram channel state is not equivalent to a live connection. `channel_setup.status=saved` means the bot token is stored and will be replayed when runtime config is ready; `connected` requires enabled Telegram config in `config/openclaw.json`.
- Host-managed runtime capabilities must never be delivered through `goLive()`. Host binaries are installed only through SSH runner commands (`sync360:bootstrap-client-vps` / `sync360:sync-runtime-capabilities`), and tenant runtime files are updated only through deterministic compose/config regeneration plus targeted remote writes.
- Google Workspace auth sync must never piggyback on `goLive()` or use a full runtime sync. `TenantAgentSyncService::configureGoogleWorkspace()` uses targeted runner writes under `.openclaw/gogcli/` and can always re-seed runtime auth from the DB row.
- Google Workspace `runtime_sync_status` is no longer equivalent to a plain file-write result. `pending` means the runtime has not been updated yet or an initial sync job is still queued/running, `synced` means auth artifacts were written, `verified` means the tenant-side smoke test reached live Gmail/Calendar APIs, and `failed` means runtime sync or verification needs attention.
- Customer-facing workspace success is now separate from raw runtime readiness. For not-yet-live tenants in Google-enabled environments, `/tenant/workspace-ready` success and `POST /onboarding/go-live` require Google Workspace to be connected and `runtime_sync_status=verified`; already-live/already-complete skipped tenants are grandfathered.
- A successful Google verification is also the cleanup signal for known stale Gmail/account failure memory files. Those files are safe to remove only after the runtime smoke path is healthy again; do not try to "fix" Google issues by deleting arbitrary workspace memory files before verification succeeds.
- Host-managed runtime capability commands are intentionally SSH-only in v1. `local` mode does not emulate host installs or bind mounts; the commands fail early with a clear error instead.
- Tenant compose generation now includes unconditional host-managed capability mounts declared in the catalog. For `gog`, every tenant compose file mounts `/usr/local/bin/gog` read-only from the host into the container, regardless of whether Google Workspace is currently connected.
- Tenant compose generation now also injects `GOG_ENABLE_COMMANDS` for the allowlisted direct `gog` service surface into every tenant runtime and injects `GOG_ACCOUNT=<connected_google_email>` only when that tenant currently has a connected Google Workspace credential.
- Runtime capability verification is two-stage for binary access: host version/health checks run on the VPS, then the control plane runs `docker exec sync360-<slug> sh -c 'command -v <binary>'` from the host to confirm the live container can actually see the mounted binary.
- Google Workspace smoke verification now checks both auth/API health and the actual native direct `gog` CLI surface the assistant uses: env allowlist/default account, Gmail CLI, Calendar CLI, Drive CLI, Contacts CLI, broader help probes, then refresh-token/Gmail/Calendar API smoke.
- The encrypted `gog` keyring token file is an opaque runtime artifact, not a plain JSON contract. Sync360 must write it with the same RFC3394-compatible AES key-wrap behavior the live `gog` file backend expects; otherwise the CLI fails with unwrap-integrity errors even if local round-trip tests appear healthy. Smoke preflight should validate token-cache/client-credential readiness and then rely on the real native `gog` CLI probes to prove that the keyring payload is readable.
- Remote Google smoke failures should never surface the SSH known-host warning as the primary error. That line is transport noise and is now filtered before `last_error` / operator-facing smoke output is derived.
- In local Docker development, private gateway checks must not use container-local `127.0.0.1`; they must go through the configured host alias (`host.docker.internal` in the shipped `docker-compose.yml`) so the app container can reach tenant ports published on the Docker host.
- When tenant `compose.yaml` env changes, local/remote Google runtime reload must recreate the tenant container (`up -d --force-recreate` / equivalent), not just `restart`, or `XDG_CONFIG_HOME` and keyring env updates will not take effect.
- Tenant runtimes use a fixed Docker container name derived from the slug (`sync360-<slug>`), so deletion and provisioning must explicitly remove stale named containers as part of cleanup to support delete-and-recreate flows safely.
- `workspace_url` is the customer-facing Sync360 URL, not a public OpenClaw URL.
- Private gateway calls should go through `TenantGatewayService` and the runner’s `httpRequest()` contract, not through the public tenant hostname.
- Production needs the scheduler path running. The scheduled commands in `routes/console.php` are part of the live product.
- Historical docs include implementation plans and rollout notes that are no longer safe to treat as current truth.
- Customer-facing language should not expose backend platform names. The current tenant heartbeat sync includes identity guardrails for that reason.
- The admin-panel runtime buttons are wrappers around the existing artisan commands, not a second implementation. If those commands change, the panel behavior should stay aligned with them rather than forking capability logic into the controller.
- The canonical-docs hook is part of the normal local git workflow. It now needs the required canonical doc updates on core code changes, and its failure path must stay compatible with the repo's Bash 3 environment so it reports missing-doc problems instead of crashing on empty arrays under `set -u`.

## 6. Known mismatches to verify

Verified current code behavior:

- Telegram onboarding currently calls `TenantAgentSyncService::configureChannel()`, which writes polling-mode Telegram config into `openclaw.json` and restarts the tenant runtime.
- Telegram no longer exposes a control-app webhook path in the current codebase.
- Google Workspace onboarding is now a required customer-readiness step before Go Live for not-yet-live tenants when the feature is available. The control plane owns the OAuth redirect, callback, token exchange, and DB persistence, then re-seeds GOG runtime auth from `tenant_google_credentials`.
- WhatsApp is not implemented as a live channel integration. The onboarding UI only keeps a disabled "Coming Soon" placeholder.
- The control plane no longer exposes WhatsApp or Telegram webhook routes.

Remaining documentation mismatch:

- Older release notes, plans, and walkthroughs still describe webhook-driven Telegram and WhatsApp flows that have now been removed or left unimplemented.

## 7. Where to look in code first

- [`routes/web.php`](../routes/web.php) — route map for public, auth, onboarding, tenant, conversations, profile, and admin flows
- [`routes/console.php`](../routes/console.php) — scheduled commands and client-VPS bootstrap helper
- [`config/sync360.php`](../config/sync360.php) — provisioning, runtime, workspace, LiteLLM, and deploy settings
- [`app/Http/Controllers/Auth/RegisterController.php`](../app/Http/Controllers/Auth/RegisterController.php) — signup transaction
- [`app/Jobs/ProcessTenantProvisioning.php`](../app/Jobs/ProcessTenantProvisioning.php) — provisioning job orchestration
- [`app/Services/OpenClawProvisioner.php`](../app/Services/OpenClawProvisioner.php) — runtime preparation and deployment
- [`app/Services/TenantRuntimeCapabilityService.php`](../app/Services/TenantRuntimeCapabilityService.php) — host-managed capability catalog, compose/config mutation, SSH install flow, and host/container verification
- [`app/Services/TenantRuntimeService.php`](../app/Services/TenantRuntimeService.php) — paths, ports, workspace URL, runtime generation
- [`app/Services/TenantAgentSyncService.php`](../app/Services/TenantAgentSyncService.php) — go-live sync, channel config, heartbeat/profile sync
- [`app/Services/TenantWorkspaceReadinessService.php`](../app/Services/TenantWorkspaceReadinessService.php) — shared runtime-ready vs customer-ready vs go-live-ready gating and UI blocking reasons
- [`app/Console/Commands/SyncConversationReplies.php`](../app/Console/Commands/SyncConversationReplies.php) — session-log conversation sync
- [`app/Services/TenantGatewayService.php`](../app/Services/TenantGatewayService.php) and [`app/Services/TenantHealthCheckService.php`](../app/Services/TenantHealthCheckService.php) — private gateway access and readiness checks

## 8. Current priorities / open work

The repo still has historical/open planning around:

- channel connection polish and broader channel support
- skill-pack and productization work
- production hardening such as SSH key migration, secret management, and monitoring

Treat `artifacts/archive/` and other historical docs as inputs for future work, not as the current architecture.

## 9. Suggested new-chat prompt

Use a prompt like this in a fresh chat:

> Read `artifacts/MEMORY.md` first, then `artifacts/ARCHITECTURE.md`, then `artifacts/RELEASE_NOTES.md`. Ground answers in current repo code, especially `routes/web.php`, `routes/console.php`, `config/sync360.php`, and the provisioning/onboarding/conversation services. Assume the canonical docs win over historical plans, but code wins over docs. Pay special attention to the private gateway model, session-log conversation sync, and the rule that `goLive()` must sync workspace files only, never the full runtime. Before ending each chat session, update `artifacts/MEMORY.md`, `artifacts/ARCHITECTURE.md`, and `README.md` if the current project truth changed, and update `artifacts/RELEASE_NOTES.md` if the session introduced a user-facing or architectural change worth recording chronologically.
> For UI/design work, read `artifacts/DESIGN_SYSTEM.md` immediately after `artifacts/MEMORY.md` and treat it as the current Blade design-system source of truth. Ignore historical redesign artifacts unless the user explicitly asks for background context.
