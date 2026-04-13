# Sync360 Control App Memory

This file is the durable project memory for the Sync360 Control App as of `2026-04-14`.

It is meant to help a new thread recover the important context quickly without losing the engineering decisions, deployment model, branch history, and verified behaviors we established together.

Use this alongside:

- [ARCHITECTURE.md](/Users/gayanhewage/Projects/openclaw-saas/ARCHITECTURE.md)
- [RELEASE_NOTES.md](/Users/gayanhewage/Projects/openclaw-saas/RELEASE_NOTES.md)
- [README.md](/Users/gayanhewage/Projects/openclaw-saas/README.md)

## 1. What This Project Is

Sync360 Control App is a Laravel-based control plane for provisioning and managing multi-tenant OpenClaw workspaces.

It currently supports:

- public landing page and signup flow
- custom auth with admin support
- user -> tenant -> provisioning job creation
- async provisioning through Laravel queues
- per-tenant OpenClaw deployments
- remote client-VPS provisioning over SSH
- tenant runtime file generation
- tenant-specific LiteLLM virtual key provisioning
- workspace-ready email sending through Brevo
- super-admin control-plane deployment from the admin UI

The current product goal is still MVP validation, but the codebase has now evolved into a practical production-ready control plane for:

- a primary control server
- one or more client workspace servers

## 2. Current Branch + Repo State

Primary working deployment branch:

- `codex/control-app-prod-deploy`

Active feature/hotfix branch:

- `cdx-feature/hot-fixes` (merged into `codex/control-app-prod-deploy`)

Important recent commits on the deployment branch:

- `a3b15e3` `feat: trial lifecycle management — expiry enforcement, AI usage widget, email notifications`
- `895b9b1` `feat: conversations and dashboard channel-aware connected state indicators`
- `478a90c` `feat: onboarding UX polish — owner↔assistant copy, clipboard webhooks, step labels`
- `f650484` `fix: redirect signup to /onboarding instead of /tenant/setup`
- `cbb8050` `Run latest fetched deploy script from UI`

Important note:

- a temporary git worktree was used during deploy-flow hardening at `/private/tmp/sync360-deploy-fix`
- the actual repo root remains `/Users/gayanhewage/Projects/openclaw-saas`

## 3. High-Level Architecture

### Control Plane

The control plane is a Laravel monolith with:

- `app`
- `worker`
- `postgres`
- `redis`

It owns:

- user signup
- tenant creation
- provisioning job dispatch
- admin monitoring
- remote client-VPS provisioning
- control-plane self-deploy trigger

### Tenant Runtime

Each tenant gets:

- its own runtime directory
- its own OpenClaw deployment
- its own LiteLLM virtual key
- its own workspace URL

No tenant shares an OpenClaw instance or LiteLLM key.

## 4. Deployment Topology We Settled On

### Primary control server

- host: `161.97.74.128`
- public app URL: `app.sync360.co.nz`
- current deployment path on server: `/opt/sync360/control-app`
- host user used by the UI deploy flow: `serveradmin`

### Client workspace VPS

- host: `89.116.28.191`
- tenant wildcard domain: `*.workspace.sync360.co.nz`
- remote runtime root: `/srv/sync360/runtime`
- current provisioning user: `deploy`
- current SSH mode: password auth

### Reverse proxy

- Caddy runs on the client VPS
- tenant URLs are served over HTTPS through Caddy
- per-tenant OpenClaw containers bind to loopback high ports on the client VPS
- Caddy routes hostname -> tenant loopback port

### Important current reality

Password-based SSH is still being used temporarily for both:

- client VPS provisioning
- control-plane deploy trigger

This works, but it is explicitly temporary and should be replaced with SSH keys.

## 5. Core Runtime Flow

### Signup and provisioning flow

1. Visitor lands on landing page.
2. Visitor signs up (`/signup`).
3. App creates:
   - `User`
   - `Tenant`
   - `ProvisioningJob`
   - `BusinessProfile`
   - `BusinessProfileFiles`
4. User is logged in immediately and redirected to `/onboarding`.
5. Queue worker picks up provisioning in the background (the owner can complete onboarding while provisioning runs).
6. Tenant is assigned to a `Server`.
7. Runtime files are generated locally under:
   - `runtime/tenants/<slug>/`
8. LiteLLM tenant key is generated before startup.
9. Runtime is copied to the assigned client VPS.
10. Tenant-specific OpenClaw container is started remotely with Docker Compose.
11. Readiness is checked.
12. Public hostname is checked.
13. Tenant is marked `ready`.
14. Workspace-ready email is sent.

