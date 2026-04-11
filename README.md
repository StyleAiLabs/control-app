# Sync360 Control App

Sync360 Control App is a Laravel control-plane MVP for provisioning tenant workspaces for a future multi-tenant AI service platform.

It now supports two infrastructure modes:

- `local`: local-first development on one machine
- `ssh`: VPS-ready provisioning from a primary app server to a remote client VPS over SSH

## What The App Proves

1. A visitor lands on the marketing page.
2. The visitor starts a free trial.
3. Signup creates the user, tenant, and provisioning job.
4. The app assigns the tenant to a client server.
5. A Redis-backed queue worker provisions the tenant asynchronously.
6. The tenant becomes `ready`.
7. The user sees a workspace-ready screen with a generated workspace URL.
8. A super admin can inspect users, tenants, jobs, and workspace state.

## Stack

- Laravel 13
- PostgreSQL
- Redis
- Blade
- Docker Compose for local development
- OpenClaw gateway containers, one per tenant
- SSH-based remote Docker orchestration for VPS-ready provisioning

## Current Architecture Modes

### Local Development Mode

Used for local testing and design work.

- `app` serves Laravel
- `worker` runs the queue
- `postgres` stores app data
- `redis` backs the queue
- tenant OpenClaw containers run on the local Docker host

### VPS-Ready Mode

Used for real deployment architecture.

- the Laravel app and worker run on the primary control server
- each tenant is assigned to a `servers` record
- runtime files are generated on the control server
- those files are copied to the client VPS over SSH
- Docker Compose is run remotely on the client VPS over SSH
- readiness is checked remotely against `127.0.0.1:<assigned_port>/readyz`
- the tenant receives a real server-aware workspace URL

This makes the control app ready for:

- `app.sync360.co.nz` on a primary app server
- one or more separate client VPS servers for tenant runtimes

## Production Deployment On The Primary Server

The recommended production shape for the control app is:

- [docker-compose.prod.yml](/Users/gayanhewage/Projects/openclaw-saas/docker-compose.prod.yml) for the Dockerized control-plane stack
- host-level Apache on `161.97.74.128` terminating TLS for `app.sync360.co.nz`
- Apache reverse proxying to the app container on `127.0.0.1:8000`
- the queue worker using the current password-auth SSH flow to provision the client VPS

This fits the existing LAMP host without turning the Laravel app back into a host-level PHP deployment.

### Production Files

- [docker-compose.prod.yml](/Users/gayanhewage/Projects/openclaw-saas/docker-compose.prod.yml)
- [.env.production.example](/Users/gayanhewage/Projects/openclaw-saas/.env.production.example)
- [docker/start-prod-app.sh](/Users/gayanhewage/Projects/openclaw-saas/docker/start-prod-app.sh)
- [docker/start-prod-worker.sh](/Users/gayanhewage/Projects/openclaw-saas/docker/start-prod-worker.sh)
- [deploy/apache/app.sync360.co.nz.conf](/Users/gayanhewage/Projects/openclaw-saas/deploy/apache/app.sync360.co.nz.conf)

### Production Boot Flow

1. Copy the production env file:

```bash
cp .env.production.example .env
```

2. Generate an app key:

```bash
docker compose -f docker-compose.prod.yml run --rm app php artisan key:generate --show
```

3. Put the generated value into `APP_KEY` in `.env`.

4. Start the production stack:

```bash
docker compose -f docker-compose.prod.yml up --build -d
```

5. Enable Apache proxy modules on the host:

```bash
sudo a2enmod proxy proxy_http headers rewrite ssl
```

6. Install [deploy/apache/app.sync360.co.nz.conf](/Users/gayanhewage/Projects/openclaw-saas/deploy/apache/app.sync360.co.nz.conf), enable the site, and reload Apache.

7. Bootstrap the client VPS once from the running app container:

```bash
docker compose -f docker-compose.prod.yml exec app php artisan sync360:bootstrap-client-vps
```

### Production Notes

- `SYNC360_RUN_SEED=true` is safe for first boot and ongoing restarts.
- existing super-admin passwords are preserved by default.
- set `SYNC360_RESET_SUPER_ADMIN_PASSWORD=true` only when you intentionally want to rotate the seeded super-admin password from env.
- for now, production still supports the temporary password-auth SSH flow through:
  - `SYNC360_CLIENT_VPS_SSH_PASSWORD`
  - `SYNC360_CLIENT_VPS_SUDO_PASSWORD`
- the production stack only binds Laravel to `127.0.0.1:8000`, so Apache remains the public entrypoint.

### Super Admin Deploy Button

The admin overview can now trigger a safe control-app deployment on the primary server.

This works by:

- SSHing from Laravel to the primary server
- starting the host-side script [deploy/scripts/run-control-app-deploy.sh](/Users/gayanhewage/Projects/openclaw-saas/deploy/scripts/run-control-app-deploy.sh)
- letting that script run `git fetch`, `git pull`, and `docker compose -f docker-compose.prod.yml up --build -d`
- reading back the last deploy state and recent log output inside `/admin`

Required env values:

- `SYNC360_CONTROL_DEPLOY_ENABLED`
- `SYNC360_CONTROL_DEPLOY_SSH_HOST`
- `SYNC360_CONTROL_DEPLOY_SSH_PORT`
- `SYNC360_CONTROL_DEPLOY_SSH_USER`
- `SYNC360_CONTROL_DEPLOY_SSH_AUTH_MODE`
- `SYNC360_CONTROL_DEPLOY_SSH_PASSWORD_ENV_KEY`
- `SYNC360_CONTROL_SERVER_SSH_PASSWORD`
- `SYNC360_CONTROL_DEPLOY_REPO_PATH`
- `SYNC360_CONTROL_DEPLOY_BRANCH`
- `SYNC360_CONTROL_DEPLOY_COMPOSE_FILE`
- `SYNC360_CONTROL_DEPLOY_SCRIPT_PATH`
- `SYNC360_CONTROL_DEPLOY_STATUS_FILE`
- `SYNC360_CONTROL_DEPLOY_LOG_FILE`

The deploy user on the primary server should:

- own the repo checkout
- be able to run `git pull`
- have Docker access without sudo

This is intentionally safer than letting the web app control its own Docker daemon directly.

## Local Setup

1. Copy the environment file:

```bash
cp .env.example .env
```

2. Build and start the stack:

```bash
docker compose up --build
```

