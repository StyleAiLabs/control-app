# Sync360 Control App Architecture

This document describes the current as-built architecture of the Sync360 Control App on the `codex/vps-ready-architecture` branch.

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
8. A super admin can inspect tenants, jobs, and workspace state.

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

### 5.4 Infrastructure Layer

Handled through the `DockerComposeRunner` contract.

Implementations:

- `App\Services\LocalDockerComposeRunner`
- `App\Services\SshDockerComposeRunner`

This layer now owns:

- runtime sync for remote servers
- Docker Compose start/stop/up/down
- remote port probing
- readiness polling

## 6. Route Architecture

Defined in [routes/web.php](/Users/gayanhewage/Projects/openclaw-saas/routes/web.php).

### Public Routes

- `/`
- `/signup`
- `/login`

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
- `/admin/jobs`
- `POST /admin/jobs/{tenant}/retry`
- `POST /admin/tenants/{tenant}/workspace/start`
- `POST /admin/tenants/{tenant}/workspace/stop`

Admin routes are protected by:

- `auth`
- `admin`
- optional `local.only`, controlled by config

## 7. Authentication And Admin Model

Authentication is intentionally small and session-based.

Implemented behavior:

- register
- login
- logout
- session persistence
- guest-only auth pages
- admin-only access to operational routes

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

### 8.3 ProvisioningJobs

Purpose:

- record each provisioning attempt and its result

Important fields:

- `id`
- `tenant_id`
- `job_type`
- `status`
- `payload_json`
- `error_message`
- `started_at`
- `completed_at`

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
6. Increment the selected server’s `current_clients`.
7. Log the user in.
8. Dispatch `ProcessTenantProvisioning`.
9. Redirect to `/tenant/setup`.

If no client VPS is available, signup fails cleanly with a validation-style error instead of a `500`.

## 11. Queue Flow

Entry job:

- [app/Jobs/ProcessTenantProvisioning.php](/Users/gayanhewage/Projects/openclaw-saas/app/Jobs/ProcessTenantProvisioning.php)

Responsibilities:

- load tenant and provisioning job
- resolve the active `TenantProvisioner`
- call `provision($tenant, $provisioningJob)`
- mark tenant and job failed if provisioning throws

## 12. Provisioning Architecture

### 12.1 Contract

- [app/Contracts/TenantProvisioner.php](/Users/gayanhewage/Projects/openclaw-saas/app/Contracts/TenantProvisioner.php)

### 12.2 Runtime Preparation

Shared runtime work is handled by:

- [app/Services/TenantRuntimeService.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/TenantRuntimeService.php)

Responsibilities:

- allocate next free port for the assigned server
- generate server-aware workspace URL
- create local runtime staging path
- copy template files
- create required directories
- write tenant `.env`
- write `metadata.json`
- compute remote runtime path from the assigned server

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
5. Generate local runtime staging files.
6. Compute remote runtime path for the assigned server.
7. Write `config/openclaw.json`.
8. Write `compose.yaml` using the remote runtime bind path.
9. Write a tenant-specific `workspace.caddy` site fragment when wildcard-domain routing is configured.
10. Persist `assigned_port`, `runtime_path`, and `workspace_url`.
11. Stop any stale remote tenant runtime and remove any stale tenant Caddy file.
12. Sync local runtime staging to the assigned server over SSH when using SSH infrastructure.
13. Install the tenant Caddy site file on the client VPS and reload Caddy.
14. Start the OpenClaw container on the target host.
15. Poll the loopback readiness endpoint on the client VPS.
16. Poll the public HTTPS workspace hostname.
17. Mark tenant `ready`.
18. Mark provisioning job `completed`.

On failure:

- stale runtime is cleaned up as best effort
- stale tenant Caddy site files are removed as best effort
- tenant becomes `failed`
- provisioning job becomes `failed`
- `error_message` is stored

## 13. Infrastructure Runner Architecture

### 13.1 Contract

- [app/Contracts/DockerComposeRunner.php](/Users/gayanhewage/Projects/openclaw-saas/app/Contracts/DockerComposeRunner.php)

Responsibilities:

- sync runtime files to a target server
- run Docker Compose commands
- probe server port usage
- check readiness

### 13.2 Local Runner

- [app/Services/LocalDockerComposeRunner.php](/Users/gayanhewage/Projects/openclaw-saas/app/Services/LocalDockerComposeRunner.php)

Used when:

- `SYNC360_INFRASTRUCTURE_DRIVER=local`

Behavior:

- no runtime sync step
- uses local Docker Compose
- checks readiness locally

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

Workspace URLs are generated from the assigned `Server`.

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

It is now ready for the intended primary-server plus client-VPS deployment architecture at the application layer, with DNS automation, SSH key hardening, and broader production hardening left as the next operational steps.
