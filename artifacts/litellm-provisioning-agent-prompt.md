# LiteLLM Key Provisioning — Codex Agent Instruction

When provisioning a new OpenClaw client instance, you must generate a dedicated LiteLLM virtual key for that tenant before starting the instance.

## Configuration

- **LiteLLM Base URL:** `https://litellm.stylesoftware.co.nz`
- **Control App Master Key:** stored in env as `LITELLM_MASTER_KEY`

## Step 1 — Generate Tenant Key

```http
POST https://litellm.stylesoftware.co.nz/key/generate
Authorization: Bearer {LITELLM_MASTER_KEY}
Content-Type: application/json

{
  "key_alias": "openclaw-{tenant_id}",
  "max_budget": {budget_from_plan},
  "budget_duration": "monthly",
  "metadata": {
    "tenant_id": "{tenant_id}",
    "plan": "{plan_name}"
  }
}
```

Store the returned `key` value against the tenant record in the database.

## Step 2 — Inject into Instance Environment

```env
OPENAI_API_KEY={returned_key}
OPENAI_BASE_URL=https://litellm.stylesoftware.co.nz
```

## Step 3 — Lifecycle Management

**On tenant cancellation:**
```http
POST https://litellm.stylesoftware.co.nz/key/delete
Authorization: Bearer {LITELLM_MASTER_KEY}

{ "keys": ["{tenant_key}"] }
```

**On plan upgrade or downgrade:**
```http
POST https://litellm.stylesoftware.co.nz/key/update
Authorization: Bearer {LITELLM_MASTER_KEY}

{
  "key": "{tenant_key}",
  "max_budget": {new_budget}
}
```

## Rules

- Never reuse keys across tenants — one key per instance
- If key generation fails, abort provisioning and surface the error — do not start the instance without a key
- Never hardcode the master key — always read from environment variable `LITELLM_MASTER_KEY`
- On suspension (not cancellation), call `/key/update` with `"budget_duration": null` and `"max_budget": 0` to freeze spend without deleting the key
