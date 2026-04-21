# Sync360 Control App

Sync360 Control App is a Laravel monolith that provisions and manages tenant-specific OpenClaw workspaces for a multi-tenant AI service platform.

It currently covers the full control-plane loop:

- marketing site, signup, login, self-serve password reset, and dashboard
- tenant creation, server assignment, and async provisioning
- local and SSH-based tenant runtime deployment
- guided onboarding with visible progress, draft-safe state refresh, optional Google Workspace connect, live Google verification status, and go-live sync
- Google Workspace onboarding now uses calmer live-access wording in Step 6 so customers can tell the difference between connected, checking, ready, and needs-attention states without being pushed toward reconnect too early
- the Channel step now always shows an explicit `Continue To Google Workspace` button in the Step 5 channel navigation itself, so customers can move from Step 5 to Step 6 even when channel setup is already connected or they want to come back later
- Business Profile sync with in-page progress/completion feedback for live assistant resyncs
- tenant workspace tool guidance via generated `TOOLS.md`, including default-account behavior, native direct `gog` CLI usage, and guardrails against hallucinated reconnect or `credentials.json` advice
- tenant runtime config now explicitly enables the bundled `gog` skill in `openclaw.json` so connected Google Workspace tooling is actually available to the agent
- host-managed runtime capability installs for external tenant dependencies such as `gog`, using pinned VPS binaries plus read-only tenant bind mounts
- tenant Google runtime auth reseeding now writes a `gog`-compatible encrypted file-keyring token artifact plus token cache files instead of a plaintext stand-in, using RFC3394-compatible AES key wrap so the live Gmail CLI and smoke path validate the same auth contract
- private gateway access for health checks and runtime integration
- conversation history sync from workspace session logs
- trial lifecycle tracking and notification emails
- local-only super-admin operations and control-plane deploy trigger

## Docs Map

Start here when opening a new chat or trying to understand the repo:

- `README.md` — canonical repo entrypoint
- [`artifacts/MEMORY.md`](artifacts/MEMORY.md) — canonical new-chat starter and durable project memory
- [`artifacts/DESIGN_SYSTEM.md`](artifacts/DESIGN_SYSTEM.md) — canonical UI/design-system reference for Blade surfaces
- [`artifacts/ARCHITECTURE.md`](artifacts/ARCHITECTURE.md) — canonical as-built technical architecture reference
- [`artifacts/RELEASE_NOTES.md`](artifacts/RELEASE_NOTES.md) — canonical chronological engineering and product history

## Doc Status

Canonical/core docs:

- `README.md`
- [`artifacts/MEMORY.md`](artifacts/MEMORY.md)
- [`artifacts/DESIGN_SYSTEM.md`](artifacts/DESIGN_SYSTEM.md)
- [`artifacts/ARCHITECTURE.md`](artifacts/ARCHITECTURE.md)
- [`artifacts/RELEASE_NOTES.md`](artifacts/RELEASE_NOTES.md)

Archived historical build notes:

- `artifacts/archive/`

Historical/reference inputs:

- `artifacts/walkthrough/`
- [`artifacts/founder_roadmap.md`](artifacts/founder_roadmap.md)
- [`artifacts/archive/README.md`](artifacts/archive/README.md)

Local enforcement helpers:

- `composer docs:check` or `bash scripts/check-canonical-docs.sh --staged` — verify required canonical docs and release notes were updated alongside core code changes
- `composer hooks:install` — install local `pre-commit` and `pre-push` hooks from `.githooks/`

If those files conflict with the codebase, the source of truth is:

1. current code
2. canonical docs in `artifacts/MEMORY.md`, `artifacts/DESIGN_SYSTEM.md`, and `artifacts/ARCHITECTURE.md`
3. `artifacts/RELEASE_NOTES.md`

## What The App Proves

