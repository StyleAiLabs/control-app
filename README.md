# Sync360 Control App

Sync360 Control App is a Laravel MVP that proves the future control-plane flow for a multi-tenant AI service platform.

It validates this path end to end:

1. Visitor lands on the marketing page
2. Visitor starts a free trial
3. Signup creates the user, tenant, and provisioning job
4. A Redis-backed queue worker provisions the tenant asynchronously
5. The tenant becomes `ready`
6. The user sees a workspace-ready screen with a generated workspace URL
7. Local admin/debug pages show users, tenants, jobs, ports, and errors
8. Stage 2 can launch one OpenClaw gateway container per tenant

## Stack

- Laravel 13
- PostgreSQL
- Redis
- Blade
- Docker Compose
- OpenClaw gateway containers (one per tenant, provisioned by the worker)

## Services

- `app` serves Laravel at [http://localhost:8000](http://localhost:8000)
- `worker` runs `php artisan queue:work redis`
- `worker` also talks to the host Docker daemon to start tenant-specific OpenClaw containers
- `postgres` stores application data
- `redis` backs the queue

## Local Setup

1. Ensure Docker Desktop is running on your Mac.
2. Copy the environment file if you want a clean local env:

```bash
cp .env.example .env
```

3. Build and start the stack:

```bash
docker compose up --build
```

4. Open [http://localhost:8000](http://localhost:8000).

The container startup scripts will:

- install Composer dependencies if `vendor/` is missing
- wait for PostgreSQL and Redis
- run migrations and seed the default local server record
- start either the web server or the queue worker

The Docker worker mounts `/var/run/docker.sock` and uses the host Docker daemon to launch per-tenant OpenClaw gateway containers. The generated tenant compose files bind to your host checkout path, so run `docker compose up` from the repo root on your Mac.

## Routes

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

## Provisioning Behavior

Provisioning is isolated behind the `App\Contracts\TenantProvisioner` contract.

Current behavior:

- default driver: `App\Services\OpenClawProvisioner`
- allocate the next free port from `4100-4199`
- create `runtime/tenants/<slug>/`
- copy files from `templates/tenant/`
- write tenant-specific `.env`
- write `metadata.json`
- write `config/openclaw.json`
- write a tenant-specific `compose.yaml`
- start one OpenClaw gateway container for that tenant with Docker Compose
- poll the OpenClaw readiness endpoint before marking the tenant `ready`
- store `runtime_path`, `assigned_port`, and `workspace_url`

Fallback behavior:

- `App\Services\LocalTenantProvisioningService` is still available as the `local` driver for tests or simple fake provisioning

This keeps provisioning replaceable later with remote Docker Compose or SSH-based VPS orchestration.

## Provisioning Configuration

Useful env vars:

- `TENANT_PROVISIONING_DRIVER=openclaw`
- `SYNC360_HOST_PROJECT_ROOT=/absolute/path/to/your/repo` when you are not relying on the shell-provided `PWD`
- `OPENCLAW_IMAGE=ghcr.io/openclaw/openclaw:latest`
- `OPENCLAW_READINESS_PATH=/readyz`
- `OPENCLAW_GATEWAY_INTERNAL_PORT=18789`

The default `.env.example` is already set up for the Stage 2 OpenClaw flow.

## Default Admin

After migrations and seeding, a default super admin is available:

- Email: `admin@sync360.local`
- Password: `admin12345`

## Tests

Run the Laravel test suite with:

```bash
php artisan test
```

Or from inside Docker:

```bash
docker compose exec app php artisan test
```

The tests cover:

- landing page response
- signup creating user, tenant, and provisioning job records
- duplicate email validation
- local provisioning success path
- OpenClaw provisioning success path
- runtime file generation
- provisioning failure handling
- OpenClaw readiness failure handling
- setup status endpoint and ready screen
- admin retry flow

## Scope Notes

This MVP intentionally excludes:

- Stripe or billing
- production RBAC
- password reset and email verification
- remote infrastructure provisioning
- advanced monitoring or enterprise controls
