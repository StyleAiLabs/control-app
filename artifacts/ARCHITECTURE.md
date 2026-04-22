# Sync360 Control App Architecture

This document describes the current as-built architecture of the Sync360 Control App based on the repo code.

Shared Blade layouts render keyboard-visible focus states via `:focus-visible` outlines on form controls, buttons, and navigation links, drawn using the brand `--accent` token (WCAG 2.4.7 Focus Visible). The authenticated app layout is mobile-responsive: at ≤980px the sidebar collapses to a sticky hamburger top bar; a `.mobile-drawer` (position: absolute, z-index: 99) expands below preserving all navigation elements (alerts bell, nav links, user name, logout) and is toggled by `toggleMobileNav()` with `aria-expanded` management. The sidebar must have `overflow: visible` in the mobile query — otherwise the desktop `overflow-y: auto` clips the drawer.

## 1. Purpose and product boundary

Sync360 Control App is a Laravel monolith that acts as the control plane for tenant-specific OpenClaw workspaces.

Implemented product boundary:

- marketing site and custom auth, including self-serve password reset
- signup and tenant creation
- tenant runtime provisioning and placement
- onboarding and go-live sync
- health checks and operational controls
- conversation history sync and summaries
- trial lifecycle tracking and notification emails
- local-only super-admin operations

Not treated as complete in this repo today:

- billing and subscriptions
- automated DNS management
- comprehensive production observability
- full secret-management hardening
- generalized multi-region placement

## 2. Deployment topology

### Control plane

The control plane is a Laravel application with:

- web app
- queue worker
- scheduler process
- PostgreSQL
- Redis

Authenticated dashboard responses are intentionally marked `no-store` / `no-cache` so browser caching does not preserve stale tenant workspace status cards after local runtime state changes.

The repo supports a Docker Compose development stack and a production control-plane stack in `docker-compose.prod.yml`.

### Frontend design contract

Blade UI design truth lives in [`artifacts/DESIGN_SYSTEM.md`](DESIGN_SYSTEM.md). This architecture document describes application structure and runtime behavior; the design-system document describes UI principles, tokens, typography rules, component patterns, and usage guidance for the shared app/guest layouts and Blade surfaces.

The shared app and guest layouts are the implementation home for the current type contract, spacing scale (`--space-*`), radius scale (`--radius-*`), semantic elevation tokens (`--shadow-panel`, `--shadow-focus`, `--shadow-elevated`), field-level validation CSS (`aria-invalid`, `.field-error`), `prefers-reduced-motion` guards, and reusable UI classes. View-level Blade files should consume those primitives instead of re-declaring font stacks, tracking, status badge behavior, or technical-string wrapping locally. Per-surface responsive breakpoints, dark-mode strategy, motion rules, and form validation patterns are documented in `DESIGN_SYSTEM.md` §10–§13.

Primary authenticated navigation lives in the shared sidebar and mobile drawer; admin overview does not duplicate those sidebar destinations as header action buttons.

### Infrastructure modes

#### `local`

- app, worker, Postgres, and Redis run locally in Docker Compose
- tenant runtimes are staged under `runtime/tenants/<slug>/`
- tenant containers run on the local Docker host
- the local Docker Compose stack pins `SYNC360_INFRASTRUCTURE_DRIVER=local`
- app and worker use `SYNC360_LOCAL_DOCKER_COMPOSE_BIN=docker-compose` inside their containers
- private tenant host-port checks use `host.docker.internal` from inside the app container so local gateway calls reach the Docker host rather than the app container loopback

#### `ssh`

- the control plane stages tenant runtimes locally
- runtime files are copied to a client VPS over SSH
- Docker Compose commands are executed remotely on that VPS
- readiness checks use private loopback HTTP on the client VPS

### Host-managed runtime capabilities

Some tenant runtime features depend on external binaries that are not part of the base OpenClaw image. Sync360 models those dependencies as host-managed runtime capabilities declared in `config/sync360.php`.

Current v1 behavior:

- capability metadata is declared centrally under `sync360.runtime_capabilities`
- the first shipped capability is `gog`
- Sync360 installs the pinned Linux release binary onto the client VPS host
- tenant compose generation mounts that host binary read-only into every tenant container
- tenant `openclaw.json` generation enables the corresponding OpenClaw skill and normalizes agent skill allowlists
- verification checks both the host binary and the running container view of that binary

Important boundaries:

- this model is supported only in `ssh` infrastructure mode
- `local` mode does not emulate host installs or bind mounts for these capabilities
- `goLive()` remains workspace-files-only and must never be used to install host dependencies or replace the full runtime
- for `gog`, Sync360 provides the runtime contract and verification surface, but tenant runtimes still use raw direct `gog` CLI commands rather than Sync360 wrapper commands

### Skill installation decision rule

Sync360 currently has two different installation models for tenant runtime skills:

- Catalog-managed skill packs:
  - use this when the skill is a repo-authored Sync360 workspace skill (`manifest.json`, `SKILL.md`, supporting files) and does not require extra host or container dependencies
  - the skill should live under `resources/skill-packs/<skill-id>/`, be scanned/imported into the Sync360 catalog, published, assigned, and rolled out through the existing tenant apply pipeline
  - `manifest.json.runtime_type` must be explicit; Sync360-materialized workspace skills use `sync360_workspace`, bundled or managed OpenClaw skills use `openclaw_native`, and host/container dependency wrappers use `runtime_capability`
  - the pack root owns `manifest.json`, `SKILL.md`, `agent-instructions.md`, and `RELEASE_NOTES.md`; richer supporting docs should live under `docs/`, not under a nested `skills/<skill-id>/` folder, because Sync360 already materializes the whole pack into `.openclaw/workspace/skills/<skill-id>/`
  - `sync360_workspace` skills are first-class OpenClaw workspace skills loaded from `.openclaw/workspace/skills/<skill-id>/`; Sync360 should materialize them, allowlist them in tenant `openclaw.json`, and restart the runtime after materialized skill or allowlist changes
  - publishing a catalog version is catalog-only; existing tenant assignments stay pinned until an explicit rollout updates `tenant_skill_assignments.skill_catalog_version_id`
  - `agent-instructions.md` is required and cannot be empty; enabled assignments inject its content into generated tenant `AGENTS.md`, and disabling/unassigning the skill removes that injected guidance on the next compose/apply
  - `RELEASE_NOTES.md` is required, cannot be empty, and must include the current manifest version; any skill behavior, metadata, instruction, analytics, privacy, or supporting-doc change must increment `manifest.json.version` and add a concise release note so operators can identify what to roll out
  - imported catalog items are not assignable until an operator publishes a version; `is_assignable` is resynced from active non-archived published versions when importing, publishing, archiving, and via the assignability repair migration
  - use `resources/skill-packs/CUSTOM_SKILL_AUTHORING_PROMPT.md` when creating or updating a skill so manifest shape, analytics emission, tracking payloads, privacy rules, versioning, release notes, and runtime materialization stay consistent
  - the current reference custom skill is `hello-world` with label `Hello World (by Sync360)`; legacy `appointment-booking` database records are migrated into that key across catalog, assignment, analytics, and customization snapshot tables
- Host-managed runtime capabilities:
  - use this when the skill depends on an external CLI, binary, auth store, mounted config directory, or other runtime dependency that must exist outside the plain skill folder
  - in that case the skill is not "just a catalog skill"; it needs a capability model similar to `gog`, where Sync360 installs/verifies the dependency on the VPS, mounts it into tenant containers, manages auth/config separately, then exposes the corresponding OpenClaw skill

