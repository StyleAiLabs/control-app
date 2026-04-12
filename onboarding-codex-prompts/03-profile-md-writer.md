# Codex Agent Prompt — 03: Runtime File Sync And Go-Live Service

## Context

Sync360 does not give each tenant their own SSH credentials.

The current platform already:

- provisions a tenant onto an assigned `Server`
- prepares a local runtime under `runtime/tenants/<slug>/`
- syncs that runtime to the assigned client VPS
- starts the tenant workspace remotely

The onboarding flow must build on that model.

After onboarding Steps 1–5 complete, Sync360 should:

1. render the business/profile markdown files
2. write them into the tenant runtime prepared by the control plane
3. sync the updated runtime to the assigned server
4. restart or refresh the tenant workspace
5. confirm the workspace is healthy
6. mark the digital employee `live`

## Task

Build a practical go-live service for the current architecture.

You may keep the name `AgentDeploymentService` if you want, but the implementation must
use `TenantRuntimeService`, the assigned `Server`, and the existing `DockerComposeRunner`
abstractions rather than tenant-level SSH credentials.

---

## Service Responsibilities

Suggested service name:

- `app/Services/AgentProfileSyncService.php`

### Method: `goLive(Tenant $tenant): void`

Steps:

1. load:
   - `businessProfile`
   - `businessProfileFiles`
   - `server`
2. confirm:
   - tenant has an assigned server
   - tenant `provisioning_status` is already `ready`
   - tone, capabilities, and channel are present
3. render:
   - `PROFILE.md`
   - `HEARTBEAT.md`
4. persist rendered content to `business_profile_files`
5. write files into the tenant runtime, for example under:
   - `runtime/tenants/<slug>/workspace/PROFILE.md`
   - `runtime/tenants/<slug>/workspace/IDENTITY.md`
   - `runtime/tenants/<slug>/workspace/SOUL.md`
   - `runtime/tenants/<slug>/workspace/USER.md`
   - `runtime/tenants/<slug>/workspace/BOOTSTRAP.md`
   - `runtime/tenants/<slug>/workspace/HEARTBEAT.md`
6. sync the updated runtime to the assigned server
7. restart or re-`up` the tenant service on that server
8. verify readiness using the existing workspace URL
9. update tenant status fields

If anything fails, mark `agent_status = failed` and store a useful failure message.

---

## File Rendering

### `PROFILE.md`

Generate from `BusinessProfile` and keep it factual:

- business name
- website
- description
- services
- contact details
- owner/operator details
- tax / company details if present
- business hours
- after-hours policy

### `HEARTBEAT.md`

Generate as a Sync360-managed file with:

- tenant external id
- slug
- server name
- skill pack
- tone
- channel
- last synced timestamp

This file is internal. Customers should never edit it directly.

---

## Queue Job

Create a queued job such as:

- `GoLiveTenantAgent`

Suggested behaviour:

```php
public function handle(AgentProfileSyncService $service): void
{
    $service->goLive($this->tenant->fresh());
}
```

Queue it separately from signup provisioning if helpful.

---

## Route

Use a customer-friendly endpoint, for example:

```php
POST /onboarding/go-live
```

Protected by `auth`.

### Behaviour

- if `provisioning_status !== ready`, return `409` with a friendly message like:
  `"We're still preparing your workspace. This usually takes less than a minute."`
- otherwise:
  - set `agent_status = deploying`
  - dispatch the go-live job
  - return success JSON for polling

---

## Status Updates On Success

Update tenant:

```php
onboarding_status   = 'complete'
onboarding_step     = 6
agent_status        = 'live'
agent_last_synced_at = now()
```

Update `business_profiles.last_synced_to_agent = now()`

Update `business_profile_files.synced_at = now()`

---

## Acceptance Criteria

- no tenant-level SSH credentials are introduced
- service reuses the current runtime + server placement model
- go-live works against already-provisioned workspaces
- readiness is verified through the tenant workspace URL or existing infra runner
- tenant is marked `live` only after successful sync and health verification

