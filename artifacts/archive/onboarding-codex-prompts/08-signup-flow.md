# Codex Agent Prompt — 08: Extend The Existing Signup Flow

## Context

Sync360 already has a working Laravel signup flow:

- `GET /signup`
- `POST /signup`
- `RegisterController`
- user + tenant + provisioning job creation
- background workspace provisioning

Do not replace that working flow with a separate API-auth architecture unless there is a
very strong reason. Extend what is already there.

## Product Goal

After signup, the customer should land in the onboarding wizard, not in a technical setup flow.

Behind the scenes, infrastructure provisioning can keep running in the background.

## Task

Update the existing signup flow so it creates the onboarding records we need and redirects
new customers into the wizard.

---

## Existing Flow To Preserve

Keep these behaviours:

- create `User`
- create `Tenant`
- assign `Server`
- create `ProvisioningJob`
- dispatch queued provisioning
- log the user in with session auth

Do not remove the current provisioning pipeline.

---

## Extend Signup Transaction

Inside the existing `RegisterController@store`, add:

1. create `BusinessProfile`
2. create `BusinessProfileFiles`
3. initialize onboarding fields on `Tenant`

Suggested initial tenant values:

```php
onboarding_status = 'pending'
onboarding_step = 0
agent_status = 'offline'
tone = null
capabilities = null
channel = null
channel_config = null
```

Populate `BusinessProfile` with whatever is already known from signup:

- business name
- industry
- contact email
- contact phone
- owner name
- owner email
- owner phone

---

## Redirect Behaviour

Change the post-signup redirect target to:

```php
/onboarding
```

Practical note:

- if provisioning is still running, the onboarding page should still open
- only the final `Go Live` step should depend on `provisioning_status === ready`

This keeps the customer journey fast and simple while background infrastructure catches up.

---

## Customer Messaging

After signup, do not dump the customer into a low-level provisioning status page.

If you need to show progress, use language like:

`We're preparing your workspace in the background while we finish your setup.`

Avoid:

- `job queued`
- `container`
- `SSH`
- `runtime`

---

## Email

If you add or update a welcome email, it should point customers to continue setup in the wizard.

Example outcome:

- signup success
- customer is logged in
- customer lands on `/onboarding`
- welcome email reinforces `Complete setup`

---

## Acceptance Criteria

- current signup provisioning still works
- signup now creates `BusinessProfile` and `BusinessProfileFiles`
- new customers land in `/onboarding`
- onboarding and infrastructure provisioning can proceed in parallel
- no unnecessary switch to Sanctum/token auth is introduced