Rule of thumb:

- repo-authored markdown workflow only -> `sync360_workspace` catalog-managed skill pack
- bundled or managed OpenClaw skill outside Sync360 workspace materialization -> `openclaw_native`
- skill plus external CLI/runtime dependency -> host-managed runtime capability

### Public and private surfaces

- `workspace_url` is the customer-facing Sync360 URL
- the tenant hostname proxies to the control app upstream
- the OpenClaw gateway binds privately and is reached by the control plane through `TenantGatewayService`
- private gateway requests use `DockerComposeRunner::httpRequest()`
- in local Docker dev, `TenantRuntimeService::gatewayBaseUrl()` resolves to the configured host alias instead of container-local `127.0.0.1`

## 3. Domain model

### `User`

Represents the authenticated customer or super-admin.

Key concerns:

- session auth
- `is_admin` flag
- optional one-to-one tenant relationship for customers

### `Tenant`

Primary customer workspace record.

Key responsibilities:

- ownership and route identity (`slug`, `tenant_id`)
- provisioning state and placement (`server_id`, `assigned_port`, `workspace_url`, `runtime_path`)
- onboarding and agent state (`onboarding_status`, `onboarding_step`, `tone`, `capabilities`, `channel`, `agent_status`)
- LiteLLM tenant key lifecycle and trial tracking
- health-check status and conversation association

Important relationships:

- belongs to `User`
- belongs to `Server`
- has many `ProvisioningJob`
- has many `ConversationLog`
- has one `BusinessProfile`
- has one `BusinessProfileFiles`

### `Server`

Represents a tenant runtime host.

Key concerns:

- SSH connection details
- password-vs-key auth mode
- runtime root and Caddy site paths
- workspace hostname shape and Docker Compose configuration
- capacity counters

### `ProvisioningJob`

Tracks async provisioning attempts.

Key concerns:

- job type and status enum
- payload snapshot
- error message
- start and completion timestamps

### `SystemHealthSignal`

Stores durable app-level operational heartbeat and scheduled-command state.

Key concerns:

- scheduler and queue worker heartbeats
- critical scheduled command start/success/failure timestamps
- latest failure message for scheduled work
- JSON metadata for lightweight runtime context

### `BusinessProfile`

Stores structured customer business context gathered during onboarding.

Key concerns:

- business identity and contact info
- website extraction results
- services, FAQs, target customers, pricing notes
- last sync timestamp into the tenant agent

### `ConversationLog`

Stores owner-visible conversation history synced back from tenant runtimes.

Key concerns:

- external message identifier
- inbound/outbound message text
- `session_id` grouping
- `ai_summary` per session
- response timestamp and meta payload

### `TenantGoogleCredential`

Stores the canonical Google Workspace OAuth state for a tenant.

Key concerns:

- Google account status (`pending`, `connected`, `skipped`, `disconnected`)
- runtime sync / verification status (`pending`, `synced`, `verified`, `failed`)
- encrypted `access_token` and `refresh_token`
- connected Google email and granted scopes
- transient OAuth `state` and PKCE verifier storage
- last sync/error timestamps used to re-seed tenant runtime auth

## 4. Core request and async flows

### Signup and tenant creation

`RegisterController::store()`:

1. validates signup input
2. asks `ServerPlacementService` for a server
3. creates `User`
4. creates `Tenant`
5. creates `ProvisioningJob`
6. creates `BusinessProfile` and `BusinessProfileFiles`
7. increments the server’s `current_clients`
8. logs the user in
9. dispatches `ProcessTenantProvisioning` after commit

### Authentication recovery flow

Guest password recovery is handled by `ForgotPasswordController` and `ResetPasswordController`.

Flow:

1. the customer requests a reset link from `/forgot-password`
2. Laravel's password broker stores a token in `password_reset_tokens`
3. `User::sendPasswordResetNotification()` routes delivery through `PasswordResetEmailService`
4. when Brevo is enabled, the reset email is sent through Brevo's `/smtp/email` API; otherwise Laravel's standard reset notification is used as fallback
5. `/reset-password/{token}` accepts the token, email, and new password
6. the password is updated, the remember token is rotated, and the user is redirected back to login

### Provisioning flow

`ProcessTenantProvisioning` delegates to `TenantProvisioner`, which currently resolves to `OpenClawProvisioner`.

Provisioning flow:

1. load tenant + server
2. mark tenant/job as provisioning/running
3. allocate a port through `TenantRuntimeService::allocatePort()`
4. ensure a tenant LiteLLM key through `LiteLlmTenantKeyService`
5. prepare the local runtime directory and metadata
6. write `config/openclaw.json`
7. write `compose.yaml`
8. optionally write tenant Caddy config that points the tenant hostname to the control app upstream
9. sync the runtime to the target host
10. start the tenant runtime with Docker Compose
11. wait for private `/readyz`
12. wait for public `<workspace_url>/login`
13. mark tenant/job ready/completed
14. replay any saved Telegram channel config into `config/openclaw.json` if the customer saved it before runtime readiness
15. send a neutral post-provisioning "workspace created / continue setup" email

During SSH-host preparation, `sync360:bootstrap-client-vps` now also installs pinned host-managed runtime capabilities declared in config. Provisioning then reuses the shared capability service so generated tenant compose/config files already include the required bind mounts and OpenClaw skill wiring.

### Custom skill conversion analytics

Custom-skill analytics now use a Sync360-owned SQLite database inside each tenant runtime rather than log-file rereads or OpenClaw-owned storage.

Runtime contract:

1. analytics-enabled skills declare an `analytics` block in `resources/skill-packs/<skill>/manifest.json`
2. catalog scan/import validates that contract and rejects analytics-enabled skills that are missing required fields
3. Sync360 workspace skill packs use `runtime_type: sync360_workspace`, materialized `.openclaw/workspace/skills/<skill-id>/SKILL.md`, generated guidance, and matching `openclaw_skill_ids` / `default_agent_skill_ids`; catalog scan rejects contradictory manifests
4. `TenantRuntimeCustomizationComposer` always deploys a shared helper plus runtime registry into the tenant workspace; the helper shell and Node implementations are sourced from `resources/runtime-helpers/sync360/` templates so the PHP composer does not embed large script nowdocs:
   - `.sync360/bin/log-skill-conversion`
   - `.sync360/bin/log-skill-conversion.mjs`
   - `.sync360/skill-analytics-registry.json`
5. skills emit analytics through the workspace exec tool by shelling the helper instead of writing files or SQL directly
6. `TenantSkillAnalyticsRuntimeService` pre-initializes analytics-enabled tenant runtimes during go-live/customization apply, and operators can repair already-live tenants with `sync360:init-skill-analytics`
7. the helper writes one row per successful conversion into `.openclaw/data/analytics/skill-events.sqlite`, supports `--init-only` schema creation, emits JSON status for both initialization and logging, and enables SQLite WAL mode during initialization

Control-plane sync contract:

1. `sync360:sync-skill-conversions` reads only runtime rows after the per-tenant cursor
2. malformed rows are logged, skipped, and cursor-advanced so they are never retried forever
3. synced runtime rows older than 7 days are pruned from the tenant runtime DB
4. central rows are stored in `tenant_skill_conversion_events`
5. per-tenant cursor state is stored in `tenant_skill_analytics_sync_states`
6. the scheduler runs analytics sync every five minutes
7. analytics-enabled tenants with no runtime SQLite database produce a sync warning so missing initialization is visible instead of silently looking like zero conversions

