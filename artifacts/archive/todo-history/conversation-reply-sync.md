# Feature: Sync Conversation Replies from OpenClaw Session Logs

> [!IMPORTANT]
> Historical planning note. Canonical current truth is in [`artifacts/MEMORY.md`](../../MEMORY.md) and [`artifacts/ARCHITECTURE.md`](../../ARCHITECTURE.md). Telegram webhook flow described in older docs is no longer current, and WhatsApp integration references in historical material may be planned or scaffolded rather than fully implemented.

**Status:** Planned  
**Priority:** High — causes "No reply" to show on all conversations in the dashboard  
**Discovered:** 2026-04-13 while debugging conversation history  
**Related:** `putfile-remote-write-bug.md` ✅ (resolved 2026-04-15 — live test confirmed putFile writes correctly to VPS)

---

## Problem

The Conversations dashboard shows **"No reply"** on every session, even when the bot fully replied on Telegram. The `ConversationLog.message_out` column is always `null`.

### Root Cause

`ProcessIncomingMessage` job calls `TenantWorkspaceMessenger::send()` to forward the message to OpenClaw and capture its reply. That call does:

```
SSH → workspace VPS → curl POST http://127.0.0.1:4100/chat
```

**OpenClaw has no `/chat` HTTP endpoint.** It returns 404. The catch block swallows the exception and sets `$reply = null`. The log is created with `message_out = null`.

OpenClaw handles the message and reply **entirely on its own** (via polling or its own webhook processing), saving the full conversation to session memory files at:

```
/home/node/.openclaw/.openclaw/workspace/memory/YYYY-MM-DD-session-name.md
```

These files contain both the incoming message and the AI reply with message IDs we can cross-reference.

### Example session log format

```markdown
# Session: 2026-04-13 14:39:11 UTC

- **Session Key**: agent:main:main
- **Session ID**: daded3a9-5782-4415-a5a7-f2498e091bba
- **Source**: telegram

## Conversation Summary

user: Conversation info (untrusted metadata):
{"message_id": "46", "sender_id": "8699995227", ...}

What your business?
assistant: Hello Gayan! I'm Style...

user: {"message_id": "48", ...}
Can you do automation work?
assistant: Yes, we offer automation services...
```

The `message_id` in the metadata corresponds to `ConversationLog.external_message_id`.

---

## Proposed Fix

### Option A — Read session logs (recommended, build first)

Build an Artisan command `sync:conversation-replies {tenant}` that:

1. SSHes into the workspace VPS for the tenant
2. Lists all `*.md` files under `/home/node/.openclaw/.openclaw/workspace/memory/`
3. Parses each file to extract `message_id` → `assistant reply` pairs (regex on the markdown structure)
4. For each pair, finds the matching `ConversationLog` record by `external_message_id`
5. If `message_out` is null, updates it with the assistant reply and sets `responded_at = now()`

**Also:** Register the command as a scheduled job (e.g., every 5 minutes) so replies are backfilled automatically without manual intervention.

### Option B — Forward raw Telegram update to OpenClaw (proper long-term fix)

When the webhook controller receives a Telegram update, forward the raw JSON body to OpenClaw's internal HTTP server so it processes and replies, then capture the reply.

**Blocker:** OpenClaw's internal endpoint for receiving raw Telegram webhook updates is unknown. The gateway binds to `0.0.0.0:18789` but `/channels/telegram`, `/telegram`, `/chat` all return 404. Need to inspect `channel.runtime-*.js` in the container to find the correct endpoint.

If this endpoint exists, the flow would be:
```
Telegram → control-app webhook → log incoming → forward raw payload to OpenClaw gateway
→ OpenClaw processes + replies via Telegram API
→ control-app reads reply from OpenClaw response (if it returns one)
```

---

## Files to Create / Modify

### New: `app/Console/Commands/SyncConversationReplies.php`

```php
artisan sync:conversation-replies {tenant_id?} {--all}
```

- Accepts a single `tenant_id` or `--all` for all live tenants
- Calls a service method (see below)
- Outputs a summary: X logs updated

### New: `app/Services/WorkspaceSessionLogReader.php`

Responsible for:
- SSHing into workspace (uses `SshDockerComposeRunner` or direct SSH)
- Reading and parsing session memory markdown files
- Returning structured reply data: `[external_message_id => reply_text]`

### Modify: `app/Console/Kernel.php`

Register the command to run every 5 minutes (or webhook-triggered):
```php
$schedule->command('sync:conversation-replies --all')->everyFiveMinutes();
```

### Modify: `app/Jobs/ProcessIncomingMessage.php`

Remove or guard the `TenantWorkspaceMessenger::send()` call — since OpenClaw handles its own replies, this always fails with 404. The job should only:
1. Deduplicate check
2. Create `ConversationLog` with `message_out = null`
3. The scheduled sync command backfills replies async

---

## Session Log Path

```
Container path: /home/node/.openclaw/.openclaw/workspace/memory/
VPS host path:  /srv/sync360/runtime/tenants/{slug}/.openclaw/workspace/memory/
```

Files confirmed on live workspace (`style-software`):
- `2026-04-12-1205.md`
- `2026-04-12-401-incorrect-api-key-provided.md`
- `2026-04-12-session-startup.md`
- `2026-04-13-automation-inquiry.md`

---

## Parsing Logic (approx.)

```php
// Extract message_id → reply pairs from a session .md file
// Pattern: "message_id": "NNN" ... \nassistant: <reply text>\n

preg_match_all(
    '/"message_id":\s*"(\d+)".*?assistant:\s*(.+?)(?=\nuser:|\Z)/s',
    $content,
    $matches
);
// $matches[1] = message_ids, $matches[2] = assistant replies
```

---

## Quick Win (manual backfill right now)

To update the 3 existing null-reply logs for `style-software`, connect to the workspace and read the session files, then run in Tinker:

```php
// After parsing memory files manually:
ConversationLog::where('tenant_id', 1)
    ->whereNull('message_out')
    ->where('external_message_id', '56')
    ->update(['message_out' => 'The reply text here', 'responded_at' => now()]);
```