If provisioning fails at any point:

- job is marked failed
- tenant is marked failed
- error message is stored
- admin can retry

### LiteLLM flow

Before starting a tenant instance:

- app calls LiteLLM `/key/generate`
- generated tenant key is stored encrypted on the tenant
- `OPENAI_API_KEY` is injected into:
  - tenant `.env`
  - tenant `compose.yaml`
- `OPENAI_BASE_URL` is set to the LiteLLM proxy URL

Lifecycle rules implemented:

- generate on provisioning
- update budget on plan change
- suspend by setting budget to zero
- delete on cancellation

Important rule:

- provisioning must abort if LiteLLM key generation fails
- do not start OpenClaw without a valid tenant LiteLLM key

### Workspace-ready email flow

After successful provisioning:

- app sends a professional workspace-created email using Brevo
- email includes:
  - workspace URL
  - username
  - initial password

Behavior:

- password is stored encrypted in provisioning job payload until used
- it is removed after successful email send
- Brevo failure does not tear down an already-ready workspace

## 6. Admin / Super Admin Capabilities

Super admin can currently:

- access `/admin`
- view users
- view tenants
- view jobs
- retry provisioning
- start/stop tenant workspace containers
- trigger control-plane deployments
- watch deploy state live
- see deployed commit SHA and commit message

Admin deploy panel now supports:

- live polling
- current deployed commit
- current branch-tip comparison
- `Up-to-date` state when deployed commit matches branch tip
- `Fetch Latest And Deploy` when branch tip is newer
- `Deploying...` while a deploy is running

## 7. Control-Plane Deploy Architecture

This area was tricky and important.

### Original problem

The control-plane deploy button was trying to update the same repo whose host-side deploy script was already running from disk.

That created a self-update trap:

- UI said deploy succeeded
- but the running deploy script itself was still the older version
- latest commit and deploy state could drift or lie

### Final working design

The deploy flow now works like this:

1. UI calls Laravel deploy trigger.
2. Laravel SSHes to the primary server.
3. Trigger fetches the target branch first.
4. Trigger executes the deploy script content directly from `FETCH_HEAD`.
5. Host-side deploy script resolves the target remote branch head.
6. Script fast-forwards local branch to `FETCH_HEAD`.
7. Script verifies local `HEAD` exactly matches intended remote commit.
8. Only then does it rebuild containers and run Laravel maintenance commands.
9. Script writes status + deployed commit metadata to a status file.
10. Admin panel reads that status file and compares deployed commit vs branch tip.

This is the important lesson:

- the deploy script must not trust the already-checked-out copy on disk when self-updating
- it must bootstrap from freshly fetched branch content

### Required Laravel commands during deploy

The deploy script runs:

```bash
docker compose -f docker-compose.prod.yml up --build -d
docker compose -f docker-compose.prod.yml exec -T app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec -T app php artisan optimize:clear
docker compose -f docker-compose.prod.yml exec -T app php artisan optimize
```

Those cache-clear / optimize steps are intentionally part of the deploy path.

## 8. What Has Been Verified

### Local MVP verification

Verified:

- signup works
- user, tenant, and provisioning job are created
- queue worker processes provisioning
- tenant becomes ready
- workspace-ready pages work
- admin pages work

### VPS provisioning verification

Verified:

- remote tenant provisioning to `89.116.28.191`
- runtime directories created on client VPS
- remote Docker Compose startup works
- Caddy routing works
- public HTTPS tenant hostname works
- tenant can be marked `ready`

### LiteLLM verification

Live verification completed:

- a real tenant was provisioned
- a real LiteLLM tenant key was generated
- key was stored on the tenant
- key was injected into runtime `.env`
- key was injected into tenant `compose.yaml`
- OpenClaw instance came up successfully with that key

### Brevo verification

Verified:

- Brevo accepted workspace-ready emails
- sender was switched to `hello@sync360.co.nz`
- professional email content was updated

### Control-plane deploy verification

Verified in the end:

- UI deploy can bring production branch current
- panel can show correct deployed commit
- panel can show `Up-to-date`
- panel can compare deployed commit vs branch tip

Current healthy expected state in UI:

- deploy state: `succeeded`
- button: `Up-to-date`
- latest commit: `cbb8050`
- commit message: `Run latest fetched deploy script from UI`

## 9. Important Gotchas We Already Learned

### 1. Self-updating deploy scripts are dangerous