Runtime diagnostics:

- `php artisan sync360:inspect-tenant-skills <tenant>` reports assigned Sync360 skills, materialized workspace skill files, analytics registry entries, SQLite DB state, configured OpenClaw skill IDs, runtime-visible OpenClaw skills when the container is available, and mismatch warnings
- the inspector warns when a `sync360_workspace` skill is missing workspace files, missing from agent allowlists, disabled in `skills.entries`, absent from runtime `openclaw skills list` after a successful runtime check, or when an analytics-enabled tenant has no runtime SQLite DB

Metric rules in v1:

- successful conversions = count of synced `conversion_succeeded` events
- productivity score = successful conversion count
- estimated time saved derives from manifest defaults or event-level effort overrides
- estimated ROI is an operational time-return ratio from those same defaults/overrides
- tenant users reach their analytics through the sidebar `Skill Outcomes` link, which anchors into `/dashboard#skill-outcomes`; admins reach cross-tenant rollups through the sidebar `Skill Analytics` link at `/admin/analytics/skills`
- estimated value is shown only when a manifest default or event override supplies it
- `session_id` can support measured agent elapsed time later, but that remains a v2 improvement

Important boundary for autonomous skills:

- assigning a `sync360_workspace` skill only materializes its files, allowlists its OpenClaw skill IDs, and injects its guidance into generated workspace instructions
- by itself it does not create a background scheduler, Gmail watcher, webhook subscription, Pub/Sub topic, or proactive Telegram action
- `inbox-triage` is the special case that now has an explicit Sync360-owned Gmail polling trigger path
- OpenClaw native `hooks.gmail` / Gmail Pub/Sub remains a future optimization; it is not the implemented trigger path

Inbox Triage polling trigger:

1. `sync360:poll-inbox-triage` runs every five minutes from `routes/console.php`.
2. The command selects live/ready tenants with a verified `TenantGoogleCredential`, runtime/server placement, and an enabled `inbox-triage` assignment, excluding disabled or backoff monitor states.
3. The command queues `ProcessTenantInboxTriage` once per eligible tenant; a selector argument can poll one tenant directly for operator debugging.
4. `TenantInboxGmailRuntimeService` runs `gog --json gmail search 'in:inbox newer_than:2d' --max 20` and `gog gmail get <messageId>` inside the tenant container, using the tenant's configured Google Workspace auth.
5. `TenantInboxMessageFilter` performs mechanical noise suppression only: promotional/social/spam/trash labels, no-reply/system senders, system/security/password/reset/newsletter/delivery subjects, and list/auto-generated headers can be skipped without invoking the agent. Ambiguous or form-like business messages are sent through with neutral `mechanical_hints`.
6. `tenant_inbox_monitor_states` stores per-tenant enabled/status/backoff/error/check timestamps. `tenant_inbox_monitor_messages` stores de-dupe and operational metadata only: Gmail ids, sender domain, subject preview/hash, status, attempts, timestamps, and last error. It must not store full bodies, raw headers, attachments, full prompts, or agent responses.
7. Business-plausible messages are sent through `TenantWorkspaceMessenger::send()` on the private gateway with channel `gmail_inbox_monitor` and sender `sync360-inbox-monitor`. The messenger posts to OpenClaw's private `/hooks/agent` route with `deliver=false`, `wakeMode=now`, a deterministic idempotency key, and an authorization token from `hooks.token`; tenant config composition must enable `hooks` with a stable token that is distinct from the gateway auth token.
8. The trigger message is neutral: it says the message is business-plausible, explicitly says Sync360 has not classified it as high-value, includes optional `telegram_default_chat_id` context, tells the agent to route the event to the workspace skill file at `./skills/inbox-triage/SKILL.md`, not `/app/skills`, and explicitly frames the hook as an internal operating task that must execute the inbox-triage workflow and the skill's Critical Runtime Contracts now rather than returning only an assessment or plan.
9. Gmail search summaries can identify an existing thread handle instead of a fresh Gmail message id, so duplicate suppression must happen after `gog gmail get` returns the actual Gmail message id. Otherwise a new email in an already-known thread can be skipped as a duplicate before the skill ever sees it.
10. Sync360 does not send Telegram notifications, does not classify lead quality, and does not reinterpret the agent response. The `inbox-triage` skill remains responsible for category, lead quality, Telegram notification, Google Drive logging, and analytics. Its skill contract specifies each required side effect with idempotency, command/tool guidance, validation, and failure handling so the agent does not silently skip operational logs. For Gmail-triggered events, the Gmail message id is the primary lead id for Telegram `Lead ref`, Drive log naming, and analytics conversion ids. High-value Telegram notifications include a visible exact `Lead ref: <gmail_message_id>` line so owner follow-up replies can reopen the exact Gmail message rather than reconstructing it from summary text. The current contract front-loads a strict Telegram `message` send schema, forbids poll fields on normal sends, uses minimal `gog drive upload <localPath>` Drive logging, forbids `apply_patch` or local-only file edits as a substitute for Drive logging, forbids public web research for internal inbox triggers unless the owner asks, and requires analytics to be attempted independently for qualified leads with the manifest-required `event_id` / outcome payload fields even when Telegram or Drive logging fails.

`LiteLlmTenantKeyService::ensureTenantKey()` is now intentionally provisioning-only. It returns the existing DB-backed key for already-keyed tenants, but if the tenant has no stored LiteLLM key it will only generate one while `provisioning_status=provisioning`. This prevents future onboarding, Google sync, initial Google sync, go-live/resync, or runtime-capability callers from silently minting a key just by reusing the convenience method.

For local Docker dev, the private readiness check targets the Docker host alias configured by `sync360.host_port_probe_host`, not container-local loopback.

Because the tenant container name is fixed to `sync360-<slug>`, provisioning cleanup also force-removes any stale same-name container before bring-up. This protects delete-and-recreate flows when a prior runtime cleanup was incomplete on the target host.

### Onboarding flow

Authenticated onboarding is handled by `OnboardingController`.

Implemented steps:

1. website extraction
2. business info confirmation
3. personality/tone
4. capabilities
5. channel configuration
6. Google Workspace
7. go live

Services involved:

- `BusinessExtractionService`
- `TenantAgentSyncService`
- `GoogleWorkspaceOAuthService`
- `OnboardingStepCatalog` for the shared step-label contract used by the onboarding wizard and dashboard summaries

The onboarding Blade shows explicit wizard progress and a background-setup status card, then polls `/onboarding/state` in the background to refresh progress and readiness. The client preserves unsaved local drafts for website, business details, tone, capabilities, and channel setup so in-progress edits are not wiped by refreshes. Successful saves on the main setup steps auto-advance the wizard to the next step, and onboarding navigation/state polling does not regenerate the tenant LiteLLM key because key creation remains provisioning-only.

Wizard async actions share a client-side operation state. While website reading, business save, tone save, file preparation, channel connect/disconnect, or Go Live is in flight, the Blade shows a visible status note, disables wizard next/back/step-bar/action controls, blocks `showWizardStep()` from changing the visible step, and pauses refresh-driven UI application until the request finishes. On success, server state is applied before the wizard advances; on failure, the lock is released and the customer stays on the same step with the existing error message.

Customer-facing readiness is now computed by a shared readiness calculator instead of being inferred directly from `TenantProvisioningStatus`. That calculator is pure over preloaded tenant + Google sync inputs and produces:

- runtime-ready / legacy `workspace.ready`
- customer-ready success for `/tenant/workspace-ready`
- go-live-ready for `POST /onboarding/go-live`
- a blocking code/message/next action when the tenant is still waiting on provisioning or Google verification

This separates "the private tenant runtime is provisioned" from "the customer can open the workspace success screen or go live."

The Channel step also keeps an explicit manual forward path. Step 5 now renders a `Continue To Google Workspace` button in the channel-panel navigation so customers can move on even when the channel is already connected or they prefer to finish Google Workspace later. A saved Telegram bot token is reported as `channel_setup.status=saved` until the runtime `config/openclaw.json` actually contains enabled Telegram config; only then does the onboarding state report `connected`. `TenantAgentSyncService::syncSavedChannelIfReady()` is the idempotent replay path used after provisioning and during Go Live.

For already-live tenants, Step 7's submit action becomes `Resync Assistant`. It stays enabled even though `workspace.go_live_ready` is false for live agents, posts to the same `/onboarding/go-live` endpoint, and receives resync-specific progress/success copy. The controller bypasses the pre-live readiness gate only for `agent_status=live`; the sync service still performs its normal runtime/profile/file validation and returns any failure as a visible error.

Google OAuth callback, queued initial Google sync, Google disconnect, and the `sync360:sync-runtime-capabilities` repair command all flow through `TenantAgentSyncService` plus `TenantRuntimeCapabilityService::syncLocalCompose()`. Those paths may regenerate staged `compose.yaml`, but they now always source `OPENAI_API_KEY` from `Tenant::litellm_virtual_key`; they do not mint or rotate LiteLLM keys.

Google Workspace Step 6 now distinguishes between OAuth account status and live runtime readiness. The state payload can report Google Workspace as `pending`, `synced`, `verified`, or `failed`, and the Blade surfaces `last_error` when runtime verification needs attention instead of collapsing everything into a single optimistic "ready" message.

For not-yet-live tenants when the Google feature is available, Step 6 is now part of the required customer-readiness path rather than an optional extra. Connected accounts are described as waiting for workspace, queued, syncing, checking, ready, or needing attention so customers can see the initial sync pipeline moving without being pushed toward reconnecting unless the actual runtime error indicates that reconnect is the right fix.

When that runtime verification runs against an SSH-managed tenant, the control plane now strips the benign SSH known-host warning line from failed remote smoke output before deriving `last_error`. The onboarding/admin surface should therefore show the actual runtime or CLI failure instead of `Warning: Permanently added ... to the list of known hosts.`

When Google verification succeeds, Sync360 now also clears the known stale Gmail/account failure memory files for today and yesterday from the tenant workspace memory directory. This keeps older reconnect/account-selection issue summaries from continuing to bias the live assistant after `gog` and runtime auth are healthy again.

`TenantProfileSyncService` is used for later profile/admin regeneration and resync work, not the main onboarding controller flow.

The Business Profile page is part of that later resync surface. When the tenant is already live, saving `/profile` regenerates assistant files, runs the same workspace-file-only live sync path, and shows in-page progress plus a completion message after redirect. `POST /profile/sync-agent` remains the manual retry path for pushing regenerated workspace files without changing the form first.

`TOOLS.md` is now part of that workspace artifact set. The control plane uses it for environment-specific tool guidance, including how the tenant agent should use exec plus the preconfigured direct `gog` CLI for owner Gmail, Calendar, Drive, Contacts, Sheets, Docs, Slides, Tasks, People, Chat, Classroom, Forms, Apps Script, and Groups requests.

### Google Workspace connect flow

Sync360 owns the full Google OAuth web flow rather than delegating it to the tenant runtime or `gog`.

Flow:

1. the authenticated customer starts connect from Step 6
2. `GoogleWorkspaceOAuthService::begin()` stores OAuth `state` and PKCE verifier in `tenant_google_credentials`
3. `GoogleOAuthController` redirects the browser to Google's consent screen using `services.google.*`
4. Google redirects to `/auth/google/callback`
5. `GoogleOAuthController::callback()` resolves the tenant by stored `oauth_state`, exchanges the code server-to-server, fetches the Google email, and stores encrypted tokens/scopes in `tenant_google_credentials`
6. if the runtime already exists, `TenantAgentSyncService::dispatchInitialGoogleWorkspaceSync()` creates a durable `initial_google_workspace_sync` job in `provisioning_jobs`
7. that job moves through `queued` / `running` / `completed` / `failed` while it re-seeds runtime auth and runs the tenant-side Google smoke test
8. if the auth files were written successfully, runtime status moves to `synced`
9. if the smoke test reaches live Gmail and Calendar APIs from inside the tenant runtime, runtime status moves to `verified`
10. if the runtime is not ready yet, the row stays `connected` with runtime status `pending` until provisioning later dispatches that same initial sync job automatically

Customer-readiness rule:

- for not-yet-live tenants in Google-enabled environments, `/tenant/workspace-ready` success and `POST /onboarding/go-live` require `status=connected` plus `runtime_sync_status=verified`
- already-live or already-complete tenants with older `skipped` Google state are grandfathered so deploy does not lock them out of the workspace

The runtime auth artifacts under `.openclaw/gogcli/` are generated by Sync360. `credentials.json` now includes both top-level OAuth `client_id` / `client_secret` and the nested `installed` payload so the real `gog` CLI and Sync360's own smoke preflight both accept the same credentials shape.

When a Google-connected tenant needs repair after deploy, `sync360:sync-runtime-capabilities` is the canonical operator path. It reinstalls or verifies pinned host capabilities on the VPS, re-seeds the tenant `.openclaw/gogcli/` auth artifacts for connected Google tenants, regenerates the full staged tenant `compose.yaml` and `config/openclaw.json`, pushes changed files remotely, refreshes workspace guidance for live tenants, recreates the tenant when compose changed, reruns capability verification, and writes `runtime_sync_status=failed` with a precise `last_error` if verification still breaks.

V1 scope set:

- identity: `openid`, `email`, `profile`
- Gmail: `gmail.readonly`, `gmail.send`, `gmail.compose`
- Calendar: `calendar`
- Drive: `drive.file`
- Contacts: `contacts.readonly`
- Sheets: `spreadsheets`
- Docs: `documents`

The database is the source of truth. Runtime Google auth files are treated as disposable cache and are recreated from DB after restart, rebuild, reprovision, or manual resync.

Auth-artifact contract:

- `credentials.json` now includes the top-level `client_id` / `client_secret` fields the live `gog` CLI expects, plus the nested `installed` payload
- `config.json` still declares `default_account` and the file keyring backend
- `keyring/token:default:<email>` is now written in the encrypted file-keyring format expected by the upstream `gog` / `99designs/keyring` file backend; it is not plain JSON and the wrapped content-encryption key must use the RFC3394 default wrap IV so the live CLI can unwrap it successfully
- `token_<safe-email>.json` remains the plain authorized-user cache used by Sync360's refresh-token/API smoke preflight
- the smoke script treats the encrypted keyring file as opaque and relies on the real native `gog` CLI probes to prove that the runtime can read it correctly, because local round-trip encryption tests can still pass even when a non-RFC3394 wrap configuration would fail in the live `gog` CLI

Runtime skill enablement rule:

- tenant `config/openclaw.json` now explicitly enables the bundled `gog` skill under `skills.entries.gog`
- tenant agent skill allowlists are also normalized to include `gog` in `agents.defaults.skills` and any existing `agents.list[].skills`
- later Google runtime syncs re-apply that skill wiring so older tenants can be repaired during resync without a full runtime reprovision

