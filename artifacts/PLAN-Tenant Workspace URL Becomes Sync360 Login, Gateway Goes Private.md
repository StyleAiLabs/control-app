# Tenant Workspace URL Becomes Sync360 Login, Gateway Goes Private

## Status
Implemented on `2026-04-12` on branch `cdx-feature/tenant-workspace-login`.

## Summary
Change the tenant `workspace_url` contract so it is always the customer-facing Sync360 URL (`https://<slug>.workspace.sync360.co.nz`) and never a public OpenClaw surface. After provisioning, that hostname must open the control app experience: guest users go to login, authenticated matching users go to their dashboard. OpenClaw must stay private and reachable only through the existing SSH control channel.

## Key Changes
### Public routing and auth behavior
- Treat `workspace_url` as the canonical customer URL only. Keep storing `https://<slug>.workspace.sync360.co.nz` on the tenant.
- Add workspace-host detection based on the configured workspace base domain. When the request host matches a tenant workspace host:
  - `GET /` redirects guests to `/login`
  - `GET /` redirects authenticated matching customers to `/dashboard`
  - authenticated users whose tenant does not match the host are blocked with a strict “wrong workspace” response and a CTA to their own tenant URL; never render the wrong dashboard under that host
- Keep the normal `app.sync360.co.nz` flow working. Tenant subdomains are an additional branded entrypoint, not a replacement for the main app host.
- Set production session sharing to the parent domain (`.sync360.co.nz`) so login works across `app.sync360.co.nz` and `*.workspace.sync360.co.nz`.

### Private gateway access
- Stop using `tenant.workspace_url` for OpenClaw `/chat` and `/readyz`.
- Introduce a dedicated internal gateway helper based on tenant placement, using `http://127.0.0.1:<assigned_port>` on the client VPS as the gateway base URL. Do not persist a new public gateway URL.
- Extend the infrastructure runner contract with a captured private HTTP call method, for example:
  - `httpRequest(Server $server, string $method, string $url, ?array $json = null, int $timeoutSeconds = 15): array{status:int, body:string}`
- Implement that method:
  - local runner: use the normal HTTP client directly
  - SSH runner: execute remote `curl` on the client VPS over the existing SSH control channel and return status/body
- Move all gateway traffic to that private path:
  - inbound message forwarding to `/chat`
  - health checks to `/readyz`
  - any future control-plane gateway calls

### Provisioning and proxying
- Keep the tenant OpenClaw container bound to loopback only on the VPS. Do not publish any public Caddy route to the gateway.
- Change tenant Caddy site generation so `https://<slug>.workspace.sync360.co.nz` reverse proxies to the Sync360 control app upstream, not to `127.0.0.1:<assigned_port>`.
- Add an explicit control-app upstream config value for Caddy generation, defaulting from `APP_URL`, so the tenant hostname can proxy to the central app cleanly.
- Change public provisioning verification to check the customer entrypoint, not the gateway:
  - private readiness remains `GET http://127.0.0.1:<assigned_port>/readyz` over SSH
  - public readiness becomes `GET https://<slug>.workspace.sync360.co.nz/login` and must return a successful control-app response
- Disable OpenClaw control UI exposure in generated config so the gateway dashboard is not public even if proxy rules drift later.

### Customer/admin surfaces
- Keep customer emails and CTAs using `workspace_url`, but make the copy consistently refer to logging in to Sync360, not opening the gateway.
- Update customer pages and admin pages that currently say “Open Workspace” so they lead to the control-app experience on the tenant URL.
- Preserve the placeholder/internal `/workspace/{tenant:slug}` route only for local/internal debugging if still needed; it must not be the customer path of record.

## Interfaces and Config
- `workspace_url` remains on `tenants` and becomes strictly customer-facing.
- No new public gateway field is added to `tenants`.
- Add one infrastructure-facing config/env for the control-app upstream used in generated tenant Caddy config.
- Extend `DockerComposeRunner` with a private HTTP request method returning status + body so higher-level services no longer shell out ad hoc.

## Test Plan
- Provisioning test: tenant still gets `workspace_url=https://<slug>.workspace...`, generated Caddy config targets the control app upstream, and OpenClaw is not publicly proxied.
- Workspace host routing test:
  - guest visiting tenant root is redirected to tenant-host `/login`
  - matching tenant user visiting tenant root reaches dashboard
  - mismatched tenant user is blocked and never sees another tenant’s dashboard on that host
- Gateway privacy test: webhook processing and health checks work using private SSH-backed gateway calls even though the public tenant host no longer fronts OpenClaw.
- Ready email/dashboard/profile/admin CTA tests: all customer-facing links use the tenant Sync360 URL and wording reflects login/dashboard behavior.
- Regression tests for local mode and SSH mode so both drivers satisfy the new runner contract.

## Assumptions and Defaults
- Chosen UX: the tenant subdomain is a tenant-branded control-app entrypoint; after login the customer lands on their dashboard there.
- Chosen privacy model: OpenClaw is private-only; no public `/chat`, `/readyz`, dashboard, or chat UI routes are exposed on the tenant hostname.
- Chosen private transport: the control plane reaches OpenClaw through the existing SSH control channel by executing tenant-local HTTP requests on the client VPS.
- Chosen host policy: tenant host matching is strict; the app must not allow a different customer account to operate under another tenant’s subdomain.