1. A visitor lands on the marketing page and signs up for a trial.
2. Signup creates the user, tenant, business-profile records, and provisioning job.
3. The app assigns the tenant to a server.
4. A queue worker provisions the tenant runtime asynchronously.
5. The tenant gets a customer-facing workspace URL and a private gateway runtime.
6. The customer completes onboarding, can optionally connect Google Workspace through Sync360's OAuth flow, and then sees Google move through connected/synced/verified runtime states instead of a single optimistic "ready" label.
7. The app re-seeds runtime Google auth from DB when needed and tracks conversations, trial state, health status, and admin operations from the control plane.
8. Delete-and-recreate provisioning remains safe because runtime cleanup removes stale fixed-name tenant containers before reprovisioning.

## Stack

- Laravel 13
- PostgreSQL
- Redis
- Blade
- Docker Compose
- OpenClaw tenant runtimes
- LiteLLM for tenant and platform LLM access
- SSH-based remote Docker orchestration for client VPS deployments

## Canonical Product Shape

### Control Plane

The control plane runs as one Laravel app with:

- web routes and Blade pages
- self-serve account recovery via Laravel's password broker
- Brevo-backed password reset delivery when Brevo is enabled
- Redis-backed queue workers
- scheduled jobs
- provisioning and runtime orchestration services
- admin/debug tooling

### Tenant Runtime

Each tenant gets:

- its own runtime directory
- its own OpenClaw container
- its own LiteLLM virtual key
- optional DB-backed Google Workspace auth that is materialized into runtime `gog` files and can be smoke-tested from inside the tenant runtime
- tenant `gog` auth files are generated in the file-keyring format expected by the live CLI; the encrypted keyring token file is not plain JSON, uses RFC3394-compatible AES key wrap, and should not be patched manually
- its own customer-facing workspace URL

Catalog-managed workspace skills are instructions plus runtime files, not background jobs by themselves. Inbox Triage now has an explicit Sync360-owned trigger layer: `sync360:poll-inbox-triage` polls eligible tenants' Gmail through the tenant container's configured `gog`, de-dupes and suppresses obvious mechanical noise, and sends only business-plausible Gmail events to the assigned skill through the private gateway. Sync360 does not classify high-value leads or send Telegram directly; the `inbox-triage` skill decides category, lead quality, notifications, Drive logging, and analytics.

The public tenant hostname is the Sync360 login/dashboard entrypoint. The OpenClaw gateway stays private and is reached by the control plane through loopback plus the infrastructure runner.

## Local Setup

1. Copy the environment file:

```bash
cp .env.example .env
```

2. Start the local stack:

```bash
docker compose up --build
```

3. Open `http://localhost:8000`

The startup scripts install dependencies if needed, wait for Postgres and Redis, run migrations, seed the default admin and server records, and start the app or worker process.

Local tenant note:

- if you override the local Docker Compose env, preserve `SYNC360_INFRASTRUCTURE_DRIVER=local`, `SYNC360_LOCAL_DOCKER_COMPOSE_BIN=docker-compose`, and `SYNC360_HOST_PORT_PROBE_HOST=host.docker.internal` so the app container can control and health-check tenant runtimes on the local Docker host
- authenticated dashboard responses are sent with no-cache headers so local workspace status cards do not stick on stale browser renders

## Production Shape

The repository includes a production package for the control plane:

- `docker-compose.prod.yml`
- `Dockerfile`
- `docker/start-prod-app.sh`
- `docker/start-prod-worker.sh`
- `docker/start-prod-scheduler.sh`
- `deploy/apache/app.sync360.co.nz.conf`

Intended topology:

- the control plane runs on the primary server
- Apache terminates TLS for `app.sync360.co.nz`
- Apache reverse-proxies to the app container on `127.0.0.1:8000`
- tenant runtimes run on one or more client VPS hosts
- wildcard tenant hostnames point at the client runtime plane

## Operator Quickstart

### Boot the control plane in production

```bash
cp .env.production.example .env
docker compose -f docker-compose.prod.yml run --rm app php artisan key:generate --show
docker compose -f docker-compose.prod.yml up --build -d
```

### Prepare the first client VPS

From the running control-plane app container:

```bash
docker compose -f docker-compose.prod.yml exec app php artisan sync360:bootstrap-client-vps
```

This command now also installs pinned host-managed runtime capabilities declared in `config/sync360.php` on the client VPS. In the current repo, that includes the `gog` binary used by Google Workspace tooling.

For `gog`, the control plane now injects:

- `GOG_ENABLE_COMMANDS` as the allowlisted top-level direct CLI surface for the tenant runtime
- `GOG_ACCOUNT` for connected tenants so the tenant runtime defaults to the tenant’s connected Google email
- a compatibility `credentials.json` payload that includes top-level OAuth `client_id` / `client_secret` as well as the nested `installed` block, matching the real `gog` CLI expectations

### Scheduler requirement

Scheduled commands are part of the live system. Production must run the `scheduler` service from `docker-compose.prod.yml` so these jobs execute automatically:

- `tenants:health-check`
- `sync360:check-trial-expiry`
- `sync360:sync-replies`
- `sync360:sync-skill-conversions`
- `sync360:poll-inbox-triage`

### Resync existing live tenants after prompt changes

If a deploy changes generated workspace instructions for already-live tenants, resync those tenants explicitly instead of reprovisioning them:

```bash
php artisan sync360:resync-live-tenants
php artisan sync360:resync-live-tenants <tenant-id-or-slug>
```

This path regenerates and pushes workspace files only. It does not full-sync the tenant runtime.

### Repair host-managed runtime capabilities on existing tenants

If a deploy adds or fixes a host-managed tenant runtime dependency, use the runtime capability repair command instead of reprovisioning the tenant:

```bash
php artisan sync360:sync-runtime-capabilities
php artisan sync360:sync-runtime-capabilities <tenant-id-or-slug>
php artisan sync360:sync-runtime-capabilities <tenant-id-or-slug> gog
```

What it does:

- SSH mode only
- verifies or installs the pinned host binary on the client VPS
- for connected Google Workspace tenants, re-seeds the tenant `.openclaw/gogcli/` auth artifacts before verification so repaired runtimes do not keep stale keyring/token files
- regenerates full staged tenant `compose.yaml` and `config/openclaw.json`
- uploads changed files only
- force-recreates the tenant when compose changed
- refreshes workspace prompt files for live tenants so `TOOLS.md` / `PROFILE.md` / `HEARTBEAT.md` stay aligned with the runtime contract
- reruns capability verification and Google smoke tests where applicable
- after a successful Google verification, clears the known stale Gmail/account failure memory files from `.openclaw/workspace/memory/` so old reconnect/account-selection narratives do not keep steering the assistant

`goLive()` still remains workspace-files-only. It must not be used to deliver host binaries or perform a full runtime resync.

### Admin-panel runtime operations

The local-only super-admin tenant detail page now exposes tenant-scoped runtime operator actions for SSH-managed tenants:

- `Bootstrap VPS` — runs the same host bootstrap flow as `php artisan sync360:bootstrap-client-vps <server>`
- `Sync Runtime Capabilities` — runs the same tenant repair flow as `php artisan sync360:sync-runtime-capabilities <tenant>`
- `Test Google Workspace` — runs the same smoke test as `php artisan sync360:test-google-workspace <tenant>`

The tenant detail screen is now organized as same-route tabbed subscreens on `GET /admin/tenants/{tenant}?tab=overview|workspace|google|agent-runtime|support`, so admin actions preserve the relevant context panel instead of bouncing back to a long single scroll. The `Agent Runtime` tab also previews the tenant's current effective prompt files before override edits and shows a setup-needed state when the tenant customization tables have not been migrated locally yet.

These admin actions call the existing artisan command paths. They do not change the `goLive()` invariant.

The Google Workspace smoke path now verifies both runtime auth health and the native direct `gog` CLI surface that tenant assistants use:

- host binary visible and healthy
- mounted binary visible inside the running tenant container
- `GOG_ENABLE_COMMANDS` / `GOG_ACCOUNT` runtime env
- direct Gmail / Calendar / Drive / Contacts CLI probes
- allowlisted help probes for the broader `gog` service surface
- existing refresh-token, Gmail API, and Calendar API smoke checks

