# Sync360 Control App Architecture

This document describes the current as-built architecture of the Sync360 Control App.

It focuses on the implemented system, including the new VPS-ready provisioning model.

## 1. Purpose

Sync360 Control App is a Laravel-based control plane for a future multi-tenant AI service platform.

It currently validates this end-to-end flow:

1. A visitor lands on the marketing site.
2. The visitor signs up for a free trial.
3. The app creates a user, tenant, and provisioning job.
4. The app assigns the tenant to a client server.
5. A queue worker provisions the tenant asynchronously.
6. The tenant becomes `ready`.
7. The user sees a workspace-ready screen with a generated workspace URL.
8. The app can send a workspace-created email with login details after successful provisioning.
9. The app provisions a dedicated LiteLLM virtual key for each OpenClaw tenant before container startup.
10. A super admin can inspect tenants, jobs, workspace state, and trigger safe control-plane deployments.
11. A super admin can open a tenant detail page and permanently delete a tenant only after infrastructure cleanup succeeds.
12. Tenant workspace URLs now open the Sync360 login/dashboard experience while the OpenClaw gateway remains private.

## 2. Deployment Shapes

The codebase now supports two infrastructure shapes.

### 2.1 Local Development Shape

Used for local development and iterative design work.

Services:

- `app`
- `worker`
- `postgres`
- `redis`

In this mode:

- the Laravel app and worker run locally in Docker Compose
- the worker can talk to the local Docker host
- tenant OpenClaw containers run on the same machine

### 2.2 VPS-Ready Shape

Used for the intended primary-server plus client-VPS deployment model.

In this mode:

- Laravel app and queue worker run on the primary control server
- each tenant is assigned to a `Server` record
- tenant runtime files are generated on the control server
- runtime files are copied to the assigned client VPS over SSH
- Docker Compose is executed remotely on that client VPS over SSH
- readiness is probed remotely against the client VPS loopback interface
- workspace URLs are generated from the assigned server configuration

### 2.3 Current Deployment Architecture

The deployment architecture now has a validated first-production shape:

- control plane URL: `app.sync360.co.nz`
- planned control plane host: `161.97.74.128`
- first client runtime VPS: `89.116.28.191`
- tenant hostname pattern: `https://<tenant-slug>.workspace.sync360.co.nz`
- wildcard DNS: `*.workspace.sync360.co.nz -> 89.116.28.191`

The flow currently proven in production-like form is:

- the Laravel control app runs locally
- a tenant is assigned to the seeded `sync360-client-vps-1` server record
- provisioning uses password-auth SSH to connect as `deploy@89.116.28.191`
- runtime files are staged locally, copied to the client VPS, and started there with remote Docker Compose
- Caddy on the client VPS serves the tenant hostname over HTTPS
- the worker marks the tenant `ready` only after both remote loopback readiness and public hostname readiness succeed

This same architecture is intended to be reused after the control app is moved from local development to `161.97.74.128`.

### 2.4 Production Control-Plane Packaging

The repository now also includes a production deployment package for the control app itself.

That package consists of:

- [docker-compose.prod.yml](/Users/gayanhewage/Projects/openclaw-saas/docker-compose.prod.yml)
- a production image target in [Dockerfile](/Users/gayanhewage/Projects/openclaw-saas/Dockerfile)
- [docker/start-prod-app.sh](/Users/gayanhewage/Projects/openclaw-saas/docker/start-prod-app.sh)
- [docker/start-prod-worker.sh](/Users/gayanhewage/Projects/openclaw-saas/docker/start-prod-worker.sh)
- [docker/start-prod-scheduler.sh](/Users/gayanhewage/Projects/openclaw-saas/docker/start-prod-scheduler.sh)
- [deploy/apache/app.sync360.co.nz.conf](/Users/gayanhewage/Projects/openclaw-saas/deploy/apache/app.sync360.co.nz.conf)

The intended deployment shape is:

- `161.97.74.128` runs the Dockerized control plane with four services: `app`, `worker`, `scheduler`, `postgres`, `redis`
- Apache on the host terminates TLS for `app.sync360.co.nz`
- Apache reverse proxies to the app container on `127.0.0.1:8000`
- the `scheduler` container runs `php artisan schedule:work` (foreground scheduler, no cron needed)
- the worker still provisions client workspaces remotely over SSH using the current temporary password-auth model
- the super-admin UI can trigger a safe host-side deploy script over SSH to update the control plane itself

## 3. High-Level Architecture

```mermaid
flowchart LR
    U["Visitor / User"] --> WEB["Laravel Web App"]
    WEB --> DB["PostgreSQL"]
    WEB --> REDIS["Redis"]
    WEB --> JOB["Provisioning Job Record + Dispatch"]
    JOB --> WORKER["Queue Worker"]
    WORKER --> DB
    WORKER --> STAGE["Local Runtime Staging<br/>runtime/tenants/<slug>"]
    WORKER --> SSH["SSH / SCP"]
    SSH --> VPS["Assigned Client VPS"]
    VPS --> OC["Per-tenant OpenClaw Container"]
    WORKER --> READY["Remote Readiness Check<br/>127.0.0.1:<port>/readyz"]
    READY --> OC
    WEB --> ADMIN["Admin / Debug UI"]
```

## 4. Core Repository Areas

