# Codex Agent Prompt — 01: Business Profile Schema For The Current Control App

## Context

Sync360 already has:

- `users`
- `tenants`
- `servers`
- `provisioning_jobs`

We now need the business-data layer that powers the onboarding wizard and later profile editing.

This must extend the current schema rather than replacing it.

## Task

Create:

1. `business_profiles` table + model
2. `business_profile_files` table + model
3. additional onboarding/channel status fields on `tenants`

Do not redesign signup ownership or provisioning tables. Build on what already exists.

---

## 1. Migration: `create_business_profiles_table`

Create a one-to-one profile table keyed by `tenant_id`.

Suggested columns:

```php
id                      bigint unsigned PK
tenant_id               foreignId unique constrained cascadeOnDelete

business_name           string nullable
trading_name            string nullable
website_url             string(500) nullable
industry                string nullable
description             text nullable
tagline                 string(500) nullable

contact_email           string nullable
contact_phone           string(50) nullable
contact_mobile          string(50) nullable
physical_address        text nullable
postal_address          text nullable
city                    string(100) nullable
country                 string(100) nullable default 'New Zealand'

tax_number              string(100) nullable
company_reg_number      string(100) nullable

owner_name              string nullable
owner_email             string nullable
owner_phone             string(50) nullable

business_hours          json nullable
after_hours_policy      string(255) nullable
primary_language        string(50) nullable default 'English'

services                json nullable
faqs                    json nullable
target_customers        text nullable
tone_hint               string(50) nullable
pricing_notes           text nullable

website_extracted_at    timestamp nullable
website_extraction_raw  json nullable

profile_completeness    unsignedTinyInteger default 0
last_synced_to_agent    timestamp nullable

timestamps
```

### Model: `app/Models/BusinessProfile.php`

- cast `business_hours`, `services`, `faqs`, and `website_extraction_raw` as `array`
- relation: `belongsTo(Tenant::class)`
- include a computed completeness helper based on key onboarding fields

---

## 2. Migration: `create_business_profile_files_table`

We need a durable store for the generated markdown files before and after go-live sync.

Suggested columns:

```php
id                    bigint unsigned PK
tenant_id             foreignId unique constrained cascadeOnDelete

identity_markdown     longText nullable
soul_markdown         longText nullable
user_markdown         longText nullable
bootstrap_markdown    longText nullable
profile_markdown      longText nullable
heartbeat_markdown    longText nullable

generated_at          timestamp nullable
synced_at             timestamp nullable

timestamps
```

### Model: `app/Models/BusinessProfileFiles.php`

- relation: `belongsTo(Tenant::class)`

---

## 3. Extend The Existing `tenants` Table

Add only the fields needed for onboarding and live-channel behaviour.

Suggested columns:

```php
onboarding_status      string default 'pending'   // pending | in_progress | complete
onboarding_step        unsignedTinyInteger default 0
tone                   string(50) nullable
capabilities           json nullable
channel                string(50) nullable        // whatsapp | telegram
channel_config         json nullable
agent_status           string default 'offline'   // offline | deploying | live | failed
agent_last_synced_at   timestamp nullable
webhook_secret         string(100) nullable
last_health_check_at   timestamp nullable
last_health_check_status string(50) nullable
health_check_message   text nullable
```

### Tenant model updates

- cast `capabilities` as `array`
- cast `channel_config` as `encrypted:array`
- add:
  - `businessProfile(): HasOne`
  - `businessProfileFiles(): HasOne`

Do not add per-tenant SSH fields. SSH belongs on `servers`.

---

## Seeder

Create a development seeder for one sample business profile tied to the existing seeded dev tenant.

---

## Acceptance Criteria

- migrations run cleanly
- `Tenant -> businessProfile` resolves
- `Tenant -> businessProfileFiles` resolves
- onboarding fields coexist with current provisioning fields
- no existing signup/provisioning behaviour is broken