The smoke preflight now intentionally treats the encrypted `gog` keyring file as an opaque runtime artifact. It validates the token cache plus client credentials before running the real native `gog` CLI probes instead of trying to JSON-parse the keyring file itself.

When a remote Google smoke run fails over SSH, Sync360 now strips the benign SSH known-host warning line from failure output before surfacing the result. Operators and onboarding screens should see the actual runtime/CLI failure instead of `Warning: Permanently added ... to the list of known hosts.`

### Upgrade a pinned runtime capability version

For `gog` and future host-managed capabilities:

1. update `version`, `download_url`, and `sha256` in `config/sync360.php`
2. deploy the control plane
3. run `php artisan sync360:bootstrap-client-vps <server>` on each SSH-managed client VPS
4. run `php artisan sync360:sync-runtime-capabilities <tenant-or-scope> <capability>` for affected tenants
5. run `php artisan sync360:test-google-workspace <tenant>` for affected Google-connected tenants

## Current Architecture Modes

### `local`

Used for local development and iterative testing.

- Laravel app, worker, Postgres, and Redis run in Docker Compose
- tenant runtimes are staged locally
- tenant containers run on the local Docker host
- the shipped local stack pins `SYNC360_INFRASTRUCTURE_DRIVER=local`
- app and worker use `docker-compose` inside the container and reach tenant host ports through `host.docker.internal`

### `ssh`

Used for the primary-server plus client-VPS deployment shape.

- the control plane runs on the primary server
- runtime files are staged locally on the control plane
- files are copied to the assigned client VPS over SSH
- Docker Compose commands run remotely on that VPS
- readiness checks use private loopback HTTP on the client VPS
- host-managed runtime capability commands are supported only in this mode

## Core Paths

- `app/` — controllers, jobs, models, services, middleware, contracts, enums
- `config/` — provisioning, deploy, LiteLLM, queue, and mail configuration
- `database/migrations/` — schema for users, tenants, servers, provisioning, onboarding, conversations, and trials
- `resources/views/` — guest, auth, onboarding, dashboard, conversations, profile, tenant, and admin pages
- `routes/web.php` — web routes
- `routes/console.php` — scheduled commands and bootstrap helpers
- `runtime/tenants/` — local tenant runtime staging
- `templates/tenant/` — base tenant runtime template
- `deploy/` — control-plane deploy scripts and Apache config

## Tests

Run the app test suite with:

```bash
php artisan test
```

The repo includes feature and unit coverage for provisioning, admin flows, onboarding, health checks, gateway/runtime services, and conversation flows.

## Local Git Hooks

Install the repo-local hooks once:

```bash
composer hooks:install
```

After that:

- `pre-commit` checks staged changes
- `pre-push` checks changes against your upstream branch when one exists

The hooks fail if code changes land under `app/`, `routes/`, `config/`, or `resources/views/` without all of the following:

- updates to `artifacts/MEMORY.md`
- updates to `artifacts/ARCHITECTURE.md`
- a descriptive added entry in `artifacts/RELEASE_NOTES.md`

Frontend presentation changes under `resources/views/` or `resources/css/` also require an update to `artifacts/DESIGN_SYSTEM.md` so UI truth stays aligned with the shared Blade design contract.

`README.md` remains optional and should be updated when repo entrypoint, setup, or operator guidance changed.

## Scope Notes

This repo does not treat the following as complete product areas yet:

- billing and subscription automation
- email verification
- full secret-management hardening
- automated DNS management
- OpenClaw native Gmail watcher/PubSub for Inbox Triage; current proactive monitoring uses Sync360 scheduler-backed polling
- multi-region scheduling and placement
- comprehensive production observability

Use `artifacts/RELEASE_NOTES.md` for historical changes, `artifacts/ARCHITECTURE.md` for the current technical shape, and `artifacts/DESIGN_SYSTEM.md` for current UI/design-system truth.