Runtime reload rule:

- if Google auth only changes `.openclaw/gogcli/` files, the tenant runtime can restart normally
- if Google sync also changes tenant `compose.yaml` env such as `XDG_CONFIG_HOME`, `GOG_KEYRING_BACKEND`, or `GOG_KEYRING_PASSWORD`, the tenant container must be recreated with `up -d --force-recreate` (or remote equivalent) because `restart` does not reload container env

### Go-live sync flow

`TenantAgentSyncService::goLive()`:

1. validates tenant, profile, and generated files
2. replays saved Telegram channel config if the runtime config exists but the channel has not been applied yet
3. writes `IDENTITY.md`, `SOUL.md`, `USER.md`, `BOOTSTRAP.md`, `TOOLS.md`, `PROFILE.md`, and `HEARTBEAT.md` into `.openclaw/workspace/`, plus materialized workspace skill files under `.openclaw/workspace/skills/<skill-id>/...`
4. syncs only workspace files with `syncWorkspaceFiles()`
5. restarts the tenant runtime if needed
6. updates onboarding and sync timestamps

Critical invariant:

- `goLive()` must never use `syncRuntime()` because that can overwrite provisioned credentials such as the tenant LiteLLM key in `compose.yaml`
- the onboarding controller must not piggyback Google auth sync or verification onto Go Live; the Go Live request is guarded by the shared readiness calculator and only proceeds once Google verification is already complete
- already-live tenants may reuse the Go Live endpoint as a resync action even though `workspace.go_live_ready` is false, but the underlying sync service must still validate runtime/profile/file readiness before writing workspace files
- compose regeneration must use `Tenant::litellm_virtual_key` for `OPENAI_API_KEY`; local runtime `.env` is allowed to provide the gateway token/base URL fallback but is not the source of truth for the tenant LiteLLM key
- LiteLLM key generation is now hard-blocked outside active provisioning. The intended credential path is `OpenClawProvisioner -> LiteLlmTenantKeyService::ensureTenantKey()` after the tenant/job has been marked provisioning.

### Conversation sync flow

Conversation history is session-oriented.

`sync360:sync-replies`:

1. selects live tenants with runtime and server assignment
2. reads workspace memory/session files through `WorkspaceSessionLogReader`
3. upserts `ConversationLog` rows
4. groups rows by `session_id`
5. generates one short AI summary per session through `ConversationSummaryService`
6. stores the summary back on that session’s rows

### Post-deploy live tenant workspace resync

Deploying updated control-plane code does not automatically rewrite generated workspace prompt files for already-live tenants. Those prompt artifacts change only when a sync path explicitly regenerates and pushes them.

To cover that case, `routes/console.php` now provides:

- `sync360:resync-live-tenants`
- `sync360:resync-live-tenants <tenant-id|tenant_id|slug>`

The command filters for eligible live tenants, runs `TenantProfileSyncService::regenerateAndSync()`, and pushes regenerated workspace files without reprovisioning the full runtime. This is the intended operator path after deploys that change generated tenant instructions such as `PROFILE.md` or `HEARTBEAT.md`.

### Trial lifecycle flow

`sync360:check-trial-expiry`:

1. selects active trial tenants with LiteLLM keys
2. refreshes spend data from LiteLLM
3. evaluates time and budget expiry
4. suspends expired tenants through `LiteLlmTenantKeyService`
5. sends warning and expired emails through `TrialNotificationEmailService`

## 5. Host-managed runtime capabilities

### Capability catalog

`config/sync360.php` now declares host-managed runtime capabilities in a structured catalog. For v1, each entry includes:

- `id`
- `activation`
- `install_strategy`
- `version`
- `download_url`
- `sha256`
- `archive_binary_path`
- `host_install_path`
- `container_mounts`
- `env`
- `openclaw_skills`
- `host_verify_commands`
- `container_verify_command`

The shipped `gog` entry is pinned to a specific upstream Linux amd64 GitHub release asset plus SHA-256 checksum. Sync360 does not compile `gog` from source on the client VPS.

For `gog`, the catalog now also defines the tenant runtime contract used across compose generation, smoke verification, and workspace guidance:

- allowlisted direct service commands via `GOG_ENABLE_COMMANDS`
- tenant-specific default account via `GOG_ACCOUNT`
- canonical non-mutating CLI smoke probes
- canonical native direct-CLI guidance strings for generated workspace artifacts

### Shared capability service

`TenantRuntimeCapabilityService` is the shared implementation for this model. It owns:

- capability catalog lookup and validation
- OpenClaw skill enablement in `config/openclaw.json`
- agent skill allowlist normalization
- deterministic compose generation for capability mounts and env
- host install flow for SSH-managed servers
- host verification command execution
- in-container verification command generation using host-side `docker exec`
- staged local compose/config regeneration for repair paths

Both of these paths now run through that shared service:

- initial provisioning in `OpenClawProvisioner`
- later Google Workspace runtime sync/repair flows in `TenantAgentSyncService` and the runtime capability command

### Compose and config generation

Tenant runtime files are regenerated deterministically from tenant state plus the capability catalog.

Rules:

- Sync360 does not line-patch remote `compose.yaml` or `config/openclaw.json`
- the control plane regenerates the full intended local staged file
- it compares the regenerated contents to the staged local copy under `runtime/tenants/<slug>/`
- it only writes the local file and uploads the remote file when the staged copy changed
- if compose changed, the tenant is force-recreated
- if only config changed, the tenant runtime is restarted

For `gog`, the compose bind mount is unconditional across all tenant runtimes:

- host source: `/usr/local/bin/gog`
- container target: `/usr/local/bin/gog`
- read-only mount

For `gog`, compose generation now also injects:

- `GOG_ENABLE_COMMANDS=gmail,calendar,drive,contacts,tasks,sheets,docs,slides,people,chat,classroom,forms,appscript,groups`
- `GOG_ACCOUNT=<connected_google_email>` only when that tenant currently has a connected Google Workspace credential

The binary is shared at the host level, but the auth/config state is still tenant-local because each container mounts its own tenant runtime directory and keeps `.openclaw/gogcli/` under that tenant-specific runtime path.

The `activation` field remains product metadata describing what the capability enables. It does not decide whether the binary mount appears in compose.

### Verification model

Capability verification is layered:

1. host binary exists, is executable, and matches the pinned version
2. the running tenant container can see the binary via host-side `docker exec`
3. capability-specific auth artifacts are present
4. capability-specific smoke tests succeed

For `gog`, the host/container binary checks happen before the existing Google auth and Gmail/Calendar smoke validation. The in-container check is performed from the host with:

`docker exec sync360-<slug> sh -c "command -v gog >/dev/null 2>&1"`

The Google smoke path now verifies the real native direct `gog` CLI surface the assistant uses, not just auth artifacts:

- `GOG_ENABLE_COMMANDS` matches the expected allowlist
- `GOG_ACCOUNT` matches the connected Google email
- `gog --json gmail search 'newer_than:30d' --max 5`
- `gog --json calendar events primary --from <now> --to <+7d>`
- `gog --json drive ls --max 1`
- `gog --json contacts list --max 1`
- `gog <service> --help` succeeds for the broader allowlisted services
- then the existing refresh-token, Gmail API, and Calendar API smoke checks run

