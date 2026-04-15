# Sync360 Control App

Sync360 Control App is a Laravel monolith that provisions and manages tenant-specific OpenClaw workspaces for a multi-tenant AI service platform.

It currently covers the full control-plane loop:

- marketing site, signup, login, self-serve password reset, and dashboard
- tenant creation, server assignment, and async provisioning
- local and SSH-based tenant runtime deployment
- guided onboarding and go-live sync
- private gateway access for health checks and runtime integration
- conversation history sync from workspace session logs
- trial lifecycle tracking and notification emails
- local-only super-admin operations and control-plane deploy trigger

## Docs Map

Start here when opening a new chat or trying to understand the repo:

- `README.md` — canonical repo entrypoint
- [`artifacts/MEMORY.md`](artifacts/MEMORY.md) — canonical new-chat starter and durable project memory
- [`artifacts/ARCHITECTURE.md`](artifacts/ARCHITECTURE.md) — canonical as-built technical architecture reference
- [`artifacts/RELEASE_NOTES.md`](artifacts/RELEASE_NOTES.md) — canonical chronological engineering and product history

## Doc Status

Canonical/core docs:

- `README.md`
- [`artifacts/MEMORY.md`](artifacts/MEMORY.md)
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
2. canonical docs in `artifacts/MEMORY.md` and `artifacts/ARCHITECTURE.md`
3. `artifacts/RELEASE_NOTES.md`

## What The App Proves

1. A visitor lands on the marketing page and signs up for a trial.
2. Signup creates the user, tenant, business-profile records, and provisioning job.
3. The app assigns the tenant to a server.
4. A queue worker provisions the tenant runtime asynchronously.
5. The tenant gets a customer-facing workspace URL and a private gateway runtime.
6. The customer completes onboarding and syncs business/agent files into the workspace.
7. The app tracks conversations, trial state, health status, and admin operations from the control plane.

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
- its own customer-facing workspace URL

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

### Scheduler requirement

Scheduled commands are part of the live system. Production must run the `scheduler` service from `docker-compose.prod.yml` so these jobs execute automatically:

- `tenants:health-check`
- `sync360:check-trial-expiry`
- `sync360:sync-replies`

## Current Architecture Modes

### `local`

Used for local development and iterative testing.

- Laravel app, worker, Postgres, and Redis run in Docker Compose
- tenant runtimes are staged locally
- tenant containers run on the local Docker host

### `ssh`

Used for the primary-server plus client-VPS deployment shape.

- the control plane runs on the primary server
- runtime files are staged locally on the control plane
- files are copied to the assigned client VPS over SSH
- Docker Compose commands run remotely on that VPS
- readiness checks use private loopback HTTP on the client VPS

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

`README.md` remains optional and should be updated when repo entrypoint, setup, or operator guidance changed.

## Scope Notes

This repo does not treat the following as complete product areas yet:

- billing and subscription automation
- email verification
- full secret-management hardening
- automated DNS management
- multi-region scheduling and placement
- comprehensive production observability

Use `artifacts/RELEASE_NOTES.md` for historical changes and `artifacts/ARCHITECTURE.md` for the current technical shape.