- `app/`
  - controllers, jobs, models, services, contracts, middleware, enums
- `config/`
  - application and provisioning configuration
- `database/migrations/`
  - schema changes for users, tenants, jobs, servers, and admin support
- `resources/views/`
  - Blade templates for public, auth, tenant, workspace, and admin pages
- `routes/web.php`
  - full route map
- `runtime/tenants/`
  - local runtime staging output
- `templates/tenant/`
  - template copied into tenant runtime staging directories
- `docker-compose.yml`
  - local development stack
- `docker-compose.prod.yml`
  - production control-plane stack
- `deploy/apache/`
  - Apache reverse proxy template for `app.sync360.co.nz`

## 5. Application Layers

### 5.1 Web Layer

Handled by Laravel controllers and Blade views.

Responsibilities:

- landing page
- signup and login
- customer dashboard
- tenant setup and status polling
- workspace-ready state
- placeholder workspace route
- super admin debug area
- super-admin tenant list and tenant detail views
- slug-confirmed permanent tenant deletion
- super-admin control-plane deploy trigger
- super-admin live deploy status view with latest commit metadata and recent log tail

### 5.2 Queue Layer

Handled by Laravel queues backed by Redis.

Responsibilities:

- async tenant provisioning
- status transitions
- failure handling

### 5.3 Provisioning Layer

Handled through the `TenantProvisioner` contract.

Implementations:

- `App\Services\OpenClawProvisioner`
- `App\Services\LocalTenantProvisioningService`
- `App\Services\LiteLlmTenantKeyService`

### 5.4 Infrastructure Layer

Handled through the `DockerComposeRunner` contract.

Implementations:

- `App\Services\LocalDockerComposeRunner`
- `App\Services\SshDockerComposeRunner`

This layer now owns:

- runtime sync for remote servers
- Docker Compose start/stop/up/down
- runtime directory removal
- remote port probing
- readiness polling

The repository also now includes a separate control-plane deployment path:

- `App\Services\ControlAppDeploymentService`
- host-side script [deploy/scripts/run-control-app-deploy.sh](/Users/gayanhewage/Projects/openclaw-saas/deploy/scripts/run-control-app-deploy.sh)

That path is intentionally separate from tenant provisioning:

- it targets the primary control server, not a client VPS
- it triggers a detached host-side deploy script over SSH
- it reads back a deploy status file, latest deployed git commit metadata, and recent log tail for the super-admin UI
- it exposes a lightweight authenticated status endpoint that the admin page polls every few seconds for realtime updates
- it hardens deploy status reads so admin pages keep working even when deploy secrets are missing or partially configured
- it treats the host-side deploy status file as the authoritative record of the commit that was actually fetched and deployed, rather than inferring that commit later from a fresh git lookup
- it is intentionally separated from the tenant provisioning runner contract so the control plane does not need direct self-Docker control inside Laravel

## 6. Route Architecture

Defined in [routes/web.php](/Users/gayanhewage/Projects/openclaw-saas/routes/web.php).

### Public Routes

- `/`
- `/signup`
- `/login`

When the request host matches a tenant workspace hostname such as
`https://<tenant-slug>.<workspace_base_domain>`:

- guests hitting `/` are redirected to `/login`
- matching signed-in customers hitting `/` are redirected to `/dashboard`
- mismatched signed-in customers are blocked from using another tenant's host

### Authenticated Customer Routes

- `POST /logout`
- `/dashboard`
- `/tenant/setup`
- `/tenant/status`
- `/tenant/workspace-ready`
- `/workspace/{tenant:slug}`

### Admin Routes

- `/admin`
- `/admin/users`
- `/admin/tenants`
- `GET /admin/tenants/{tenant}`
- `DELETE /admin/tenants/{tenant}`
- `/admin/jobs`
- `GET /admin/deploy/control-app/status`
- `POST /admin/deploy/control-app`
- `POST /admin/jobs/{tenant}/retry`
- `POST /admin/tenants/{tenant}/workspace/start`
- `POST /admin/tenants/{tenant}/workspace/stop`
- `POST /admin/tenants/{tenant}/workspace/restart`

Admin routes are protected by:

- `auth`
- `admin`
- optional `local.only`, controlled by config

Authenticated customer routes that expose tenant data are additionally protected by workspace-host matching when the request is made through a tenant workspace hostname.

## 8. Admin Tenant Operations

The admin tenant area now has two layers:

- a compact list view at `/admin/tenants`
- a detailed tenant view at `/admin/tenants/{tenant}`

The compact list is intended for quick scanning and keeps only the highest-signal fields:

- business name and slug
- customer name and email
- assigned client VPS
- provisioning status
- agent status
- health / workspace summary

The detail page is the operational workspace for one tenant and includes:

- customer account metadata
- runtime and server metadata
- onboarding and channel summary
- latest provisioning job status and error
- support actions for retry, health check, agent resync, start, stop, and restart
- a danger zone for permanent deletion

Permanent deletion is intentionally strict:

- infrastructure teardown must succeed first
- LiteLLM key deletion must succeed
- only then is the linked non-admin customer account deleted, allowing the tenant and tenant-owned records to cascade
- if any cleanup step fails, the control-app record is preserved so the admin can retry safely

In local development, permanent deletion bypasses SSH and performs runtime shutdown locally with `docker compose` before deleting local runtime files.