Generated tenant `TOOLS.md` also documents the write-side Calendar command shape because the pinned `gog` CLI uses `gog calendar create <calendarId> --summary ... --from ... --to ...` for bookings/reminders. It explicitly rejects the unsupported `gog calendar event create`, `--title`, `--start`, `--end`, and `--calendar` shapes so the assistant does not recover from a read-only smoke pass by inventing non-existent write flags.

This closes the previous gap where auth/API smoke could pass while the assistant still improvised an invalid `gog` command and misreported it as a `credentials.json` problem.

If capability verification fails during repair or Google sync, Sync360 explicitly writes `runtime_sync_status=failed` and stores the precise `last_error`, even if the tenant had previously been marked verified.

### Operator commands

`sync360:bootstrap-client-vps`

- still prepares Caddy, runtime directories, and Docker access on the client VPS
- now also installs pinned host-managed runtime capabilities for that server
- fails if download, checksum validation, extraction, install, or verification fails

`sync360:sync-runtime-capabilities {tenantSelector?} {capability?}`

- SSH-only repair and resync path for existing ready tenants
- ensures the host capability is installed with version-aware idempotency
- regenerates full staged compose/config files
- pushes changed files remotely with `putFile()`
- refreshes workspace guidance for live tenants so runtime contract and prompt guidance stay aligned
- recreates the tenant only when compose changed
- reruns host/container verification and Google smoke tests where applicable
- corrects tenant Google runtime status on success or failure

`sync360:test-google-workspace <tenant>`

- surfaces the verification layers more explicitly:
  - host capability
  - container binary
  - `GOG_ENABLE_COMMANDS` / `GOG_ACCOUNT` runtime env
  - Gmail CLI
  - Calendar CLI
  - Drive CLI
  - Contacts CLI
  - broader help probes
  - runtime artifacts
  - container smoke

### Admin-panel operator entrypoints

The local-only super-admin tenant detail page now exposes tenant-scoped wrappers around the same operator commands:

- `Queue Google Sync`
- `Bootstrap VPS`
- `Sync Runtime Capabilities`
- `Test Google Workspace`

Implementation notes:

- the tenants list and tenant detail page derive Google connection/live-access state from `tenant_google_credentials` plus the latest `initial_google_workspace_sync` job, so operators see connection state, sync label, relevant timestamps, and last recorded errors in the same vocabulary used by onboarding
- the admin controller invokes the existing artisan commands rather than re-implementing the runtime logic
- `Queue Google Sync` delegates to `TenantAgentSyncService::dispatchInitialGoogleWorkspaceSync()` so the panel reuses the durable job path instead of running a one-off inline sync
- bootstrap targets the tenant's assigned server
- runtime-capability sync targets the tenant slug and applies the full repair path for that tenant
- Google smoke test targets the tenant slug and reports the existing layered verification result back through the flashed admin status message
- the tenant detail UI is now organized as one route with query-driven tabs: `Overview`, `Workspace`, `Google`, `Skills`, `Agent Runtime`, and `Support`
- tenant skill packs and comma-separated `Default Skill IDs` live in the `Skills` tab, while `Agent Runtime` is reserved for model defaults, prompt overrides, current markdown previews, preview output, and runtime apply/revert controls
- the controller still persists both surfaces into the same `tenant_agent_customizations` record, but save handling is scoped by tab so a `Skills` save preserves prompt/model data and an `Agent Runtime` save preserves assigned packs and default skill IDs
- the `Skills` panel no longer shows the raw apply audit; instead it renders a derived skill change history such as enabled/disabled packs and added/removed default skill IDs, while the generic apply log with before/after hashes remains on `Agent Runtime`
- the `Skills` tab now also shows each assigned skill's pinned assigned version, the latest published catalog version, update-available state, and a polling runtime-apply progress panel; it must not imply that Save Draft upgrades tenant skill versions
- the skill detail screen now acts as the rollout surface for published versions, including outdated-tenant lists, guarded selected/all-outdated rollout actions, exact state-transition banners, and polling rollout progress backed by `ProvisioningJob`
- the skill-id editor still uses a single comma-separated `Default Skill IDs` text input, and the controller normalizes that text into the canonical saved list before validation/composition
- this keeps the panel and CLI paths behaviorally aligned

### Admin tenant-detail information architecture

`GET /admin/tenants/{tenant}` remains the only tenant-detail route, but the page is now organized as one shell with internal tabbed subscreens selected by `?tab=...`.

Current allowlisted tabs:

- `overview`
- `workspace`
- `google`
- `agent-runtime`
- `support`

Implementation notes:

- the controller validates the `tab` query parameter and falls back to `overview` for missing or invalid values
- the page renders a compact left-side subnav on desktop and a horizontal tab row on smaller screens
- top-level tenant state is shown as labeled chips such as provisioning, agent, health, workspace, and Google so operators can tell what each status refers to without opening a panel first
- panel actions redirect back to the relevant tab so retry/apply/test flows preserve local context rather than bouncing operators to the top of the page
- the active tab uses explicit visual treatment plus `aria-current="page"`

### Admin agent-runtime customization surface

The `agent-runtime` tab is an internal Sync360-owned configuration layer for tenant runtime customization. It does not expose arbitrary file editing or raw `openclaw.json` mutation.

Current behavior:

- the panel can save draft prompt overrides for `IDENTITY.md`, `SOUL.md`, `USER.md`, and `BOOTSTRAP.md`
- the panel can assign allowlisted Sync360 skill packs and override allowlisted agent defaults such as the default model and extra default skill IDs
- the page renders the tenant's current composed prompt content for those files before the override editors so operators can see the existing effective runtime content before appending or replacing it
- preview, apply, revert, and apply-log actions all stay on the same tenant page and preserve the `agent-runtime` tab selection
- apply and revert queue `ApplyTenantAgentCustomization` jobs through `provisioning_jobs` and record history in `tenant_agent_customization_applies`
- a dedicated gate controls live apply/revert authority separately from broad admin read/edit access
- if the customization tables are missing locally, the page skips eager-loading those relations and renders an unavailable/setup-needed state instead of throwing a 500

### Future skill flow

When adding a new OpenClaw skill that depends on an external host binary, the canonical flow is:

1. classify it as config-only or host-managed runtime capability
2. add a catalog entry with pinned version, download URL, checksum, mounts, env, and verification commands
3. wire its compose/config behavior through `TenantRuntimeCapabilityService`
4. add verification coverage for host presence, container visibility, and any skill-specific smoke/auth checks
5. expose the activation point in onboarding, profile, or admin flows if the product needs a user-visible switch
6. document the upgrade path for future pinned-version bumps

### Pinned-version upgrade workflow

For `gog` and future host-managed capabilities, the operator workflow is:

1. update `version`, `download_url`, and `sha256` in `config/sync360.php`
2. deploy the control plane
3. run `php artisan sync360:bootstrap-client-vps <server>` on each SSH-managed client VPS
4. run `php artisan sync360:sync-runtime-capabilities <tenant-or-scope> <capability>` to push regenerated compose/config and recreate affected tenants when needed
5. run `php artisan sync360:test-google-workspace <tenant>` for affected Google-connected tenants

## 6. Runtime generation and file layout

### Local staging

Tenant runtimes are staged under:

- `runtime/tenants/<slug>/`

The base template comes from:

- `templates/tenant/`

`TenantRuntimeService::prepareRuntime()` ensures these paths exist in the staged runtime:

- `.env`
- `metadata.json`
- `config/`
- `data/`
- `logs/`
- `workspace/`
- `.openclaw/workspace/`
- `.openclaw/gogcli/keyring/`

