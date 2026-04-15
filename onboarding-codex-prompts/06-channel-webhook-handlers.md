# Codex Agent Prompt — 06: Channel Webhooks And Conversation Logging

> [!WARNING]
> Superseded historical prompt. The Telegram webhook path described here has been removed from the current codebase. Current canonical truth lives in [`artifacts/MEMORY.md`](../artifacts/MEMORY.md) and [`artifacts/ARCHITECTURE.md`](../artifacts/ARCHITECTURE.md). WhatsApp remains scaffolded for inbound logging and verification, not full production channel handling.

## Context

Once a customer is live, end-user messages should flow like this:

1. message arrives at Sync360 webhook
2. Sync360 identifies the tenant
3. Sync360 forwards the message to that tenant workspace
4. Sync360 sends the reply back on the same channel
5. Sync360 logs the conversation

This must fit the current platform:

- tenant public identifier is `tenants.tenant_id`
- workspace URL already exists on the tenant
- customer channel tokens live in `tenants.channel_config`

Do not rely on per-tenant SSH fields like `tenant.ssh_host`.

## Task

This file records the original webhook-first design direction for channels and conversation logging. It should not be used as the current implementation guide.

---

## Routes

Add public routes in `routes/web.php`:

```php
Route::get('/webhooks/whatsapp/{tenantId}', ...);
Route::post('/webhooks/whatsapp/{tenantId}', ...);
```

Route parameter should resolve against `tenants.tenant_id`.

Exclude `webhooks/*` from CSRF protection.

---

## Forwarding Model

Forward inbound messages to the tenant workspace through its existing public URL or configured gateway URL.

Suggested endpoint:

```php
$agentUrl = rtrim($tenant->workspace_url, '/') . '/chat';
```

If later needed, this path can become configurable, but do not hardcode tenant SSH host assumptions into the design.

---

## Conversation Log Schema

Create `conversation_logs` with fields such as:

```php
id
tenant_id
channel
external_message_id
from_identifier
message_in
message_out
meta_json
responded_at
created_at
updated_at
```

Cast `meta_json` as `array`.

---

## WhatsApp

Store in `channel_config`:

- `whatsapp_phone_number_id`
- `whatsapp_access_token`
- `whatsapp_verify_token`

### Verify route

Check:

- `hub.mode`
- `hub.verify_token`
- `hub.challenge`

against the stored tenant config.

### Handle route

- validate signature when possible
- ignore status-only payloads
- deduplicate by external message id
- dispatch a queued message-processing job
- always return quickly with `200`

---

## Job: `ProcessIncomingMessage`

Suggested responsibilities:

1. load fresh tenant
2. POST user message to tenant workspace `/chat`
3. extract reply text
4. send reply through the correct sender service
5. write `ConversationLog`

Suggested request body to workspace:

```php
[
    'message' => $this->messageText,
    'from' => $this->from,
    'channel' => $this->channel,
    'tenant_id' => $this->tenant->tenant_id,
]
```

On failure:

- log the error
- optionally send a short fallback reply
- do not lose the inbound message context

---

## Sender Services

Build:

- `App\Services\Channels\WhatsAppSender`
- `App\Services\Channels\TelegramSender`

These should read decrypted channel credentials from `tenant.channel_config`.

---

## Acceptance Criteria

- webhooks identify tenants using the existing tenant external id
- inbound messages are forwarded using the tenant workspace URL
- replies go back out on the same channel
- every handled conversation is logged
- no tenant-level SSH routing assumptions remain in the design