## 7. Authentication And Admin Model

Authentication is intentionally small and session-based.

Implemented behavior:

- register
- login
- logout
- session persistence
- guest-only auth pages
- admin-only access to operational routes
- trusted reverse-proxy support through the `TRUSTED_PROXIES` environment variable

Admin control:

- `users.is_admin` gates super-admin access
- super admins without a tenant are redirected to `/admin`
- non-admins receive `403`

## 8. Data Model

### 8.1 Users

Purpose:

- authentication identity
- owner of a tenant
- optional super admin

Important fields:

- `id`
- `name`
- `email`
- `password`
- `phone`
- `is_admin`

### 8.2 Tenants

Purpose:

- represent a provisioned customer workspace

Important fields:

- `id`
- `tenant_id`
- `slug`
- `business_name`
- `industry`
- `skill_pack`
- `user_id`
- `server_id`
- `trial_status`
- `provisioning_status`
- `assigned_port`
- `workspace_url`
- `runtime_path`
- `litellm_virtual_key`
- `litellm_key_alias`
- `litellm_plan_name`
- `litellm_max_budget`
- `litellm_budget_duration`
- `litellm_last_synced_at`

### 8.3 ProvisioningJobs

Purpose:

- record each provisioning attempt and its result
- temporarily carry encrypted workspace-ready email credentials until a successful email send

Important fields:

- `id`
- `tenant_id`
- `job_type`
- `status`
- `payload_json`
- `error_message`
- `started_at`
- `completed_at`

`payload_json` is also used for transient provisioning metadata such as:

- signup context
- retry context
- encrypted initial password for the workspace-created email
- workspace-ready email sent timestamp

### 8.4 Servers

Purpose:

- describe where tenant workspaces are deployed

Important fields:

- `id`
- `name`
- `host`
- `ssh_host`
- `ssh_port`
- `ssh_user`
- `ssh_private_key_path`
- `ssh_auth_mode`
- `ssh_password_env_key`
- `sudo_password_env_key`
- `status`
- `max_clients`
- `current_clients`
- `runtime_root`
- `workspace_scheme`
- `workspace_base_domain`
- `docker_compose_bin`
- `caddy_sites_path`
- `caddy_reload_command`

The seeded super admin is now environment-driven:

- `SYNC360_SUPER_ADMIN_NAME`
- `SYNC360_SUPER_ADMIN_EMAIL`
- `SYNC360_SUPER_ADMIN_PASSWORD`
- `SYNC360_RESET_SUPER_ADMIN_PASSWORD`

Behavior:

- first seed creates the super admin
- later seeds preserve the existing password by default
- password reset only happens when `SYNC360_RESET_SUPER_ADMIN_PASSWORD=true`

### 8.5 Relationships

- `User` has one `Tenant`
- `Tenant` belongs to `User`
- `Tenant` belongs to `Server`
- `Tenant` has many `ProvisioningJob`
- `ProvisioningJob` belongs to `Tenant`
- `Server` has many `Tenant`

## 9. Status Enums

### Tenant Provisioning Status

- `pending`
- `provisioning`
- `ready`
- `failed`

### Trial Status

- `trial_active`
- `trial_expired`

### Provisioning Job Status

- `queued`
- `running`
- `completed`
- `failed`

## 10. Signup And Tenant Creation Flow

Signup is handled in [app/Http/Controllers/Auth/RegisterController.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/Auth/RegisterController.php).

Form fields:

- `business_name`
- `contact_name`
- `email`
- `password`
- `password_confirmation`
- `industry`
- `skill_pack`
- optional `phone`

On successful signup:

1. Validate the request.
2. Select an active server using `ServerPlacementService`.
3. Create the `User`.
4. Create the `Tenant` with `server_id`.
5. Create a queued `ProvisioningJob`.
6. Store workspace-ready email metadata in the job payload, including the login email and encrypted initial password.
7. Increment the selected server’s `current_clients`.
8. Log the user in.
9. Dispatch `ProcessTenantProvisioning`.
10. Redirect to `/tenant/setup`.

If no client VPS is available, signup fails cleanly with a validation-style error instead of a `500`.

## 11. Queue Flow

Entry job:

- [app/Jobs/ProcessTenantProvisioning.php](/Users/gayanhewage/Projects/openclaw-saas/app/Jobs/ProcessTenantProvisioning.php)

Responsibilities:

- load tenant and provisioning job
- resolve the active `TenantProvisioner`
- call `provision($tenant, $provisioningJob)`
- send the workspace-created email after successful provisioning when Brevo is enabled
- mark tenant and job failed if provisioning throws

The queue job does not mark a ready tenant as failed if the post-provisioning email send fails. Email failures are logged separately so provisioning success remains authoritative.

## 12. Provisioning Architecture

### 12.1 Contract

- [app/Contracts/TenantProvisioner.php](/Users/gayanhewage/Projects/openclaw-saas/app/Contracts/TenantProvisioner.php)

### 12.2 Runtime Preparation

Shared runtime work is handled by:

- [app/Services/TenantRuntimeService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/TenantRuntimeService.php)

Responsibilities:

- allocate next free port for the assigned server
- generate server-aware workspace URL
- generate private tenant gateway base URL from `127.0.0.1:<assigned_port>`
- create local runtime staging path
- copy template files
- create required directories
- write tenant `.env`
- write `metadata.json`
- compute remote runtime path from the assigned server
- compute the control-app upstream used in generated tenant Caddy configs

### 12.3 Local Fake Provisioner

- [app/Services/LocalTenantProvisioningService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/LocalTenantProvisioningService.php)

Purpose:

- lightweight fake provisioning
- used in tests or local simplified scenarios

### 12.4 OpenClaw Provisioner

- [app/Services/OpenClawProvisioner.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/OpenClawProvisioner.php)

Current provisioning sequence:

1. Mark tenant `provisioning`.
2. Mark provisioning job `running`.
3. Allocate a free port for the assigned server.
4. Generate workspace URL.
5. Generate or reuse the tenant's dedicated LiteLLM virtual key.
6. Persist the encrypted LiteLLM key plus plan and budget metadata on the tenant record.
7. Generate local runtime staging files, including `OPENAI_API_KEY` and `OPENAI_BASE_URL`.
8. Compute remote runtime path for the assigned server.
9. Write `config/openclaw.json` with `agents.defaults.model`, `models.providers.openai` pointing to LiteLLM, and gateway auth config.
10. Write `compose.yaml` using the remote runtime bind path and tenant-scoped LiteLLM environment.
11. Write a tenant-specific `workspace.caddy` site fragment when wildcard-domain routing is configured.
12. Persist `assigned_port`, `runtime_path`, and `workspace_url`.
13. Stop any stale remote tenant runtime and remove any stale tenant Caddy file.
14. Sync local runtime staging to the assigned server over SSH when using SSH infrastructure.
15. Install the tenant Caddy site file on the client VPS and reload Caddy.
16. Start the OpenClaw container on the target host.
17. Poll the loopback readiness endpoint on the client VPS.
18. Poll the public HTTPS tenant login entrypoint.
19. Mark tenant `ready`.
20. Mark provisioning job `completed`.
21. Trigger the workspace-created email service after successful provisioning.

Current routing behavior inside provisioning:

- the OpenClaw container is still bound only to loopback on the client VPS
- generated tenant Caddy configs reverse proxy `https://<slug>.workspace...` to the Sync360 control app upstream
- generated `openclaw.json` disables the public control UI
- public provisioning verification checks `https://<slug>.workspace.../login`
- private readiness checks continue to use `http://127.0.0.1:<assigned_port>/readyz`

On failure:

- stale runtime is cleaned up as best effort
- stale tenant Caddy site files are removed as best effort
- tenant startup is aborted if LiteLLM key generation fails
- tenant becomes `failed`
- provisioning job becomes `failed`
- `error_message` is stored

Email-specific behavior:

- the workspace-created email is sent only after the tenant is already ready
- the initial password is stored encrypted in the provisioning job payload
- after a successful send, the encrypted password is removed from the payload and a sent timestamp is stored
- if the email provider call fails, the tenant remains `ready` and the failure is logged

### 12.5 Workspace-Created Email

- [app/Services/WorkspaceReadyEmailService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/WorkspaceReadyEmailService.php)

Purpose:

- send the customer-facing confirmation that the workspace has been created
- include the Sync360 workspace URL, username, and initial password
- keep initial credentials encrypted at rest until the email is sent successfully

Current provider:

- Brevo transactional email API via `POST /v3/smtp/email`

Configuration:

- `BREVO_ENABLED`
- `BREVO_API_KEY`
- `BREVO_BASE_URL`
- `BREVO_SENDER_EMAIL` or `MAIL_FROM_ADDRESS`
- `BREVO_SENDER_NAME` or `MAIL_FROM_NAME`

Operational behavior:

- email is skipped entirely if Brevo is not enabled or not configured
- the sender address should be a verified Brevo sender
- retry provisioning can carry forward the encrypted credentials so a tenant still receives the email after a retry succeeds

### 12.6 LiteLLM Virtual Key Provisioning

- [app/Services/LiteLlmTenantKeyService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/LiteLlmTenantKeyService.php)

Purpose:

- create one dedicated LiteLLM virtual key per tenant
- inject that key into the OpenClaw runtime before container startup
- prevent OpenClaw instances from starting without tenant-scoped API credentials
- support later budget updates, suspension, and deletion without sharing keys across tenants

Configuration:

- `LITELLM_BASE_URL`
- `LITELLM_MASTER_KEY`
- `LITELLM_DEFAULT_PLAN_NAME`
- `LITELLM_DEFAULT_MAX_BUDGET`
- `LITELLM_DEFAULT_BUDGET_DURATION`
- `LITELLM_TRIAL_MAX_BUDGET`
- `LITELLM_TEAM_ID`
- `LITELLM_DEFAULT_MODELS`

Key restrictions:

- all generated virtual keys are assigned to a LiteLLM team via `LITELLM_TEAM_ID`
- all generated virtual keys are restricted to the models listed in `LITELLM_DEFAULT_MODELS`
- the LiteLLM team must also allow the same models in its own settings, or requests are rejected with 401
- the default trial budget is set via `LITELLM_TRIAL_MAX_BUDGET` (currently $5/month)

Operational behavior:

- the service calls `POST /key/generate` on LiteLLM before OpenClaw runtime preparation
- the generated request includes `team_id` and `models` restrictions from centralized config
- the generated tenant key is stored on the tenant record with Laravel encrypted casting
- the generated runtime `.env` file receives `OPENAI_API_KEY` and `OPENAI_BASE_URL`
- the tenant `compose.yaml` also receives `OPENAI_API_KEY` and `OPENAI_BASE_URL` so the OpenClaw container uses the tenant key at runtime
- if LiteLLM key generation fails, provisioning aborts immediately and the tenant instance is not started
- plan changes use `POST /key/update`
- suspension uses `POST /key/update` with `max_budget=0` and `budget_duration=null`
- cancellation-ready cleanup uses `POST /key/delete`

### 12.7 OpenClaw Model & Provider Routing

OpenClaw has a built-in provider catalog that defaults to calling `api.openai.com` directly. Since tenant API keys are LiteLLM virtual keys (not real OpenAI keys), all AI calls must be routed through LiteLLM.

This is achieved by writing a `models` block in the generated `openclaw.json`:

```json
{
  "agents": {
    "defaults": {
      "model": "gpt-4o"
    }
  },
  "models": {
    "mode": "replace",
    "providers": {
      "openai": {
        "baseUrl": "https://litellm.stylesoftware.co.nz/v1",
        "models": [{"id": "gpt-4o", "name": "gpt-4o"}]
      }
    }
  }
}
```

Key points:

- `models.mode: "replace"` is critical — without it, OpenClaw merges with its built-in catalog and may still call `api.openai.com` directly
- `models.providers.openai.models` must use the `[{id, name}]` object format (string arrays cause validation errors)
- `agents.defaults.model` sets the default model for all agents (not `agent.model`, which is invalid)
- `OPENCLAW_MODEL` and `OPENAI_BASE_URL` env vars do **not** override the built-in provider catalog
- the model is configured via `OPENCLAW_DEFAULT_AGENT_MODEL` env var on the control app, which defaults to `gpt-4o`

Self-healing:

- `TenantAgentSyncService::configureChannel()` checks for and injects the `agents.defaults.model` and `models.providers` config if missing
- this ensures pre-existing tenants provisioned before this feature are automatically upgraded on the next channel update

## 12.8 Control-Plane Deploy Status Flow

The control-plane deploy feature now has a dedicated status loop for the super-admin interface.

Implemented behavior:

- the admin panel triggers a detached host-side deploy script over SSH
- the host-side script writes a status file and append-only deploy log on the control server
- that status file records the branch, timestamps, message, and the exact fetched-and-deployed commit SHA and subject after `git pull`
- the Laravel app reads those files over SSH through `ControlAppDeploymentService`
- the service prefers the status-file commit metadata and only falls back to a direct git lookup when the status file does not exist yet
- the service also compares the deployed commit with the current `origin/<branch>` tip on the primary server repository to determine whether production is already up to date
- the SSH trigger now bootstraps deployment by fetching the target branch first and executing the deploy script content directly from `FETCH_HEAD`, so deploy-script changes are applied immediately even when the checked-out script on disk is older
- before rebuilding containers, the host deploy script now resolves the target remote branch head, fast-forwards the checked-out branch to `FETCH_HEAD`, and aborts if the resulting local HEAD does not equal the intended remote commit
- the admin page polls `GET /admin/deploy/control-app/status` every few seconds
- the UI updates deploy state, started/finished timestamps, message, latest commit, up-to-date state, and recent log tail without a full page refresh

Shell hardening now includes:

- remote deploy commands are sent directly to SSH without an extra nested `sh -lc` wrapper
- deploy status probe statements are separated with semicolons for portable remote shell parsing
- deploy UI actions are derived from the deployed-vs-branch commit comparison: `Up-to-date` when hashes match, otherwise `Fetch Latest And Deploy`
- misconfigured deploy secrets or unreachable SSH targets surface as a readable failed/unreachable state instead of taking `/admin` down with a `500`

## 13. Infrastructure Runner Architecture

### 13.1 Contract

- [app/Contracts/DockerComposeRunner.php](/Users/gayanhewage/Projects/openclaw-saas/app/Contracts/DockerComposeRunner.php)

Responsibilities:

- sync runtime files to a target server
- run Docker Compose commands
- probe server port usage
- check readiness
- make private HTTP requests to tenant-local services

### 13.2 Local Runner

- [app/Services/LocalDockerComposeRunner.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/LocalDockerComposeRunner.php)

Used when:

- `SYNC360_INFRASTRUCTURE_DRIVER=local`

Behavior:

- no runtime sync step
- uses local Docker Compose
- checks readiness locally
- performs private tenant gateway HTTP requests directly through Laravel's HTTP client

### 13.3 SSH Runner

- [app/Services/SshDockerComposeRunner.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/SshDockerComposeRunner.php)

Used when:

- `SYNC360_INFRASTRUCTURE_DRIVER=ssh`

Behavior:

- creates remote runtime directory over SSH
- copies runtime staging to the assigned client VPS over SCP
- supports both key-based auth and temporary password-based auth via `sshpass`
- resolves SSH and sudo passwords from env-only secret keys referenced by the `Server` record
- uploads or removes remote files such as tenant Caddy site configs
- runs privileged remote commands through `sudo -S` when needed
- runs Docker Compose remotely over SSH
- probes ports remotely using `ss`
- checks readiness remotely using `curl`
- performs private tenant gateway HTTP requests over SSH by executing remote `curl` against tenant-local loopback URLs