### Core generated files

- `.env` — tenant env values such as slug, assigned port, workspace URL, and provisioning extras
- `metadata.json` — tenant, server, job, and runtime metadata snapshot
- `config/openclaw.json` — gateway, agent, and model/provider config
- `compose.yaml` — tenant container definition
- `config/workspace.caddy` — tenant hostname reverse-proxy config when Caddy is managed
- `.openclaw/gogcli/credentials.json` — Google OAuth client payload rewrapped for `gog`
- `.openclaw/gogcli/config.json` and `keyring/*` — file-backed `gog` auth cache derived from `tenant_google_credentials`
- `.openclaw/workspace/TOOLS.md` — environment-specific tool guidance injected into the tenant runtime, including `gog` usage notes for connected Google Workspace tenants

### Remote layout

For SSH mode, `TenantRuntimeService::remoteRuntimePath()` resolves the remote runtime path from the assigned server’s `runtime_root`.

The expected remote layout is:

- `<runtime_root>/tenants/<slug>/`

### Workspace sync boundary

There are two separate sync shapes in the runner contract:

- `syncRuntime()` — full runtime sync used for initial provisioning
- `syncWorkspaceFiles()` — markdown-only sync used by go-live/profile sync to avoid overwriting credentials

Google auth sync is a third shape:

- targeted `putFile()` / `removeDirectory()` writes under `.openclaw/gogcli/`, plus a runtime restart when auth artifacts change

## 7. External integrations

### LiteLLM

Two distinct roles exist in the current codebase:

- tenant LiteLLM keys for workspace runtime operation
- platform LiteLLM access for extraction and conversation summaries

Related services:

- `LiteLlmTenantKeyService`
- `BusinessExtractionService`
- `ConversationSummaryService`

### OpenClaw

Each tenant gets its own OpenClaw runtime.

Current gateway model:

- private bind via loopback port mapping
- token auth in generated config
- control UI disabled in generated config
- bundled `gog` skill explicitly enabled in generated config and added to agent skill allowlists
- control-plane access through the private gateway helper, not the public hostname

### Email

Email services in the monolith include:

- `WorkspaceReadyEmailService`
- `TrialNotificationEmailService`

### Channel integrations

Current code supports a live Telegram configuration path and a WhatsApp placeholder in onboarding.

Important nuance:

- Telegram onboarding currently calls `TenantAgentSyncService::configureChannel()`, which writes polling-mode Telegram config into `openclaw.json` and restarts the tenant runtime
- Telegram no longer exposes a control-app webhook route; the intended flow is polling in the tenant runtime plus session-log sync in the control plane
- WhatsApp is not implemented as a live integration path; onboarding keeps only a disabled "Coming Soon" placeholder
- the conversation-sync architecture relies on workspace session logs plus scheduled sync
- current code/comments describe polling/session-log sync as the primary live Telegram conversation-history path

### Google Workspace

Google Workspace connect uses Sync360's shared Google OAuth app and direct HTTP token exchange in the control plane.

Key runtime details:

- the same platform client is transformed from `{"web": ...}` to `{"installed": ...}` for `gog` compatibility
- `TenantRuntimeService` provisions `XDG_CONFIG_HOME`, `GOG_KEYRING_BACKEND=file`, and a per-tenant `GOG_KEYRING_PASSWORD`
- the file-backed keyring lives under the mounted tenant runtime path so it survives normal container restarts
- the runtime keyring is still treated as cache only because `tenant_google_credentials` remains canonical
- `TenantGoogleWorkspaceSmokeTestService` is the end-to-end verifier: it runs inside the tenant runtime, confirms `XDG_CONFIG_HOME`, verifies `GOG_ENABLE_COMMANDS` / `GOG_ACCOUNT`, runs native direct `gog` CLI probes, exchanges the refresh token, and calls Gmail profile plus Calendar list APIs
- `verified` therefore means live runtime Google access worked from inside the tenant container, while `synced` only means the auth artifacts were written successfully
- runtime Google usability depends on both auth artifacts and skill wiring: `gog` must be enabled in `openclaw.json` and included in agent skill allowlists, not just present under `.openclaw/gogcli/`
- generated `PROFILE.md` and `HEARTBEAT.md` now explicitly instruct the agent to treat owner inbox/calendar/file requests as internal operating tasks and to use connected Google Workspace tools instead of giving a generic refusal
- generated `TOOLS.md` tells the agent to use exec plus direct `gog` commands for those owner requests, inspect `gog --help` and product-specific help when needed, treat the connected Google email as the default account, avoid asking the owner to choose an account unless tooling explicitly reports multiple accounts or no default account, avoid `gog auth` mutation during normal requests, and avoid reconnect/credentials advice unless a real tool error indicates an auth failure

### SSH and remote orchestration

Remote runtime operations are abstracted by `DockerComposeRunner`.

Implementations:

- `LocalDockerComposeRunner`
- `SshDockerComposeRunner`

Responsibilities:

- runtime sync
- workspace-only sync
- remote file writes/removals
- remote Docker Compose control
- private HTTP requests to the tenant gateway
- port probing and readiness waiting

### Control-plane deploy

`ControlAppDeploymentService` triggers and inspects a host-side deploy script over SSH for the primary control-plane server.

This path is intentionally separate from tenant provisioning.

## 8. Routes and scheduled jobs

### Web routes

Defined in `routes/web.php`.

Public routes:

- `/`
- `/signup`
- `/login`
- `/auth/google/callback`

Authenticated customer routes:

- `POST /logout`
- `/dashboard`
- `POST /dashboard/refresh-trial-usage`
- `/conversations`
- `/profile`
- `PATCH /profile`
- `POST /profile/sync-agent`
- `/onboarding`
- `/onboarding/state`
- `POST /onboarding/extract-business`
- `POST /onboarding/business-info`
- `POST /onboarding/personality`
- `POST /onboarding/capabilities`
- `POST /onboarding/channel`
- `POST /onboarding/channel/disconnect`
- `GET /onboarding/google/connect`
- `POST /onboarding/google/skip`
- `POST /onboarding/google/disconnect`
- `POST /onboarding/go-live`
- `/tenant/setup`
- `/tenant/status`
- `/tenant/workspace-ready`
- `/workspace/{tenant:slug}`

Admin routes under `local.only` and `admin`:

- `/admin`
- `/admin/users`
- `/admin/tenants`
- `GET /admin/tenants/{tenant}`
- `DELETE /admin/tenants/{tenant}`
- `/admin/jobs`
- `GET /admin/deploy/control-app/status`
- `POST /admin/deploy/control-app`
- `POST /admin/jobs/{tenant}/retry`
- `GET /admin/system-health/status`
- `POST /admin/tenants/{tenant}/health-check`
- `POST /admin/tenants/{tenant}/resync-agent`
- `POST /admin/tenants/{tenant}/runtime/bootstrap`
- `POST /admin/tenants/{tenant}/runtime/capabilities/sync`
- `POST /admin/tenants/{tenant}/google/sync`
- `POST /admin/tenants/{tenant}/google/test`
- `PATCH /admin/tenants/{tenant}/agent-customization`
- `POST /admin/tenants/{tenant}/agent-customization/preview`
- `POST /admin/tenants/{tenant}/agent-customization/apply`
- `POST /admin/tenants/{tenant}/agent-customization/revert`
- `GET /admin/tenants/{tenant}/agent-customization/apply-log`
- `POST /admin/tenants/{tenant}/workspace/start`
- `POST /admin/tenants/{tenant}/workspace/stop`
- `POST /admin/tenants/{tenant}/workspace/restart`

