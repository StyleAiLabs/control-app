# Bug: SshDockerComposeRunner::putFile — remote VPS file not updated

> [!IMPORTANT]
> Historical debugging note. Canonical current truth is in [`artifacts/MEMORY.md`](../../MEMORY.md) and [`artifacts/ARCHITECTURE.md`](../../ARCHITECTURE.md). Telegram webhook configuration described here is no longer current, and WhatsApp integration references in older material may reflect planned or scaffolded work rather than a complete implementation.

**Status:** ✅ Resolved  
**Priority:** ~~Medium~~ — fixed  
**Discovered:** 2026-04-13 during Telegram webhook mode investigation  
**Resolved:** 2026-04-15 — live test confirmed end-to-end write on production VPS  
**Workaround (no longer needed):** ~~Manual `python3` config patch + container restart~~  

---

## Summary

`SshDockerComposeRunner::putFile()` appears to succeed (no exception thrown, `configureChannel()` returns `"OK"`) but the config file on the workspace VPS (`/srv/sync360/runtime/tenants/{slug}/config/openclaw.json`) is **not updated** with the new content.

This means any call to `configureChannel()` that modifies `openclaw.json` and relies on `putFile` to push it to the VPS — including the new `webhookUrl` / `webhookSecret` fields — only updates the **local** copy on the control-app server, not the live workspace.

---

## How putFile Works

```
SshDockerComposeRunner::putFile($server, $remotePath, $contents)
  1. Write $contents to a local temp file (tempnam)
  2. SCP local temp file → /tmp/{uuid}-openclaw.json on VPS
  3. SSH: mkdir -p {dir} && install -m 0644 /tmp/{uuid}-openclaw.json {remotePath} && rm /tmp/{uuid}-openclaw.json
```

All three steps use `runLocalProcess()` with `throwOnFailure: true` — so a silent failure shouldn't be possible. Yet the remote file does not change.

---

## Suspected Root Causes

1. **`$tenant->runtime_path` mismatch** — The `remoteRuntimePath` resolved for `putFile` may differ from the **actual bind-mounted path** used by the compose stack. The compose.yaml for `sync360-style-software` binds:
   ```
   /srv/sync360/runtime/tenants/style-software → /home/node/.openclaw
   ```
   If `$tenant->runtime_path` in the DB is stale or has a different format (e.g., trailing slash, or different base path), `putFile` writes to a valid but unmounted location.

2. **SCP auth failure with silent fallback** — The SCP uses `sshpass` with the server's password. If the SCP command times out or the password is stale, `runLocalProcess` with `sshpass` may exit 0 (sshpass itself exits 0 even when SSH fails in some configurations).

3. **`install` permission denial** — If the `deploy` user doesn't have write permissions to `/srv/sync360/runtime/tenants/{slug}/config/`, the `install` command fails but `runSsh` may not propagate the error correctly.

---

## Evidence (original)

- `configureChannel()` completed with no exception (returned `"OK"` in Tinker)
- `cat /srv/sync360/runtime/tenants/style-software/config/openclaw.json` showed old content after `putFile` ran
- The **local** config file on the control app (`/var/www/html/runtime/tenants/style-software/config/openclaw.json`) also showed old content (no `webhookUrl`)
- Direct `array_filter` test in Tinker confirmed `webhookUrl` IS present in `$config` before the write
- Manual `python3` patch to the VPS directly DID work and OpenClaw picked it up correctly

## Resolution

The two-step SCP → remote `install` pattern introduced in `SshDockerComposeRunner::putFile()` fixed the issue:

1. Write content to a local `tempnam()` file
2. `scp` local temp → `/tmp/{uuid}-{filename}` on the remote VPS
3. SSH: `mkdir -p {dir} && install -m 0644 /tmp/{uuid} {dest} && rm -f /tmp/{uuid}`
4. Clean up local temp file in `finally` block

**Live test (2026-04-15)** against `litellm-live-1775898445` on `89.116.28.191`:

- MD5 before: `5556b91cd1d5d8e0290e7d9393d85771`
- Triggered `putFile()` via `php artisan tinker` inside production `control-app-app-1` container
- MD5 after: `fd72db2c8f252292a82a1f0a9a99cc85` — changed ✅
- New content (`putfile-verification-2026-04-15T10:18:26+00:00`) confirmed on VPS ✅
- No exception thrown, `runLocalProcess()` correctly propagated exit codes ✅

The `sshpass` exit-code masking risk does not manifest in the Docker production environment for this server/auth configuration.

---

## Workaround Applied (Current Tenant)

For `style-software` (tenant `01KP0JG8P5KA1ZMQCPD2X8GE2G`), patched manually:

```bash
# On workspace VPS (89.116.28.191 via deploy@)
python3 -c "
import json
path = '/srv/sync360/runtime/tenants/style-software/config/openclaw.json'
with open(path) as f: cfg = json.load(f)
cfg['channels']['telegram']['webhookUrl'] = 'https://app.sync360.co.nz/webhooks/telegram/01KP0JG8P5KA1ZMQCPD2X8GE2G'
cfg['channels']['telegram']['webhookSecret'] = 'e2n2pwSM3lTmjr2PMmn6kzGSP7WMgVQw'
with open(path, 'w') as f: json.dump(cfg, f, indent=2)
"
docker compose -f /srv/sync360/runtime/tenants/style-software/compose.yaml -p sync360-style-software restart
```

Result confirmed: OpenClaw restarted in webhook mode:
```
[telegram] webhook advertised to telegram on https://app.sync360.co.nz/webhooks/telegram/01KP0JG8P5KA1ZMQCPD2X8GE2G
```

---

## Impact

- **Future new tenants:** `configureChannel()` called during onboarding will NOT push `webhookUrl` to workspace → OpenClaw will start in polling mode → webhook will be overridden on every restart.
- `registerTelegramWebhook()` (the belt-and-suspenders `setWebhook` HTTP call) will still register the Telegram-side webhook URL, but OpenClaw will kill it with `deleteWebhook` on next restart.

---

## Files to Investigate

- [`SshDockerComposeRunner.php`](../app/Services/SshDockerComposeRunner.php) — `putFile()`, `runScpFile()`, `runCommand()`, `authPrefix()`
- [`TenantAgentSyncService.php`](../app/Services/TenantAgentSyncService.php) — `configureChannel()` (lines 188–200), `remoteRuntimePath` resolution
- [`TenantRuntimeService.php`](../app/Services/TenantRuntimeService.php) — `remoteRuntimePath()` implementation
- Tenant DB row — check `runtime_path` column value vs actual VPS path

## Debugging Steps

1. Add explicit logging before and after `putFile` in `configureChannel()` to confirm content and path
2. Check `$tenant->runtime_path` vs `$this->runtime->remoteRuntimePath($tenant)` for this tenant
3. Manually trace the SCP command that gets built: log the `runLocalProcess` command array
4. Check if `sshpass` exits 0 even on failure in the Docker container's version
