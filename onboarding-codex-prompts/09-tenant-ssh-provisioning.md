# Codex Agent Prompt — 09: Server Admin, Tenant Health Checks, And Resync Operations

## Context

The original onboarding draft assumed every tenant stores their own SSH credentials.

That does not match the current Sync360 platform.

Current reality:

- tenant placement happens through `servers`
- server SSH/runtime settings already live on the `servers` table
- the control plane manages runtime sync centrally

This prompt should therefore focus on:

1. server admin management
2. tenant health checks
3. tenant resync / restart operations

not per-tenant SSH credential entry.

## Task

Extend the current admin tooling so operators can manage infrastructure and support live tenants.

---

## Server Management

Use the existing `servers` table as the source of truth.

Support viewing and editing fields like:

- `ssh_host`
- `ssh_port`
- `ssh_user`
- `ssh_private_key_path`
- `ssh_auth_mode`
- `ssh_password_env_key`
- `sudo_password_env_key`
- `runtime_root`
- `workspace_scheme`
- `workspace_base_domain`
- `docker_compose_bin`
- `caddy_sites_path`
- `caddy_reload_command`
- `max_clients`
- `status`

If you add admin UI for this, keep it in the existing local-only admin area.

Do not move these fields onto `tenants`.

---

## Tenant Health Checks

Create `TenantHealthCheckService`.

### Method: `check(Tenant $tenant): array`

Use the current control-plane model:

1. load tenant + server
2. verify the tenant has:
   - assigned server
   - workspace URL
   - runtime path
3. perform an HTTP health check against the tenant workspace, for example:
   - `GET {workspace_url}/readyz`
   - or a dedicated ping endpoint if available
4. optionally use `DockerComposeRunner` to verify the remote service state through the assigned server
5. update:
   - `last_health_check_at`
   - `last_health_check_status`
   - `health_check_message`
   - `agent_status`

Do not assume `tenant.ssh_host` exists.

---

## Tenant Resync / Restart

Provide admin actions such as:

- `POST /admin/tenants/{tenant}/health-check`
- `POST /admin/tenants/{tenant}/resync-agent`
- `POST /admin/tenants/{tenant}/workspace/restart`

These should:

- reuse the assigned `Server`
- reuse the current runtime path
- reuse the same go-live/profile sync service from prompt 03 where possible

---

## Scheduling

Add a scheduled command:

```text
php artisan tenants:health-check
```

Run it every 5 minutes for tenants that are:

- `agent_status = live` or `failed`
- and have a workspace URL / assigned server

Queueing is fine if needed.

---

## Admin Access Model

Fit the existing app:

- current admin area already uses `is_admin`
- current admin area already uses `local.only`

Prefer extending that model rather than introducing a second auth stack for now.

---

## Acceptance Criteria

- infrastructure settings remain server-based
- tenant health checks work without tenant-level SSH fields
- admin can manually trigger tenant health checks and resyncs
- scheduled health checks keep tenant live/failed status fresh
- no mismatch is introduced between onboarding docs and the actual server-placement architecture

