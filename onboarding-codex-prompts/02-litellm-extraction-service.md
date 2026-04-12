# Codex Agent Prompt — 02: LiteLLM Extraction And File Generation Service

## Context

During onboarding, the customer provides a website URL. Sync360 should:

1. analyze the website using Claude via LiteLLM
2. pre-fill the business-info step
3. later generate the markdown files needed by the customer's digital employee

This must fit the current Laravel monolith and session-auth flow.

## Important Distinction

This service is for Sync360-level AI tasks:

- website understanding
- file generation

It should use the Sync360 platform virtual key.

This is separate from the existing tenant-key provisioning flow used by `LiteLlmTenantKeyService`.

## Task

Build `BusinessExtractionService` and wire it to an authenticated JSON endpoint inside the current app.

---

## Service: `app/Services/BusinessExtractionService.php`

### Method: `extractFromUrl(string $url): array`

Make an OpenAI-compatible request to:

```text
POST {LITELLM_BASE_URL}/chat/completions
Authorization: Bearer {LITELLM_VIRTUAL_KEY}
```

Use a low temperature and require strict JSON output.

Return structured fields including:

- `business_name`
- `trading_name`
- `tagline`
- `description`
- `industry`
- `services`
- `target_customers`
- `tone_hint`
- `contact_email`
- `contact_phone`
- `contact_mobile`
- `physical_address`
- `city`
- `country`
- `business_hours`
- `faqs`
- `pricing_notes`

If parsing fails, throw a domain exception such as `ExtractionFailedException`.

### Method: `generateAgentFiles(array $profile, string $tone, array $capabilities): array`

Return JSON with exactly these keys:

- `identity`
- `soul`
- `user`
- `bootstrap`

These strings should be customer-business-aware but still safe and concise.

The generated files are internal implementation artifacts. Do not expose file names in customer-facing copy.

---

## Route Style

Do not assume Sanctum or a separate SPA.

Add JSON endpoints under authenticated `web.php` routes, for example:

```php
Route::middleware('auth')->prefix('onboarding')->group(function (): void {
    Route::post('/extract-business', ...);
});
```

Use normal session auth + CSRF protection.

---

## Controller

Create something like:

- `app/Http/Controllers/Onboarding/BusinessExtractionController.php`

### `extract(Request $request)`

Validate:

```php
url => ['required', 'url', 'max:500']
```

Then:

1. load the authenticated user's tenant
2. call `BusinessExtractionService::extractFromUrl()`
3. upsert data into `business_profiles`
4. persist:
   - `website_url`
   - `website_extracted_at`
   - `website_extraction_raw`
   - extracted editable fields
5. set:
   - `tenants.onboarding_status = in_progress`
   - `tenants.onboarding_step = max(current, 1)`

Return JSON shaped for Step 2 prefill.

---

## Practical Notes

- save extraction results immediately so the user can leave and return later
- if extraction fails, return a friendly message and allow manual entry
- do not block the onboarding flow on perfect extraction quality
- generation should be triggered after capabilities are saved, not during Step 1

## Acceptance Criteria

- authenticated customers can submit a website URL from the current app
- extracted data is persisted to `business_profiles`
- generation method returns valid markdown payloads for the 4 files
- failures produce a recoverable manual-entry path

