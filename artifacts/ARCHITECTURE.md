# Sync360 Control App Architecture

This document describes the current as-built architecture of the Sync360 Control App based on the repo code.

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

The repo supports a Docker Compose development stack and a production control-plane stack in `docker-compose.prod.yml`.

### Infrastructure modes

#### `local`

- app, worker, Postgres, and Redis run locally in Docker Compose
- tenant runtimes are staged under `runtime/tenants/<slug>/`
- tenant containers run on the local Docker host

#### `ssh`

- the control plane stages tenant runtimes locally
- runtime files are copied to a client VPS over SSH
- Docker Compose commands are executed remotely on that VPS
- readiness checks use private loopback HTTP on the client VPS

### Public and private surfaces

- `workspace_url` is the customer-facing Sync360 URL
- the tenant hostname proxies to the control app upstream
- the OpenClaw gateway binds privately and is reached by the control plane through `TenantGatewayService`
- private gateway requests use `DockerComposeRunner::httpRequest()`

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

### `BusinessProfile`

Stores structured customer business context gathered during onboarding.

Key concerns:

- business identity and contact info
- website extraction results
- services, FAQs, target customers, pricing notes
- last sync timestamp into the tenant agent

### `ConversationLog`

Stores customer-visible conversation history.

Key concerns:

- external message identifier
- inbound/outbound message text
- `session_id` grouping
- `ai_summary` per session
- response timestamp and meta payload

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
3. the user receives the standard reset notification through the configured mail driver
4. `/reset-password/{token}` accepts the token, email, and new password
5. the password is updated, the remember token is rotated, and the user is redirected back to login

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
14. send workspace-ready email

### Onboarding flow

Authenticated onboarding is handled by `OnboardingController`.

Implemented steps:

1. website extraction
2. business info confirmation
3. personality/tone
4. capabilities
5. channel configuration
6. go live

Services involved:

- `BusinessExtractionService`
- `TenantAgentSyncService`

`TenantProfileSyncService` is used for later profile/admin regeneration and resync work, not the main onboarding controller flow.

### Go-live sync flow

`TenantAgentSyncService::goLive()`:

1. validates tenant, profile, and generated files
2. writes `IDENTITY.md`, `SOUL.md`, `USER.md`, `BOOTSTRAP.md`, `PROFILE.md`, and `HEARTBEAT.md` into `.openclaw/workspace/`
3. syncs only workspace markdown files with `syncWorkspaceFiles()`
4. restarts the tenant runtime if needed
5. updates onboarding and sync timestamps

Critical invariant:

- `goLive()` must never use `syncRuntime()` because that can overwrite provisioned credentials such as the tenant LiteLLM key in `compose.yaml`

### Conversation sync flow

Conversation history is session-oriented.

`sync360:sync-replies`:

1. selects live tenants with runtime and server assignment
2. reads workspace memory/session files through `WorkspaceSessionLogReader`
3. upserts `ConversationLog` rows
4. groups rows by `session_id`
5. generates one short AI summary per session through `ConversationSummaryService`
6. stores the summary back on that session’s rows

### Trial lifecycle flow

`sync360:check-trial-expiry`:

1. selects active trial tenants with LiteLLM keys
2. refreshes spend data from LiteLLM
3. evaluates time and budget expiry
4. suspends expired tenants through `LiteLlmTenantKeyService`
5. sends warning and expired emails through `TrialNotificationEmailService`

## 5. Runtime generation and file layout

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

### Core generated files

- `.env` — tenant env values such as slug, assigned port, workspace URL, and provisioning extras
- `metadata.json` — tenant, server, job, and runtime metadata snapshot
- `config/openclaw.json` — gateway, agent, and model/provider config
- `compose.yaml` — tenant container definition
- `config/workspace.caddy` — tenant hostname reverse-proxy config when Caddy is managed

### Remote layout

For SSH mode, `TenantRuntimeService::remoteRuntimePath()` resolves the remote runtime path from the assigned server’s `runtime_root`.

The expected remote layout is:

- `<runtime_root>/tenants/<slug>/`

### Workspace sync boundary

There are two separate sync shapes in the runner contract:

- `syncRuntime()` — full runtime sync used for initial provisioning
- `syncWorkspaceFiles()` — markdown-only sync used by go-live/profile sync to avoid overwriting credentials

## 6. External integrations

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

## 7. Routes and scheduled jobs

### Web routes

Defined in `routes/web.php`.

Public routes:

- `/`
- `/signup`
- `/login`

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
- `POST /admin/tenants/{tenant}/health-check`
- `POST /admin/tenants/{tenant}/resync-agent`
- `POST /admin/tenants/{tenant}/workspace/start`
- `POST /admin/tenants/{tenant}/workspace/stop`
- `POST /admin/tenants/{tenant}/workspace/restart`

### Scheduled commands

Defined in `routes/console.php`.

Implemented recurring commands:

- `tenants:health-check` — every five minutes
- `sync360:check-trial-expiry` — every thirty minutes
- `sync360:sync-replies` — every ten minutes

Also present:

- `sync360:bootstrap-client-vps` — bootstrap helper for a client VPS

## 8. Operational controls

### Tenant health and status

`TenantHealthCheckService`:

- checks server/runtime/workspace prerequisites
- verifies the container is running
- calls private `/readyz` through `TenantGatewayService`
- persists health status and failure messaging on the tenant

### Admin workspace controls

Admin actions expose start/stop/restart and health/resync operations for tenant runtimes.

### Retry model

Provisioning retry uses a new `ProvisioningJob` record instead of mutating historical job records.

### Local-only admin constraint

The admin surface is intentionally restricted by:

- authenticated user
- admin flag
- `local.only` middleware

### Control-plane deploy control

The admin UI can trigger a host-side deploy script through `ControlAppDeploymentService` and read back status, commit info, and recent logs.

## 9. Configuration surface

Primary architecture settings live in `config/sync360.php`.

Main configuration groups:

- `admin_local_only`
- runtime and template roots
- infrastructure driver and SSH tooling
- tenant port range
- provisioning driver and delay
- OpenClaw image, service, readiness, and model settings
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

## 10. Constraints and known gaps

- Billing is not implemented.
- Secret-management and SSH-key hardening are not complete.
- DNS automation is external to this repo.
- Historical release notes and plans still contain webhook-first Telegram narratives and more complete WhatsApp claims than the current implementation.
- Production assumptions should be verified against the deployed environment rather than inferred from repo docs alone.

## 11. Code map for contributors

Start with these files:

- `routes/web.php`
- `routes/console.php`
- `config/sync360.php`
- `app/Http/Controllers/Auth/RegisterController.php`
- `app/Http/Controllers/OnboardingController.php`
- `app/Http/Controllers/AdminController.php`
- `app/Jobs/ProcessTenantProvisioning.php`
- `app/Services/OpenClawProvisioner.php`
- `app/Services/TenantRuntimeService.php`
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
- `BusinessProfile`
- `ConversationLog`
