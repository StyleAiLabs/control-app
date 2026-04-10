# Sync360 Control App MVP Plan

## Summary
Build a fresh Laravel monolith in the repo root, running entirely through Docker Compose with four services: `app`, `worker`, `postgres`, and `redis`. The MVP will validate one end-to-end flow: landing page -> custom Blade signup -> user + tenant + provisioning job creation -> Redis queue dispatch -> async provisioning service -> tenant marked ready -> user sees a ready screen with a generated workspace URL and a working placeholder workspace screen in the control app.

## Key Changes
- Bootstrap a new Laravel app in the current repo root and keep [`architecture.html`](/Users/gayanhewage/Projects/openclaw-saas/architecture.html) untouched as a reference artifact.
- Use a single custom PHP image for both `app` and `worker`; `app` serves Laravel on port `8000`, `worker` runs `php artisan queue:work redis`.
- Add `docker-compose.yml`, `Dockerfile`, startup script, persistent Postgres volume, bind-mounted source, and bind-mounted `runtime/` directory so filesystem provisioning survives container restarts.
- Ship `.env.example` with local Docker defaults, `QUEUE_CONNECTION=redis`, Postgres/Redis connection settings, runtime/template paths, and a dev-safe app key so setup is `cp .env.example .env` + `docker compose up --build`.
- Implement minimal custom session auth instead of a starter kit: guest routes for `GET/POST /signup` and `GET/POST /login`, authenticated `POST /logout`, and standard Laravel session persistence.
- Map signup fields as follows: `contact_name -> users.name`, `phone -> users.phone`, `business_name/industry/skill_pack -> tenants`, and keep `email/password` on `users`.
- Create database tables and Eloquent models for `users`, `tenants`, `provisioning_jobs`, and `servers`.
- Use `users.phone` as a nullable column even though it was not in the original table sketch, because the form requires it and it should be persisted.
- Model tenant ownership as one-user-to-one-tenant for MVP by enforcing a unique `tenants.user_id`.
- Use `tenants.id` as the primary key and add a unique `tenant_id` ULID/string as the external tenant identifier; also add unique `slug`, nullable unique `assigned_port`, and nullable `workspace_url` / `runtime_path`.
- Seed one default `servers` row: `local-dev-server`, host `localhost`, status `active`, and zero current clients.
- Define domain enums or constants for tenant provisioning status (`pending`, `provisioning`, `ready`, `failed`), trial status (`trial_active`, `trial_expired`), and provisioning job status (`queued`, `running`, `completed`, `failed`).
- On successful signup, wrap creation in a transaction: create the user, create the tenant with `trial_active` and `pending`, create a `provisioning_jobs` row with `job_type=provision_tenant` and `status=queued`, log the user in, dispatch a queued Laravel job, and redirect to `/tenant/setup`.
- Build a dedicated provisioning service, e.g. `LocalTenantProvisioningService`, and keep all provisioning logic there so the queue job only orchestrates status transitions and error handling.
- The provisioning service will run these steps in order: mark tenant/job as provisioning/running, sleep 3-5 seconds, allocate the next free port from `4100-4199`, create `/runtime/tenants/<slug>/`, copy contents from `/templates/tenant/`, ensure `config/`, `data/`, and `logs/` exist, write tenant-specific `.env`, write `metadata.json`, persist `runtime_path`, set `workspace_url` to `http://localhost:<assigned_port>`, and mark tenant/job ready/completed.
- The generated `workspace_url` will be stored exactly as `http://localhost:41xx` for architecture validation, but the UI “Open Workspace” action will go to an internal placeholder route such as `/workspace/{tenant:slug}` so the demo flow is fully clickable without standing up per-port tenant runtimes yet.
- Port allocation will be a small allocator method inside the provisioning service that scans existing assigned ports in range order and throws a domain exception when no free port remains.
- Failure handling will be explicit: any provisioning exception marks the tenant `failed`, marks the provisioning job `failed`, stores `error_message`, and leaves the user on a friendly failure state with retry information.
- Add authenticated Blade pages for `/dashboard`, `/tenant/setup`, and `/tenant/workspace-ready`.
- `/tenant/setup` will poll a small JSON status endpoint, e.g. `GET /tenant/status`, every few seconds and redirect to `/tenant/workspace-ready` when provisioning becomes ready.
- `/dashboard` will be a lightweight account summary page showing tenant name, skill pack, trial status, provisioning status, and links to setup or workspace-ready depending on current state.
- `/workspace/{tenant:slug}` will be a simple placeholder workspace screen confirming the tenant slug, business name, selected skill pack, assigned port, and generated workspace URL.
- Add a developer-focused local admin area protected by auth plus local-environment check: `/admin`, `/admin/tenants`, and `/admin/jobs`.
- The admin views will show users, tenants, provisioning jobs, statuses, assigned ports, workspace URLs, runtime paths, and failure messages.
- Include a retry action from admin that creates a new `provisioning_jobs` record for the same tenant and dispatches a fresh queue job rather than mutating historical job records.
- Keep views Blade-first and intentionally simple: one guest layout, one app layout, and small partials/components only where they reduce duplication.

## Public Interfaces
- Web routes: `/`, `/signup`, `/login`, `/logout`, `/dashboard`, `/tenant/setup`, `/tenant/status`, `/tenant/workspace-ready`, `/workspace/{tenant:slug}`, `/admin`, `/admin/tenants`, `/admin/jobs`, and `POST /admin/jobs/{tenant}/retry`.
- Domain service interface: one provisioning service entrypoint that accepts a tenant and provisioning-job record and is the only place that touches ports, runtime folders, template copies, and workspace URL generation.
- Database-visible additions beyond the original sketch: `users.phone`, unique `tenants.user_id`, unique `tenants.tenant_id`, and nullable unique `tenants.assigned_port`.

## Test Plan
- Feature test: landing page renders and CTA points to `/signup`.
- Feature test: signup with valid payload creates `users`, `tenants`, and `provisioning_jobs` rows with expected initial statuses and logs the user in.
- Feature test: duplicate email and other validation failures return errors and create no tenant/job records.
- Integration test: queued provisioning job updates job status from queued -> running -> completed and tenant status from pending -> provisioning -> ready.
- Integration test: provisioning creates the expected runtime directory tree, `.env`, and `metadata.json`, and persists `runtime_path`, `assigned_port`, and `workspace_url`.
- Integration test: forced provisioning failure marks both tenant and provisioning job as failed and stores `error_message`.
- Feature test: `/tenant/setup` status endpoint reports pending/provisioning/ready correctly and ready state is reflected on `/tenant/workspace-ready`.
- Feature test: admin pages list tenants/jobs and retrying a failed tenant creates a new queued provisioning job.
- Keep tests at the Laravel feature/integration level; no browser automation or frontend framework tests for this MVP.

## Assumptions And Defaults
- The repo is intentionally empty, so the Laravel app will be created at the root of `/Users/gayanhewage/Projects/openclaw-saas`.
- This is local-only development, so a dev-safe app key in `.env.example` is acceptable for frictionless startup.
- No billing, RBAC, email verification, password reset, or multi-tenant team membership will be included.
- Admin/debug pages are local-dev tools, so authenticated-local access is enough; no separate admin role will be introduced.
- The generated workspace URL proves allocation and future routing shape, but the actual demo click target will be the internal placeholder workspace route until a later phase adds real per-port tenant runtimes.