### Scheduled commands

Defined in `routes/console.php`.

Implemented recurring commands:

- `sync360:system-health-heartbeat` — every minute
- `tenants:health-check` — every five minutes
- `sync360:check-trial-expiry` — every thirty minutes
- `sync360:sync-replies` — every ten minutes
- `sync360:sync-skill-conversions` — every five minutes
- `sync360:poll-inbox-triage` — every five minutes

Also present:

- `sync360:bootstrap-client-vps` — bootstrap helper for a client VPS
- `sync360:sync-runtime-capabilities` — install/verify host-managed runtime capabilities and resync compose/config for ready tenants
- `sync360:resync-live-tenants` — regenerate and push workspace files, including materialized workspace skill files, to existing live tenants after prompt/profile sync changes

## 9. Operational controls

### System health

The Admin Overview includes a System Health panel backed by `SystemHealthService` and `system_health_signals`.

Current v1 behavior:

- `sync360:system-health-heartbeat` records `scheduler` every minute
- that heartbeat dispatches `RecordQueueWorkerHeartbeat`, and the `queue_worker` signal updates only when a queue worker handles the job
- scheduler hooks around critical scheduled commands record start, success, and failure state
- `GET /admin/system-health/status` returns the same summary payload used by the Admin Overview poller
- database queue metrics include pending, reserved, failed, and oldest pending age when the active queue driver is `database`

This is intentionally app-level health. It does not inspect Docker container/process state.

### Tenant health and status

`TenantHealthCheckService`:

- checks server/runtime/workspace prerequisites
- verifies the container is running
- calls private `/readyz` through `TenantGatewayService`
- persists health status and failure messaging on the tenant

### Admin workspace controls

Admin actions expose start/stop/restart and health/resync operations for tenant runtimes.

They now also expose tenant-scoped runtime-capability repair/testing actions by delegating to the existing artisan commands for VPS bootstrap, runtime-capability sync, and Google Workspace smoke testing.

### Retry model

Provisioning retry uses a new `ProvisioningJob` record instead of mutating historical job records.

### Local-only admin constraint

The admin surface is intentionally restricted by:

- authenticated user
- admin flag
- `local.only` middleware

### Control-plane deploy control

The admin UI can trigger a host-side deploy script through `ControlAppDeploymentService` and read back status, commit info, and recent logs.

## 10. Configuration surface

Primary architecture settings live in `config/sync360.php`.

Main configuration groups:

- `admin_local_only`
- runtime and template roots
- infrastructure driver and SSH tooling
- tenant port range
- provisioning driver and delay
- OpenClaw image, service, readiness, and model settings
- runtime capability catalog
- workspace proxy and gateway timeouts
- LiteLLM plans and budgets
- control-plane deploy settings
- summary-refresh toggle

Additional supporting config lives in:

- `config/services.php`
- `config/queue.php`
- `config/mail.php`
- `config/session.php`
- `config/database.php`

Notable current additions:

- `services.google.client_id`
- `services.google.client_secret`
- `services.google.redirect_uri`
- `services.google.project_id`
- `skill_catalog.enabled`
- `skill_catalog.scan_enabled`
- `skill_catalog.import_enabled`
- `skill_catalog.rollout_enabled`

## 11. Constraints and known gaps

- Billing is not implemented.
- Secret-management and SSH-key hardening are not complete.
- DNS automation is external to this repo.
- Historical release notes and plans still contain webhook-first Telegram narratives and more complete WhatsApp claims than the current implementation.
- Production assumptions should be verified against the deployed environment rather than inferred from repo docs alone.
- Skill analytics import is a scheduled control-plane pull from tenant runtime SQLite databases. The current cadence is every five minutes via `sync360:sync-skill-conversions`, not a push-based realtime stream.
- In remote/SSH mode, analytics import reads the tenant DB from inside the running tenant container with `docker exec` plus Node SQLite. It no longer requires the client VPS host to have a `sqlite3` binary installed.
- Analytics sync is failure-isolated per tenant: one broken tenant transport should be logged into that tenant's sync-state record and must not prevent other tenants from importing successfully in the same scheduler run.
- Inbox Triage proactive monitoring is implemented as a Sync360-owned polling trigger, not OpenClaw `hooks.gmail` / Gmail Pub/Sub. It still uses OpenClaw's generic private `/hooks/agent` HTTP ingress to wake the tenant agent; missing Telegram destination context does not block event delivery, and the skill receives `telegram_default_chat_id: null` before deciding the next action.
- Host-managed runtime capability commands are intentionally unsupported in `local` mode.
- `goLive()` must continue to sync workspace files only and must not be extended to deliver host binaries or full runtime config.
- Tenant skill management is now split between DB-backed catalog/assignment state and runtime diagnostics. The tenant `Skills` tab remains the assignment surface, while a separate read-only `Runtime Available Skills` panel shells into the tenant runtime with `openclaw skills list --eligible` for operator diagnostics only.
- Tenant skill assignment controls runtime eligibility, not physical installation. Already-installed skill folders may remain on the tenant runtime after unassign, but Sync360 must remove the skill from agent allowlists and write `skills.entries.<skill>.enabled = false` so OpenClaw stops treating it as eligible. Any tenant-level helper instructions about assigned skills must be generated from the current enabled assignments and disappear again when a skill is unassigned.
- Tenant skill guidance is generated into `.openclaw/workspace/AGENTS.md`, not persisted as a manual prompt override. The `Assigned Skill Guidance` section is composed from the currently enabled tenant skill assignments, appears when one or more skills are assigned, disappears when none are assigned, and is exposed in the admin `Agent Runtime` preview as a generated read-only file so operators can inspect the effective guidance.
- Tenant runtime skill discovery is diagnostic, not authoritative. Assignment and rollout still come from `SkillCatalogItem`, `SkillCatalogVersion`, `TenantSkillAssignment`, and the existing tenant customization/apply pipeline.

## 12. Code map for contributors

Start with these files:

- `routes/web.php`
- `routes/console.php`
- `config/sync360.php`
- `app/Http/Controllers/Auth/RegisterController.php`
- `app/Http/Controllers/OnboardingController.php`
- `app/Http/Controllers/GoogleOAuthController.php`
- `app/Http/Controllers/AdminController.php`
- `app/Jobs/ProcessTenantProvisioning.php`
- `app/Services/OpenClawProvisioner.php`
- `app/Services/GoogleWorkspaceOAuthService.php`
- `app/Services/GogAuthStorageService.php`
- `app/Services/TenantRuntimeCapabilityService.php`
- `app/Services/TenantRuntimeService.php`
- `app/Services/SkillCatalogService.php`
- `app/Services/TenantSkillAssignmentService.php`
- `app/Services/TenantRuntimeSkillDiscoveryService.php`
- `app/Services/TenantAgentSyncService.php`
- `app/Services/TenantGatewayService.php`
- `app/Services/TenantHealthCheckService.php`
- `app/Services/ConversationSummaryService.php`
- `app/Services/WorkspaceSessionLogReader.php`
- `app/Console/Commands/SyncConversationReplies.php`
- `app/Services/ControlAppDeploymentService.php`

The most useful models for orientation are:

- `Tenant`
- `Server`
- `ProvisioningJob`
- `TenantGoogleCredential`
- `BusinessProfile`
- `ConversationLog`
