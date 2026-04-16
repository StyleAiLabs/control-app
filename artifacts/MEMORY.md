# Sync360 Control App Memory

Last verified: `2026-04-17`

This memory is based on the current repo code and current canonical docs. It is not a guarantee about live production state.

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
3. [`RELEASE_NOTES.md`](RELEASE_NOTES.md)

Canonical docs:

- `artifacts/MEMORY.md` is the durable new-chat starter
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
- Authenticated dashboard responses are now sent with no-cache headers so workspace status cards do not get stuck on stale browser snapshots
- Password reset delivery: uses Brevo's HTTP email API when Brevo is enabled; falls back to Laravel's default notification pipeline otherwise
- Infrastructure modes: `local` and `ssh`
- Tenant runtime staging: `runtime/tenants/<slug>/`
- Tenant runtime deployment: one OpenClaw runtime per tenant
- Workspace URL model: customer-facing Sync360 URL on the tenant hostname; private gateway stays behind the control plane
- Local dev runtime model: the Docker Compose dev stack forces `SYNC360_INFRASTRUCTURE_DRIVER=local`, uses `docker-compose` inside the app/worker containers, and reaches tenant host ports through `host.docker.internal`
- Onboarding model: signup provisions the runtime in the background, while the customer completes a seven-step setup flow ending in optional Google Workspace connect and Go Live; the onboarding UI polls server state without wiping in-progress drafts and now advances automatically after successful saves on the main setup steps
- Google auth model: Sync360 owns the Google OAuth web flow; `tenant_google_credentials` is the source of truth and tenant `.openclaw/gogcli/` auth artifacts are a re-seedable runtime cache
- Conversation model: Telegram history is synced from workspace session logs with AI summaries; the control plane no longer exposes channel webhook ingress
- Trial model: 14-day / budget-capped trial with scheduled expiry checks and email notifications
- Admin model: super-admin area is behind `auth`, `admin`, and `local.only` middleware

## 4. Core flows at a glance

### Signup and provisioning

1. `RegisterController` creates `User`, `Tenant`, `BusinessProfile`, `BusinessProfileFiles`, and `ProvisioningJob`.
2. `ServerPlacementService` selects a server.
3. `ProcessTenantProvisioning` dispatches after commit.
4. `OpenClawProvisioner` allocates a port, ensures a LiteLLM tenant key, prepares the runtime, writes OpenClaw and Compose config, syncs the runtime, starts the tenant container, checks private readiness through `TenantRuntimeService::gatewayBaseUrl()`, then checks the public workspace login URL.
5. `WorkspaceReadyEmailService` sends the workspace-ready email after provisioning succeeds.

### Onboarding and go-live

1. Customer works through `/onboarding` steps for website extraction, business info, tone, capabilities, channel setup, optional Google Workspace connect, and Go Live.
2. The onboarding Blade polls `/onboarding/state`, but the client preserves unsaved local drafts so background refreshes do not collapse or clear in-progress setup.
3. Successful saves on website extraction, business info, tone, capabilities, and channel setup advance the wizard to the next step automatically.
4. `BusinessExtractionService` handles website extraction and initial markdown generation.
5. `GoogleOAuthController` and `GoogleWorkspaceOAuthService` own the Google OAuth flow, store encrypted tokens in `tenant_google_credentials`, and trigger runtime reseeding when the workspace is ready.
6. `TenantAgentSyncService::goLive()` writes the full workspace artifact set into `.openclaw/workspace/` (`IDENTITY.md`, `SOUL.md`, `USER.md`, `BOOTSTRAP.md`, `PROFILE.md`, and `HEARTBEAT.md`) and syncs only workspace markdown files.
7. Google auth reseeding writes `.openclaw/gogcli/` artifacts from DB state and force-recreates the tenant container when `compose.yaml` env changed, because a plain restart does not reload container env.
8. `goLive()` still only syncs workspace markdown files and restarts the tenant without overwriting provisioned credentials.

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
- Google Workspace auth sync must never piggyback on `goLive()` or use a full runtime sync. `TenantAgentSyncService::configureGoogleWorkspace()` uses targeted runner writes under `.openclaw/gogcli/` and can always re-seed runtime auth from the DB row.
- In local Docker development, private gateway checks must not use container-local `127.0.0.1`; they must go through the configured host alias (`host.docker.internal` in the shipped `docker-compose.yml`) so the app container can reach tenant ports published on the Docker host.
- When tenant `compose.yaml` env changes, local/remote Google runtime reload must recreate the tenant container (`up -d --force-recreate` / equivalent), not just `restart`, or `XDG_CONFIG_HOME` and keyring env updates will not take effect.
- Tenant runtimes use a fixed Docker container name derived from the slug (`sync360-<slug>`), so deletion and provisioning must explicitly remove stale named containers as part of cleanup to support delete-and-recreate flows safely.
- `workspace_url` is the customer-facing Sync360 URL, not a public OpenClaw URL.
- Private gateway calls should go through `TenantGatewayService` and the runner’s `httpRequest()` contract, not through the public tenant hostname.
- Production needs the scheduler path running. The scheduled commands in `routes/console.php` are part of the live product.
- Historical docs include implementation plans and rollout notes that are no longer safe to treat as current truth.
- Customer-facing language should not expose backend platform names. The current tenant heartbeat sync includes identity guardrails for that reason.

## 6. Known mismatches to verify

Verified current code behavior:

- Telegram onboarding currently calls `TenantAgentSyncService::configureChannel()`, which writes polling-mode Telegram config into `openclaw.json` and restarts the tenant runtime.
- Telegram no longer exposes a control-app webhook path in the current codebase.
- Google Workspace onboarding is now a separate optional step before Go Live. The control plane owns the OAuth redirect, callback, token exchange, and DB persistence, then re-seeds GOG runtime auth from `tenant_google_credentials`.
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
- [`app/Services/TenantRuntimeService.php`](../app/Services/TenantRuntimeService.php) — paths, ports, workspace URL, runtime generation
- [`app/Services/TenantAgentSyncService.php`](../app/Services/TenantAgentSyncService.php) — go-live sync, channel config, heartbeat/profile sync
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