The control-plane deploy script must bootstrap from fetched branch content, not from the stale checked-out file already on disk.

### 2. Deploy success is meaningless without commit verification

A deploy should not be considered successful unless:

- remote branch tip was resolved
- local branch fast-forwarded
- local `HEAD` equals the expected remote commit

### 3. Production `.env` is not auto-updated

Git deploys update code only.

Any new or changed env variables must still be updated manually on the server.

### 4. Password-auth SSH is only a temporary bridge

It works right now, but it should be replaced with SSH keys.

### 5. Secrets were shared in-thread

Multiple secrets were pasted during setup:

- SSH passwords
- Brevo API key
- LiteLLM master key

Do not preserve them in docs or code.

Treat them as compromised and rotate them.

## 10. Channel Scope — Phase 1 Design Decision

**Critical context for any future work involving channel copy, feature design, or UX:**

In Phase 1 of Sync360, the Telegram and WhatsApp channel connection is **strictly owner↔assistant**.

- The business owner connects their own personal Telegram or WhatsApp to their digital employee
- Messages on that channel flow between the **business owner** and the **AI digital employee only**
- There are **no public-facing end customers** messaging through the channel in this phase
- `ConversationLog` records are owner-initiated sessions, not inbound customer queries
- All copy, UI labels, help text, and onboarding instructions must reflect this — never say "customers will message you" or "your assistant will reply to customers"

This decision is confirmed and intentional for Phase 1. Future phases may add a true customer-facing channel, but that is a separate engineering scope and must be explicitly planned.

---

## 11. Known Current State / Open Work

These are the main future areas, not active failures:

- move fully from password SSH auth to SSH key auth
- keep production `.env` synchronized with new required variables
- possibly merge `codex/control-app-prod-deploy` back to `main` when stable
- eventually deploy the control app cleanly as the permanent control plane on `161.97.74.128`
- add a safer env/config drift check in admin UI
- further harden operational monitoring and rollback paths

## 11. Practical Commands Worth Remembering

### Manual bootstrap deploy on control server

```bash
cd /opt/sync360/control-app
git fetch origin
git checkout codex/control-app-prod-deploy
git pull --ff-only origin codex/control-app-prod-deploy
docker compose -f docker-compose.prod.yml up -d --build --force-recreate app worker
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec app php artisan optimize:clear
docker compose -f docker-compose.prod.yml exec app php artisan optimize
```

### Check deployed branch commit manually

```bash
cd /opt/sync360/control-app
git rev-parse --short HEAD
git rev-parse --short origin/codex/control-app-prod-deploy
```

### Expected result when fully current

Both should match.

## 13. Suggested New-Thread Prompt

If starting a fresh thread, say something like:

> Read [MEMORY.md](/Users/gayanhewage/Projects/openclaw-saas/artifacts/MEMORY.md), [ARCHITECTURE.md](/Users/gayanhewage/Projects/openclaw-saas/artifacts/ARCHITECTURE.md), and [RELEASE_NOTES.md](/Users/gayanhewage/Projects/openclaw-saas/artifacts/RELEASE_NOTES.md) first. We are on `codex/control-app-prod-deploy` (latest commit `a3b15e3`). Phase 1 channel communication is strictly owner↔assistant. Trial lifecycle (14 days / $5) is fully implemented. Continue from there.

---

## 14. Trial Lifecycle Quick Reference

**Two expiry conditions (first wins):**
- Time: 14 days from `created_at` (UTC)
- Budget: LiteLLM spend ≥ `litellm_max_budget` (default $5)

**Scheduler:** `sync360:check-trial-expiry` every 30 min

**Emails (all idempotent via guard columns):**
- 80% budget used → budget warning
- ≤ 3 days left → time warning
- Either expired → expired email + `suspendTenant()`

**Expired state:** `trial_status = trial_expired`, LiteLLM budget = 0, Go Live disabled, dashboard banner shown

**Upgrade path (Phase 1):** `mailto:hello@sync360.co.nz` — no self-serve billing yet

**Key files:**
- `app/Services/TrialNotificationEmailService.php`
- `app/Services/LiteLlmTenantKeyService.php::getKeyInfo()`
- `app/Models/Tenant.php` (accessors: `isTrialExpired`, `trialDaysLeft`, `trialBudgetPercent`, `trialTimePercent`, `trialUrgency`)
- `routes/console.php` (command + schedule)
- Migration: `2026_04_13_123342_add_trial_and_spend_fields_to_tenants`