3. Open [http://localhost:8000](http://localhost:8000)

The startup scripts will:

- install Composer dependencies if needed
- wait for PostgreSQL and Redis
- run migrations
- seed the default admin and default server record
- start the web app or queue worker

## Core Routes

- `/`
- `/signup`
- `/login`
- `/dashboard`
- `/tenant/setup`
- `/tenant/status`
- `/tenant/workspace-ready`
- `/workspace/{tenant:slug}`
- `/admin`
- `/admin/users`
- `/admin/tenants`
- `/admin/jobs`

## Provisioning Flow

Provisioning is isolated behind the `App\Contracts\TenantProvisioner` contract.

Current default behavior:

- select an active client server
- create `users`, `tenants`, and `provisioning_jobs`
- allocate the next free port for the assigned server
- generate a tenant-scoped LiteLLM virtual key before OpenClaw startup
- generate tenant runtime files in `runtime/tenants/<slug>/`
- write tenant `.env`
- write `metadata.json`
- write `config/openclaw.json`
- write tenant `compose.yaml`
- if `SYNC360_INFRASTRUCTURE_DRIVER=local`, run Docker Compose locally
- if `SYNC360_INFRASTRUCTURE_DRIVER=ssh`, copy runtime to the remote server and run Docker Compose remotely over SSH
- poll the readiness endpoint
- mark the tenant `ready`

### LiteLLM Tenant Keys

OpenClaw provisioning now creates one dedicated LiteLLM virtual key per tenant before the workspace container starts.

Required env values:

- `LITELLM_BASE_URL`
- `LITELLM_MASTER_KEY`

Optional plan defaults:

- `LITELLM_DEFAULT_PLAN_NAME`
- `LITELLM_DEFAULT_MAX_BUDGET`
- `LITELLM_DEFAULT_BUDGET_DURATION`
- `LITELLM_TRIAL_MAX_BUDGET`

Current behavior:

- provisioning aborts if LiteLLM key generation fails
- the generated key is stored encrypted on the tenant record
- the tenant runtime receives:
  - `OPENAI_API_KEY`
  - `OPENAI_BASE_URL`
- plan updates and suspension are handled through LiteLLM `key/update`
- cancellation-ready cleanup is handled through LiteLLM `key/delete`

### Provisioners

- `App\Services\OpenClawProvisioner`
- `App\Services\LocalTenantProvisioningService`

### Infrastructure Runners

- `App\Services\LocalDockerComposeRunner`
- `App\Services\SshDockerComposeRunner`

## Server Assignment

Each tenant now belongs to a `Server`.

The server record stores:

- public host
- SSH host
- SSH port
- SSH user
- SSH private key path
- remote runtime root
- workspace URL scheme
- optional workspace base domain
- Docker Compose binary

At signup, the app picks the first active server with available capacity and increments its `current_clients`.

## Workspace URL Generation

Workspace URLs are no longer hard-coded to `localhost`.

The current rules are:

- if `workspace_base_domain` is set on the assigned server:
  - URL becomes `https://<tenant-slug>.<workspace_base_domain>` or whatever scheme the server defines
- otherwise:
  - URL falls back to `<scheme>://<server-host>:<assigned_port>`

This means you can support either:

- wildcard subdomain routing such as `acme.workspace.sync360.co.nz`
- direct host-and-port routing during infrastructure testing

## Recommended DNS Shape

For a single client VPS, the recommended routing model is:

- `app.sync360.co.nz` -> primary control server
- `*.workspace.sync360.co.nz` -> client VPS

That avoids one DNS record per tenant signup.

If you do not have wildcard DNS and reverse proxying ready yet, the app can still generate host-and-port workspace URLs from the assigned server record.

## Important Environment Variables

### App / Infrastructure

- `SYNC360_ADMIN_LOCAL_ONLY=false`
- `SYNC360_INFRASTRUCTURE_DRIVER=local` or `ssh`
- `TRUSTED_PROXIES=*`
- `SYNC360_RUN_SEED=true`
- `SYNC360_QUEUE_TRIES`
- `SYNC360_QUEUE_TIMEOUT`
- `SYNC360_QUEUE_SLEEP`
- `SYNC360_QUEUE_MAX_TIME`
- `SYNC360_LOCAL_DOCKER_COMPOSE_BIN="docker compose"`
- `SYNC360_SSH_TIMEOUT_SECONDS=30`
- `SYNC360_SCP_TIMEOUT_SECONDS=120`
- `SYNC360_CLIENT_VPS_SSH_PASSWORD`
- `SYNC360_CLIENT_VPS_SUDO_PASSWORD`
- `SYNC360_CONTROL_DEPLOY_ENABLED`
- `SYNC360_CONTROL_DEPLOY_SSH_HOST`
- `SYNC360_CONTROL_DEPLOY_SSH_PORT`
- `SYNC360_CONTROL_DEPLOY_SSH_USER`
- `SYNC360_CONTROL_DEPLOY_SSH_AUTH_MODE`
- `SYNC360_CONTROL_DEPLOY_SSH_PASSWORD_ENV_KEY`
- `SYNC360_CONTROL_SERVER_SSH_PASSWORD`
- `SYNC360_CONTROL_DEPLOY_REPO_PATH`
- `SYNC360_CONTROL_DEPLOY_BRANCH`
- `SYNC360_CONTROL_DEPLOY_COMPOSE_FILE`
- `SYNC360_CONTROL_DEPLOY_SCRIPT_PATH`
- `SYNC360_CONTROL_DEPLOY_STATUS_FILE`
- `SYNC360_CONTROL_DEPLOY_LOG_FILE`
- `SYNC360_SUPER_ADMIN_NAME`
- `SYNC360_SUPER_ADMIN_EMAIL`
- `SYNC360_SUPER_ADMIN_PASSWORD`
- `SYNC360_RESET_SUPER_ADMIN_PASSWORD`

### Workspace Ready Email

- `BREVO_ENABLED=true`
- `BREVO_API_KEY`
- `BREVO_BASE_URL=https://api.brevo.com/v3`
- `BREVO_SENDER_EMAIL` or `MAIL_FROM_ADDRESS`
- `BREVO_SENDER_NAME` or `MAIL_FROM_NAME`

When Brevo is enabled, Sync360 sends a workspace-ready confirmation email after successful provisioning. That email includes:

- login username
- the initial password chosen at signup
- the tenant workspace link

The password is stored encrypted in the provisioning job payload only until the email is sent successfully, then it is removed.

### Tenant Provisioning

- `TENANT_PROVISIONING_DRIVER=openclaw`
- `TENANT_RUNTIME_ROOT=/var/www/html/runtime/tenants`
- `TENANT_TEMPLATE_ROOT=/var/www/html/templates/tenant`
- `TENANT_PORT_RANGE_START=4100`
- `TENANT_PORT_RANGE_END=4199`

### Default Seeded Client Server

- `SYNC360_DEFAULT_SERVER_NAME`
- `SYNC360_DEFAULT_SERVER_HOST`
- `SYNC360_DEFAULT_SERVER_SSH_HOST`
- `SYNC360_DEFAULT_SERVER_SSH_PORT`
- `SYNC360_DEFAULT_SERVER_SSH_USER`
- `SYNC360_DEFAULT_SERVER_SSH_PRIVATE_KEY_PATH`
- `SYNC360_DEFAULT_SERVER_SSH_AUTH_MODE`
- `SYNC360_DEFAULT_SERVER_SSH_PASSWORD_ENV_KEY`
- `SYNC360_DEFAULT_SERVER_SUDO_PASSWORD_ENV_KEY`
- `SYNC360_DEFAULT_SERVER_RUNTIME_ROOT`
- `SYNC360_DEFAULT_SERVER_WORKSPACE_SCHEME`
- `SYNC360_DEFAULT_SERVER_WORKSPACE_BASE_DOMAIN`
- `SYNC360_DEFAULT_SERVER_DOCKER_COMPOSE_BIN`
- `SYNC360_DEFAULT_SERVER_CADDY_SITES_PATH`
- `SYNC360_DEFAULT_SERVER_CADDY_RELOAD_COMMAND`

### OpenClaw

- `OPENCLAW_IMAGE`
- `OPENCLAW_SERVICE_NAME`
- `OPENCLAW_COMPOSE_FILENAME`
- `OPENCLAW_CONTAINER_HOME`
- `OPENCLAW_GATEWAY_INTERNAL_PORT`
- `OPENCLAW_READINESS_PATH`
- `OPENCLAW_READINESS_TIMEOUT_SECONDS`
- `OPENCLAW_READINESS_POLL_INTERVAL_MS`
- `OPENCLAW_COMPOSE_TIMEOUT_SECONDS`

## Example VPS Deployment Shape

### Primary Control Server

- public app URL: `https://app.sync360.co.nz`
- runs:
  - Laravel app
  - queue worker
  - Postgres
  - Redis

### Client VPS

- runs:
  - Docker engine
  - tenant OpenClaw containers
  - optional wildcard reverse proxy for subdomains

Example server env values:

```env
APP_URL=https://app.sync360.co.nz
APP_ENV=production
APP_DEBUG=false

SYNC360_ADMIN_LOCAL_ONLY=false
SYNC360_INFRASTRUCTURE_DRIVER=ssh

SYNC360_DEFAULT_SERVER_NAME=sync360-client-vps-1
SYNC360_DEFAULT_SERVER_HOST=89.116.28.191
SYNC360_DEFAULT_SERVER_SSH_HOST=89.116.28.191
SYNC360_DEFAULT_SERVER_SSH_PORT=22
SYNC360_DEFAULT_SERVER_SSH_USER=deploy
SYNC360_DEFAULT_SERVER_SSH_PRIVATE_KEY_PATH=
SYNC360_DEFAULT_SERVER_SSH_AUTH_MODE=password
SYNC360_DEFAULT_SERVER_SSH_PASSWORD_ENV_KEY=SYNC360_CLIENT_VPS_SSH_PASSWORD
SYNC360_DEFAULT_SERVER_SUDO_PASSWORD_ENV_KEY=SYNC360_CLIENT_VPS_SUDO_PASSWORD
SYNC360_DEFAULT_SERVER_RUNTIME_ROOT=/srv/sync360/runtime
SYNC360_DEFAULT_SERVER_WORKSPACE_SCHEME=https
SYNC360_DEFAULT_SERVER_WORKSPACE_BASE_DOMAIN=workspace.sync360.co.nz
SYNC360_DEFAULT_SERVER_DOCKER_COMPOSE_BIN="docker compose"
SYNC360_DEFAULT_SERVER_CADDY_SITES_PATH=/etc/caddy/sites
SYNC360_DEFAULT_SERVER_CADDY_RELOAD_COMMAND="systemctl reload caddy"
SYNC360_CLIENT_VPS_SSH_PASSWORD=replace-me
SYNC360_CLIENT_VPS_SUDO_PASSWORD=replace-me
```

Bootstrap the client VPS once before the first signup:

```bash
php artisan sync360:bootstrap-client-vps
```

That command installs Caddy, creates `/srv/sync360/runtime`, enables `/etc/caddy/sites/*.caddy` imports, and verifies `docker compose` access for the `deploy` user.

## Default Admin

The seeded super admin is now env-driven.

Important env vars:

- `SYNC360_SUPER_ADMIN_NAME`
- `SYNC360_SUPER_ADMIN_EMAIL`
- `SYNC360_SUPER_ADMIN_PASSWORD`
- `SYNC360_RESET_SUPER_ADMIN_PASSWORD`

Development defaults:

- Email: `admin@sync360.local`
- Password: `admin12345`

## Tests

Run tests with:

```bash
php artisan test
```

Current coverage includes:

- landing page CTA rendering
- signup creation of user, tenant, server assignment, and provisioning job
- duplicate email validation
- graceful signup failure when no client VPS is available
- local provisioning success path
- OpenClaw provisioning success path
- OpenClaw readiness failure handling
- public workspace hostname failure handling
- password-auth SSH transport handling
- runtime file generation
- admin retry flow
- admin access restrictions
- admin workspace controls
- seeded super-admin password preservation and explicit reset behavior
- super-admin control-app deploy trigger and status view

## Scope Notes

This MVP still intentionally excludes:

- billing
- password reset and email verification
- remote DNS automation
- advanced monitoring and alerting
- multi-region scheduling
- production hardening across every failure mode

## Architecture Reference

For the full as-built architecture, see [ARCHITECTURE.md](/Users/gayanhewage/Projects/openclaw-saas/ARCHITECTURE.md).
