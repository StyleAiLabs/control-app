# Client VPS Production-First Plan

> [!IMPORTANT]
> Historical implementation plan. Canonical current truth is in [`artifacts/MEMORY.md`](../../MEMORY.md) and [`artifacts/ARCHITECTURE.md`](../../ARCHITECTURE.md). This file captures an earlier rollout stage for remote tenant deployment and should be used as background only.

## Summary

We will make `89.116.28.191` the first production tenant host and validate remote tenant deployment from your local control app before touching the primary control-app server at `161.97.74.128`.

This phase will support the current temporary access model:
- tenant URLs use `*.workspace.sync360.co.nz`
- the client VPS is accessed as `deploy@89.116.28.191`
- SSH is temporarily password-based
- `sudo` is password-prompted
- the app will automate both using `sshpass` plus env-only secrets
- no passwords are stored in git or the database

The milestone is done when a local signup provisions a real tenant onto `89.116.28.191`, Caddy serves `https://<tenant>.workspace.sync360.co.nz`, and the control app marks the tenant `ready`.

## Key Changes

### Infrastructure model
- Keep local control app as the control plane for this milestone.
- Treat `89.116.28.191` as the single active client VPS in `servers`.
- Defer deploying the control app to `161.97.74.128` until after this remote provisioning test succeeds.
- Change the tenant base domain assumption from `*.workspaces.sync360.co.nz` to `*.workspace.sync360.co.nz`.

### Secret and server configuration
- Extend `Server` to support temporary password-auth provisioning without storing secrets in DB.
- Add server fields for:
  - `ssh_auth_mode` with values `key` or `password`
  - `ssh_password_env_key`
  - `sudo_password_env_key`
  - `caddy_sites_path`
  - `caddy_reload_command`
- For the first client VPS record, set:
  - `host=89.116.28.191`
  - `ssh_host=89.116.28.191`
  - `ssh_user=deploy`
  - `runtime_root=/srv/sync360/runtime`
  - `workspace_scheme=https`
  - `workspace_base_domain=workspace.sync360.co.nz`
  - `docker_compose_bin="docker compose"`
  - `ssh_auth_mode=password`
  - `ssh_password_env_key=SYNC360_CLIENT_VPS_SSH_PASSWORD`
  - `sudo_password_env_key=SYNC360_CLIENT_VPS_SUDO_PASSWORD`
  - `caddy_sites_path=/etc/caddy/sites`
  - `caddy_reload_command="systemctl reload caddy"`
- Store the actual SSH and sudo passwords only in environment variables on the machine running the worker.

### Client VPS preparation
- Install and configure Caddy on `89.116.28.191`.
- Standardize Caddy layout as:
  - main config imports `/etc/caddy/sites/*.caddy`
  - tenant site files live in `/etc/caddy/sites`
- Create and own `/srv/sync360/runtime` for tenant runtime staging on the VPS.
- Ensure Docker is installed and usable by `deploy`.
- Ensure `deploy` can run the exact required sudo commands non-interactively through `sshpass` + `sudo -S`:
  - create/update `/etc/caddy/sites/*.caddy`
  - reload Caddy
  - optionally create runtime directories if needed

### Provisioning and routing flow
- Keep server selection on signup.
- Generate local tenant runtime staging under `runtime/tenants/<slug>/`.
- Generate tenant `compose.yaml` to bind OpenClaw to `127.0.0.1:<assigned_port>`.
- Generate a tenant-specific Caddy site fragment for:
  - `https://<slug>.workspace.sync360.co.nz`
  - reverse proxy to `127.0.0.1:<assigned_port>`
- Sync runtime files to `/srv/sync360/runtime/tenants/<slug>/` on the client VPS.
- Install or update the tenant Caddy file on the VPS.
- Reload Caddy after Caddy file changes.
- Start the tenant container remotely with Docker Compose.
- Poll remote readiness from the VPS against `http://127.0.0.1:<assigned_port>/readyz`.
- Mark tenant ready only after both remote readiness and public routing are confirmed.
- Set `workspace_url` to `https://<slug>.workspace.sync360.co.nz`.

### SSH runner changes
- Extend the SSH runner to support two auth paths:
  - key-based
  - temporary password-based via `sshpass`
- For password mode:
  - wrap `ssh` and `scp` with `sshpass`
  - use env lookups from the server’s env-key fields
  - support passworded `sudo` by piping the sudo password to `sudo -S`
- Install required client tooling in the worker/app runtime for this phase:
  - `openssh-client`
  - `sshpass`
  - `rsync` optional, but not required if we keep `scp -r`
- Keep key-based auth support intact because the next phase will switch the client VPS to SSH keys.

### Admin and operational behavior
- Show the assigned client VPS host and tenant hostname in `/admin/tenants`.
- Keep retry/start/stop actions targeting the assigned server.
- On provisioning retry:
  - keep the same server assignment
  - reset `assigned_port`, `workspace_url`, and `runtime_path`
  - rewrite the same tenant Caddy file path
- On failed provisioning after Caddy file creation but before readiness:
  - remove or disable the tenant Caddy file
  - reload Caddy
  - stop and clean stale tenant containers

### Docs and environment updates
- Update `.env.example`, `README.md`, and `ARCHITECTURE.md` for:
  - `*.workspace.sync360.co.nz`
  - password-auth temporary mode
  - Caddy-managed wildcard hostname routing
  - client-VPS-first deployment
- Add explicit env vars for this milestone:
  - `SYNC360_INFRASTRUCTURE_DRIVER=ssh`
  - `SYNC360_CLIENT_VPS_SSH_PASSWORD`
  - `SYNC360_CLIENT_VPS_SUDO_PASSWORD`
  - first-client default server metadata
- Document that password auth is temporary and should be replaced with key auth before moving the control app to `161.97.74.128`.

## Test Plan

### Automated tests
- Signup creates `user`, `tenant`, `provisioning_job`, and assigns the tenant to the configured client server.
- Workspace URL generation returns `https://<slug>.workspace.sync360.co.nz` when `workspace_base_domain=workspace.sync360.co.nz`.
- SSH runner chooses password mode when `ssh_auth_mode=password`.
- Provisioning writes tenant Caddy config content with the expected hostname and upstream port.
- OpenClaw provisioning success path:
  - sync runtime
  - install Caddy file
  - reload Caddy
  - remote compose up
  - remote readiness check
  - tenant becomes `ready`
- Failure path marks tenant/job failed when:
  - SSH command fails
  - sudo/Caddy install step fails
  - remote compose fails
  - readiness never succeeds
- Retry path creates a new queued job and preserves the server assignment.

### Manual acceptance on the client VPS
- `deploy` can SSH to `89.116.28.191`.
- `deploy` can run Docker Compose.
- Caddy serves wildcard subdomains for `*.workspace.sync360.co.nz`.
- A tenant signup from the local control app results in:
  - remote runtime directory present
  - tenant container running
  - tenant Caddy file present
  - public HTTPS hostname working
  - tenant marked `ready` in admin
- Admin start/stop controls work against the remote tenant runtime.

## Assumptions And Defaults

- `*.workspace.sync360.co.nz` already points to `89.116.28.191`.
- `deploy` has sudo access, but sudo prompts for a password.
- Password auth is temporary for this milestone only.
- We will not store SSH or sudo passwords in git or the database.
- The app will use env-only secrets referenced by the `Server` record.
- Caddy is the standard reverse proxy for the first client VPS.
- The control app remains local during this milestone.
- Migrating the control app to `161.97.74.128` happens only after remote tenant provisioning from local is proven.
