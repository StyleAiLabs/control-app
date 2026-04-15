# Codex Agent Prompt — 04: Onboarding JSON Endpoints Inside The Laravel Monolith

## Context

The onboarding wizard lives inside the current Sync360 app, not a separate SPA.

Use:

- Blade page for `/onboarding`
- authenticated JSON endpoints in `routes/web.php`
- session auth
- CSRF protection

Assume prompts 01–03 are already in place.

## Task

Build the remaining onboarding endpoints and state helpers for the 6-step wizard.

---

## Route Group

Example structure:

```php
Route::middleware('auth')->prefix('onboarding')->group(function (): void {
    Route::get('/', ...)->name('onboarding.show');
    Route::get('/state', ...)->name('onboarding.state');
    Route::post('/extract-business', ...);
    Route::post('/business-info', ...);
    Route::post('/personality', ...);
    Route::post('/capabilities', ...);
    Route::post('/channel', ...);
    Route::post('/go-live', ...);
    Route::post('/skip', ...);
});
```

Return JSON for every route except the page route.

---

## Step 2 — Save Business Info

`POST /onboarding/business-info`

Validate and save editable business fields into `business_profiles`.

Also:

- copy high-level fields back to `tenants` where appropriate:
  - `business_name`
  - `industry`
- set:
  - `onboarding_status = in_progress`
  - `onboarding_step = max(current, 2)`

---

## Step 3 — Save Personality

`POST /onboarding/personality`

Validate:

```php
tone => ['required', Rule::in(['friendly', 'professional', 'formal', 'casual'])]
```

Save to:

- `tenants.tone`
- `business_profiles.tone_hint`

Set `onboarding_step = max(current, 3)`.

---

## Step 4 — Save Capabilities

`POST /onboarding/capabilities`

Validate capabilities array, save to `tenants.capabilities`, then generate the four markdown files via `BusinessExtractionService::generateAgentFiles()`.

Store output in `business_profile_files` and set:

- `onboarding_step = max(current, 4)`

Do not auto-go-live here. Generation and channel connection are separate concerns.

---

## Step 5 — Save Channel Connection

`POST /onboarding/channel`

Validate supported channels:

- WhatsApp
- Telegram

Store credentials in `tenants.channel_config` using encrypted cast storage.

Also save:

- `tenants.channel`
- `tenants.webhook_secret` if needed
- `onboarding_step = max(current, 5)`

Then call a `ChannelWebhookService` to register the webhook where applicable.

If registration fails, return `422` with a customer-friendly message.

---

## Step 6 — Go Live

`POST /onboarding/go-live`

Call the service from prompt 03.

If the tenant workspace is still provisioning, return `409`.

If accepted, queue the go-live job and return a polling response.

---

## State Endpoint

`GET /onboarding/state`

Return:

- `onboarding_step`
- `onboarding_status`
- `provisioning_status`
- `agent_status`
- `resume_from_step`
- step labels/statuses
- current saved business data
- saved tone
- saved capabilities
- saved channel

### Step completion rules

Use data presence, not just a counter:

- Step 1 complete:
  - `website_url` exists, or business info was manually saved
- Step 2 complete:
  - `business_name`, `description`, and at least one `service`
- Step 3 complete:
  - `tone` exists
- Step 4 complete:
  - `capabilities` exists and `business_profile_files.generated_at` exists
- Step 5 complete:
  - `channel` and required channel config exist
- Step 6 complete:
  - `agent_status === live`

Set `resume_from_step` to the first incomplete step.

---

## Skip Endpoint

`POST /onboarding/skip`

This should:

- preserve all saved data
- set `onboarding_status = in_progress`
- return the latest `resume_from_step`

---

## Practical Notes

- the wizard must be resumable without relying on local browser state
- the JSON contract should support a Blade + small-JS frontend
- do not introduce Sanctum just for onboarding if session auth already works

## Acceptance Criteria

- the current authenticated user can progress through all 6 steps
- progress survives page close/reopen
- the state endpoint correctly reflects saved progress
- go-live only succeeds once the workspace itself is provisioned and ready

