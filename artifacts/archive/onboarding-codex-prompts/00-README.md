# Sync360 Onboarding Wizard — Practical Prompt Pack

> [!IMPORTANT]
> Archived historical build scaffold only. This prompt pack is superseded by the implemented code and the canonical docs in [`artifacts/MEMORY.md`](../../MEMORY.md) and [`artifacts/ARCHITECTURE.md`](../../ARCHITECTURE.md). Use current code under `app/`, `routes/`, and `resources/views/` for implementation truth.

## Purpose

This prompt pack records how the onboarding system was originally decomposed for implementation.
It is retained as historical build scaffolding, not as current guidance for how the system works today.

The customer experience stays simple:

1. Sign up
2. Tell us about your business
3. Choose how your digital employee should sound
4. Choose what it should handle
5. Connect WhatsApp or Telegram
6. Go live

The implementation must fit the platform we already have:

- Laravel monolith
- Blade views + session auth
- existing signup and tenant provisioning flow
- control-plane-managed tenant runtime sync
- server-based placement through `servers`
- remote OpenClaw runtimes provisioned by the control plane

Do not design this as a separate SPA, separate frontend repo, or per-tenant SSH product.

## Product Rules

- Customers should never see internal terms like `OpenClaw`, `LiteLLM`, `runtime`, `SSH`, or `deploy`.
- Customer-facing copy should use terms like `digital employee`, `setup`, `business details`, `connect WhatsApp`, and `go live`.
- Internal services, jobs, and files can still use technical names when appropriate.

## Architecture Rules

- Keep the current marketing-site signup flow in Laravel.
- Keep Laravel session auth as the primary auth model.
- Build the onboarding wizard inside the existing app layout.
- Use authenticated JSON endpoints in `routes/web.php` for wizard save actions.
- Reuse the existing provisioning model:
  - signup creates user + tenant + provisioning job
  - control plane assigns a `Server`
  - runtime is staged locally
  - runtime syncs to the assigned client VPS
  - tenant workspace URL comes from the assigned server
- Do not store tenant-specific SSH credentials on the `tenants` table.
- Server SSH and runtime settings belong to `servers`, not tenants.

## Historical Build Order

| # | Prompt File | What It Builds |
|---|-------------|----------------|
| 01 | `01-business-profile-schema.md` | Business profile schema + onboarding state |
| 02 | `02-litellm-extraction-service.md` | Website extraction + file generation services |
| 03 | `03-profile-md-writer.md` | Runtime file sync + go-live service using existing server/runtime model |
| 04 | `04-onboarding-wizard-api.md` | Authenticated onboarding JSON endpoints inside the monolith |
| 05 | `05-frontend-onboarding-wizard.md` | Blade-first 6-step onboarding wizard |
| 06 | `06-channel-webhook-handlers.md` | Historical webhook-oriented channel plan, now superseded |
| 07 | `07-dashboard-integration.md` | Dashboard status, onboarding progress, recent conversations |
| 08 | `08-signup-flow.md` | Extend current signup flow without replacing it |
| 09 | `09-tenant-ssh-provisioning.md` | Server admin + tenant health/resync operations |

## Historical Scope Snapshot

### Signup

- Extend the existing `POST /signup` flow
- Create:
  - `User`
  - `Tenant`
  - `BusinessProfile`
  - `BusinessProfileFiles`
  - `ProvisioningJob`
- Redirect new customers to `/onboarding`
- Let infrastructure provisioning continue in the background

### Onboarding

- `GET /onboarding`
- `GET /onboarding/state`
- `POST /onboarding/extract-business`
- `POST /onboarding/business-info`
- `POST /onboarding/personality`
- `POST /onboarding/capabilities`
- `POST /onboarding/channel`
- `POST /onboarding/go-live`
- `POST /onboarding/skip`

All of the above should live behind `auth` middleware and return JSON except the page route.

### Profile

- `GET /profile`
- `PATCH /profile`
- `POST /profile/sync-agent`

### Dashboard

- Extend the existing `/dashboard` Blade page
- Add recent conversations, onboarding progress, and digital employee status

### Admin

- Extend the existing local-only admin area
- Add server configuration, tenant health checks, and tenant resync actions
- Keep current `is_admin` + `local.only` protection unless explicitly changed later

### Webhooks

- `GET /webhooks/whatsapp/{tenant_id}`
- `POST /webhooks/whatsapp/{tenant_id}`

Use the existing external tenant identifier (`tenants.tenant_id`) instead of inventing a second public UUID.

## Core Models

- `User`
- `Tenant`
- `Server`
- `ProvisioningJob`
- `BusinessProfile`
- `BusinessProfileFiles`
- `ConversationLog`

## Key Services

- `BusinessExtractionService`
- `AgentProfileSyncService` or `AgentDeploymentService`
- `ChannelWebhookService`
- `TenantHealthCheckService`
- `WhatsAppSender`
- `TelegramSender`

## Important Practical Notes

> [!NOTE]
> Some channel and webhook details in this pack no longer match the implemented system. Telegram webhook handling has been removed from the control plane, and WhatsApp remains scaffolded rather than fully integrated end to end.

### 1. Signup and onboarding are separate concerns

Signup should continue creating the tenant and kicking off workspace provisioning.
The onboarding wizard should configure the already-provisioned workspace, not replace the provisioning flow.

### 2. Workspace readiness can lag behind wizard progress

The customer can complete Steps 1–5 while provisioning is still finishing in the background.
The final `Go Live` action should:

- check whether `provisioning_status === ready`
- queue file sync/restart if ready
- otherwise return a friendly waiting message and keep polling

### 3. LiteLLM has two different jobs

- Sync360-level extraction/file generation should use the Sync360 platform virtual key.
- Tenant workspace provisioning should continue using the existing management flow for tenant LiteLLM keys.

Do not collapse those two concerns into one credential model.

### 4. Use the runtime already created by provisioning

Generated files should be written into the tenant runtime prepared by the control plane and then synced to the assigned server.
Do not assume each tenant has its own SSH host or private key.

### 5. Keep the UI simple

The customer sees:

- Business Website
- Business Info
- Personality
- Capabilities
- Channel
- Go Live

The customer should not see:

- file names
- deployment terminology
- SSH
- server names
- provisioning jobs
- LiteLLM