## 14. Runtime Filesystem Model

### 14.1 Local Staging Path

Generated under:

```text
runtime/tenants/<tenant-slug>/
```

Contents:

```text
.env
metadata.json
compose.yaml
config/
  openclaw.json
data/
logs/
workspace/
```

### 14.2 Remote Runtime Path

Derived from the assigned server:

```text
<server.runtime_root>/tenants/<tenant-slug>/
```

The database `runtime_path` now stores the remote runtime path, not the local staging path.

### 14.3 Client VPS Filesystem Roles

For the validated client-VPS shape on `89.116.28.191`:

- tenant runtimes live under `/srv/sync360/runtime/tenants/<slug>/`
- tenant Caddy site files live under `/etc/caddy/sites/<slug>.caddy`
- Caddy imports `/etc/caddy/sites/*.caddy` from the main Caddyfile

This means the provisioning system owns both:

- the tenant runtime directory
- the reverse-proxy route definition for the tenant hostname

## 15. Workspace URL Model

Workspace URLs are generated from the assigned `Server` and are now strictly customer-facing Sync360 URLs.

Rules:

```text
if workspace_base_domain is present:
  <scheme>://<tenant-slug>.<workspace_base_domain>

otherwise:
  <scheme>://<server.host>:<assigned_port>
```

This supports:

- wildcard subdomain routing such as `tenant.workspace.sync360.co.nz`
- host-and-port routing during infrastructure testing

In the current validated deployment model:

- `workspace_scheme = https`
- `workspace_base_domain = workspace.sync360.co.nz`
- URLs therefore resolve to `https://<tenant-slug>.workspace.sync360.co.nz`

Behavioral contract:

- `workspace_url` is the customer login/dashboard URL, not the public OpenClaw gateway URL
- the tenant subdomain fronts the Sync360 control app for human access
- private control-plane-to-gateway traffic uses tenant-local loopback URLs derived from `assigned_port`

The internal placeholder route still exists:

```text
/workspace/{tenant:slug}
```

## 16. Admin And Operational Controls

Handled in:

- [app/Http/Controllers/AdminController.php](/Users/gayanhewage/Projects/openclaw-saas/app/Http/Controllers/AdminController.php)

Capabilities:

- admin overview
- users list
- tenants list
- jobs list
- retry failed provisioning
- start remote tenant workspace
- stop remote tenant workspace
- inspect workspace state
- inspect client VPS assignment
- inspect runtime paths and workspace URLs
- inspect provisioning errors

## 17. Configuration Surface

Primary configuration file:

- [config/sync360.php](/Users/gayanhewage/Projects/openclaw-saas/config/sync360.php)

Important groups:

- `admin_local_only`
- `infrastructure.driver`
- SSH timeout settings
- SSH/SCP/sshpass binary settings
- local runtime root
- template root
- port range
- provisioning driver
- OpenClaw image and compose config
- OpenClaw default agent model
- public workspace readiness polling

Default environment values live in:

- [.env.example](/Users/gayanhewage/Projects/openclaw-saas/.env.example)

Important deployment env keys now include:

- `SYNC360_INFRASTRUCTURE_DRIVER=ssh`
- `SYNC360_CLIENT_VPS_SSH_PASSWORD`
- `SYNC360_CLIENT_VPS_SUDO_PASSWORD`
- `SYNC360_DEFAULT_SERVER_SSH_AUTH_MODE=password`
- `SYNC360_DEFAULT_SERVER_SSH_PASSWORD_ENV_KEY`
- `SYNC360_DEFAULT_SERVER_SUDO_PASSWORD_ENV_KEY`
- `SYNC360_DEFAULT_SERVER_CADDY_SITES_PATH`
- `SYNC360_DEFAULT_SERVER_CADDY_RELOAD_COMMAND`

These secrets are intentionally env-only and are not stored in git or in the database.

## 18. Docker Compose Development Stack

Defined in [docker-compose.yml](/Users/gayanhewage/Projects/openclaw-saas/docker-compose.yml).

Local services:

- `app`
- `worker`
- `postgres`
- `redis`

The local stack still mounts the Docker socket so local infrastructure mode continues to work in development.

## 19. Client VPS Bootstrap Flow

The app now includes a one-time bootstrap command for preparing a fresh client VPS:

- command: `php artisan sync360:bootstrap-client-vps {serverSelector?}`

Current bootstrap responsibilities:

- install Caddy using the official apt repository when it is missing
- create `/srv/sync360/runtime` and `/srv/sync360/runtime/tenants`
- create `/etc/caddy/sites`
- ensure the `deploy` user can work with the runtime directories
- add `import /etc/caddy/sites/*.caddy` to the main Caddyfile if needed
- enable and reload Caddy
- verify remote `docker compose` availability

This command is part of the deployment architecture because provisioning assumes the target server already has:

- Docker installed
- Caddy installed and enabled
- the runtime root available
- tenant site import support enabled in Caddy

## 20. Seed Data

Seeding creates:

- the default super admin user
- the default server record based on environment variables

Default admin:

- email: `admin@sync360.local`
- password: `admin12345`

## 21. Request And Provisioning Sequence

