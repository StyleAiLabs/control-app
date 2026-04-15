# Codex Agent Prompt — 07: Dashboard Integration For The Existing Blade App

## Context

The current app already has:

- `/dashboard`
- a Blade dashboard view
- tenant and provisioning data

We now need that dashboard to reflect onboarding progress, digital employee status,
and recent conversations.

Do not assume a separate frontend application. Extend the current controller and Blade view.

## Task

Wire the dashboard to real data and add the post-onboarding sections customers need.

---

## Controller

Extend `app/Http/Controllers/DashboardController.php`

Load:

- tenant
- business profile
- business profile files
- recent `ConversationLog` records
- onboarding status summary

Useful derived values:

- `trial_status`
- `provisioning_status`
- `agent_status`
- `channel`
- `tone`
- `capabilities`
- `last_synced_to_agent`
- total conversations
- conversations today
- conversations this week
- `resume_from_step`

You can render everything server-side on first load and add a small JSON summary endpoint later only if polling is needed.

---

## Dashboard Sections

### Top cards

Reuse the existing cards and back them with real data:

- trial
- workspace status
- skill pack
- industry

### Your Business

Show:

- business name
- industry
- skill pack
- contact details summary
- trial status

Add an edit action that opens profile editing.

### Digital Employee Status

Map status clearly:

- `offline`
- `deploying`
- `live`
- `failed`

If onboarding is incomplete, the primary CTA should send the customer back to the wizard.

### Setup Progress

If onboarding is incomplete, show a step tracker with:

- completed steps
- next required step
- button to continue setup

### Recent Conversations

If the tenant is live, show recent conversations with:

- channel
- who messaged
- inbound message excerpt
- outbound message excerpt
- time

If there are none yet, show an encouraging empty state.

---

## Profile Editing

Add profile routes under auth, for example:

- `GET /profile`
- `PATCH /profile`
- `POST /profile/sync-agent`

Updating business details should:

1. persist to `business_profiles`
2. optionally mirror high-level values back to `tenants`
3. trigger a background sync if the tenant is already live

This lets customers change things like:

- services
- hours
- phone
- contact email
- GST / tax number

without ever touching the underlying workspace directly.

---

## Acceptance Criteria

- `/dashboard` reflects real onboarding and conversation data
- incomplete onboarding shows a resume path
- live tenants can see recent conversations
- profile changes can resync to the tenant workspace
- implementation extends the current Blade dashboard rather than replacing it