```mermaid
sequenceDiagram
    participant USER as User
    participant WEB as Laravel Web App
    participant DB as PostgreSQL
    participant REDIS as Redis
    participant WORKER as Queue Worker
    participant SERVER as ServerPlacementService
    participant RT as TenantRuntimeService
    participant RUNNER as Infrastructure Runner
    participant VPS as Client VPS
    participant CADDY as Caddy
    participant OC as OpenClaw

    USER->>WEB: Submit signup form
    WEB->>SERVER: Select active client VPS
    WEB->>DB: Create user
    WEB->>DB: Create tenant with server_id
    WEB->>DB: Create provisioning job
    WEB->>DB: Increment server current_clients
    WEB->>REDIS: Dispatch provisioning job
    WEB-->>USER: Redirect to /tenant/setup

    WORKER->>DB: Load tenant and provisioning job
    WORKER->>RT: Allocate port and prepare local runtime staging
    RT-->>WORKER: Local staging path + remote runtime path + workspace URL
    WORKER->>RUNNER: Remove stale tenant runtime and stale Caddy site
    WORKER->>RUNNER: Sync runtime to assigned server
    WORKER->>RUNNER: Install tenant Caddy site
    WORKER->>RUNNER: Reload Caddy
    RUNNER->>CADDY: Publish https://<slug>.workspace.sync360.co.nz
    WORKER->>RUNNER: Remote docker compose up
    RUNNER->>VPS: Start tenant runtime
    VPS->>OC: Launch OpenClaw container
    WORKER->>RUNNER: Probe http://127.0.0.1:<port>/readyz remotely
    WORKER->>CADDY: Probe public HTTPS hostname
    WORKER->>DB: Mark tenant ready
    WORKER->>DB: Mark provisioning job completed
```

## 22. Deployment Topology

### 22.1 Control Plane

Intended production endpoint:

- `https://app.sync360.co.nz`

Intended production host:

- `161.97.74.128`

Responsibilities:

- Laravel web app
- queue worker
- PostgreSQL
- Redis
- tenant signup and admin/debug UI
- tenant placement and remote provisioning orchestration

### 22.2 Client Runtime Plane

Validated first runtime host:

- `89.116.28.191`

Responsibilities:

- tenant runtime directory root
- per-tenant OpenClaw containers
- Caddy reverse proxy
- public HTTPS termination for `*.workspace.sync360.co.nz`
- reverse proxy from tenant subdomains into the Sync360 control app

The OpenClaw gateway is no longer intended to be publicly reachable on the tenant hostname. Customer traffic lands in Sync360; control-plane traffic reaches OpenClaw privately over the existing SSH channel.

### 22.3 Trust And Secret Model

Current validated access model:

- remote access user: `deploy`
- transport: password-auth SSH
- privilege escalation: passworded `sudo`
- password lookup: env-only secrets on the control-plane worker

This is explicitly temporary. The next hardening step is:

- replace password auth with SSH keys
- update the `Server` record to use `ssh_auth_mode=key`
- remove password env keys from the worker environment

## 23. Test Coverage

Current feature coverage includes:

- landing page CTA rendering
- signup creation of user, tenant, server assignment, and job
- duplicate email validation
- graceful signup failure when no client VPS is available
- local provisioning success
- OpenClaw provisioning success
- OpenClaw readiness failure handling
- runtime file generation
- admin access restrictions
- admin retry flow
- admin workspace controls
 - password-auth SSH runner behavior
 - public hostname readiness failure handling

## 24. Architectural Strengths

- standard Laravel structure
- queue-driven provisioning
- clean separation between web, provisioning, and infrastructure concerns
- server-aware tenant placement
- local and SSH infrastructure modes behind one contract
- staging runtime generation isolated in one service
- OpenClaw provisioning isolated behind one provisioner
- admin visibility into provisioning and workspace state
 - validated remote deployment path to a real client VPS

## 25. Current Architectural Limits

The app is now VPS-ready at the control-plane level, but some operational concerns are still external or intentionally lightweight.

### 25.1 DNS Automation Is External

The app now automates tenant Caddy route installation and reloads on the client VPS, but it still does not automate:

- wildcard DNS creation
- DNS record updates across providers

Caddy can obtain and serve TLS automatically once DNS is already pointing to the client VPS.

### 25.2 Server Scheduling Is Basic

Current server selection is intentionally simple:

- active server only
- least-loaded by `current_clients`
- hard capacity check against `max_clients`

It does not yet support:

- health-based failover
- draining or maintenance windows
- regional placement
- auto-rebalancing

### 25.3 Password Auth Is Temporary

The current validated deployment uses password-auth SSH plus `sudo -S`.

That is appropriate for controlled staged testing, but not the final hardened production posture.

The next security step is key-based SSH with restricted sudo policy.

### 25.4 Long First Pulls Are Expected

The first OpenClaw deployment to a fresh client VPS can take several minutes because the `ghcr.io/openclaw/openclaw:latest` image is large.

The runner now uses the longer OpenClaw compose timeout to accommodate that first pull.

## 26. Summary

Today’s Sync360 Control App is:

- a Laravel control-plane MVP
- queue-driven
- Blade-based
- PostgreSQL-backed for state
- Redis-backed for async provisioning
- capable of assigning tenants to client VPS servers
- capable of provisioning OpenClaw containers locally or remotely over SSH
- capable of generating server-aware workspace URLs
- equipped with super-admin inspection and workspace control tools
- able to bootstrap and provision the first client VPS deployment architecture
- equipped with automated trial lifecycle enforcement (time + budget expiry, email notifications, dashboard credit widget)

It is now ready for the intended primary-server plus client-VPS deployment architecture at the application layer, with DNS automation, SSH key hardening, and broader production hardening left as the next operational steps.

---

## 27. Trial Lifecycle Architecture

### 27.1 Overview

Each tenant is provisioned with a free trial governed by **two independent expiry conditions** — whichever occurs first:

1. **Time:** 14 days from `tenants.created_at` (UTC)
2. **Budget:** LiteLLM AI spend reaches `litellm_max_budget` (default $5.00)

There is no grace period. The assistant is suspended immediately on expiry.

### 27.2 Schema (tenants table additions)

| Column | Type | Purpose |
|---|---|---|
| `trial_ends_at` | `timestamp nullable` | Set at signup = `created_at + 14 days` |
| `litellm_spend` | `decimal(10,6) nullable` | Cached spend from LiteLLM `/key/info` |
| `litellm_spend_cached_at` | `timestamp nullable` | When the spend was last refreshed |
| `trial_80pct_notified_at` | `timestamp nullable` | Idempotency guard for 80% budget email |
| `trial_3day_notified_at` | `timestamp nullable` | Idempotency guard for 3-day warning email |
| `trial_expired_notified_at` | `timestamp nullable` | Idempotency guard for trial expired email |

### 27.3 Scheduler

Command: `sync360:check-trial-expiry`
Schedule: every 30 minutes (defined in `routes/console.php`)

Per active trial tenant:
1. Calls `LiteLlmTenantKeyService::getKeyInfo()` → stores `litellm_spend` + `litellm_spend_cached_at`
2. Evaluates both expiry conditions
3. If expired: sets `trial_status = trial_expired`, calls `suspendTenant()`, sends expiry email once
4. If not expired: sends 80% budget warning once; sends 3-day warning once

### 27.4 Email Notifications

Service: `TrialNotificationEmailService` (Brevo direct-HTTP, same pattern as `WorkspaceReadyEmailService`)

| Event | Subject | Guard |
|---|---|---|
| Budget ≥ 80% | "Your AI credit is almost used up" | `trial_80pct_notified_at` |
| ≤ 3 days left | "Your trial ends in N days" | `trial_3day_notified_at` |
| Expired | "Your Sync360 trial has ended" | `trial_expired_notified_at` |

All emails idempotent. Upgrade CTA = `mailto:hello@sync360.co.nz` (Phase 1 — no self-serve upgrade).

### 27.5 Tenant Model Accessors

| Method | Returns |
|---|---|
| `isTrialExpired()` | `bool` |
| `trialDaysLeft()` | `int` — 0 if expired |
| `trialBudgetPercent()` | `float` — 0–100 |
| `trialTimePercent()` | `float` — 0–100 |
| `trialUrgency()` | `'ok'` / `'warning'` / `'critical'` |

### 27.6 Dashboard Trial Widget

`DashboardController::trialData()` passes a pre-computed snapshot (no live API call on page load).

**Active trial:** Two colour-coded progress bars (AI Credit + Trial Time). Urgency badge when approaching limit. "Usage data as of X" timestamp footer.

**Expired trial:** Full-width red banner. Trial stat card shows "Trial ended". Onboarding Step 6 Go Live button replaced with disabled state + contact message.

### 27.7 Existing Tenant Backfill Strategy

Tenants created before the `trial_ends_at` column was introduced have a `NULL` value in that column.

**Backfill migration** (`2026_04_13_125055_backfill_trial_ends_at_for_existing_tenants`):
- Sets `trial_ends_at = created_at + 14 days` for every tenant where `trial_ends_at IS NULL`
- Implemented as a PHP `lazyById()` loop (not raw SQL) to be compatible with both SQLite (test) and PostgreSQL (production)
- Runs automatically on deploy via `php artisan migrate`

**Defensive fallback in code** — two locations handle `NULL` without relying solely on the migration:

```php
// Tenant::trialDaysLeft()
$endsAt = $this->trial_ends_at ?? $this->created_at->copy()->addDays(14);

// sync360:check-trial-expiry command
$trialEndsAt = $tenant->trial_ends_at ?? $tenant->created_at->copy()->addDays(14);
```

This means a tenant with a `NULL` `trial_ends_at` will always behave correctly in both the dashboard and the scheduler, even if somehow missed by the backfill.

### 27.8 Superadmin Trial Visibility

Trial and spend metrics are visible to superadmins on two admin pages:

**`/admin/tenants` (tenants list):**
- New **Trial** column showing status badge and spend/budget hint
- Badge colour matches urgency: green (active, ok) / amber (warning) / red (critical or expired)

**`/admin/tenants/{slug}` (tenant detail):**
- New **Trial & AI Usage** section with dual progress bars (AI Credit + Trial Time)
- Full metadata grid exposing: `trial_status`, `trial_ends_at`, `litellm_spend` (4dp), `litellm_spend_cached_at`, all three notification guard timestamps, `litellm_plan_name`
- Allows superadmin to instantly confirm whether lifecycle emails have fired and when
