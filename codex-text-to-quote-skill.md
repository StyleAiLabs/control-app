# Codex Agent Instruction — Text-to-Quote Skill Pack

## Context — Read This First

You are building a **skill pack** that runs inside an **OpenClaw** instance.

- **OpenClaw** is the AI agent runtime (the platform you are building FOR)
- **Sync360** is the SaaS brand that sells Digital Employee products powered by OpenClaw
- **Skill packs** are self-contained capability modules installed into OpenClaw instances
- The customer (tradie business) never sees "OpenClaw", "Sync360", "LiteLLM", or any AI brand — they see only their own business name and output

Your job is to build `text-to-quote` as a portable, installable skill pack. It must work on any OpenClaw instance. All tenant-specific values come from `config.json` only — zero hardcoding.

---

## What You Are Building

A skill pack that does this:

```
[Input]  WhatsApp message / voice note / email
         "Quote bathroom reno at 45 Queen St for John, shower tiles vanity"
              ↓
[Parse]  Extract: client, location, job type, scope items, confidence score
              ↓
[Price]  Match scope items to rate card → calculate line items, GST, total
              ↓
[Generate] Branded PDF quote (business logo, T&Cs, payment details)
              ↓
[Deliver] Email to client + WhatsApp confirmation to owner
              ↓
[Log]    Emit outcome event → Sync360 dashboard
```

---

## Folder Structure

```
skills/
└── text-to-quote/
    ├── skill.json                  ← manifest: metadata, version, triggers, required config
    ├── config.schema.json          ← JSON schema for all config fields
    ├── config.json                 ← tenant config (populated at provisioning, never committed)
    ├── state.json                  ← runtime state (quote counter, activation status)
    ├── SKILL.md                    ← human-readable description for OpenClaw agent context
    ├── index.js                    ← skill entry point
    ├── lifecycle/
    │   ├── install.js              ← runs once on installation
    │   ├── activate.js             ← runs on activation
    │   ├── deactivate.js           ← runs on deactivation
    │   ├── uninstall.js            ← runs on uninstall
    │   ├── health-check.js         ← verifies skill is operational
    │   └── self-test.js            ← end-to-end smoke test (no real sends)
    ├── src/
    │   ├── parser.js               ← extract job details from raw message
    │   ├── calculator.js           ← apply rate card, calculate totals and GST
    │   ├── pdf-generator.js        ← build branded PDF quote
    │   ├── sender.js               ← send email + WhatsApp confirmation
    │   ├── outcome.js              ← emit structured outcome event to dashboard
    │   └── registry-logger.js      ← log all lifecycle events to Sync360 registry
    ├── templates/
    │   └── quote.html              ← PDF quote HTML template
    ├── logs/
    │   └── .gitkeep                ← local log fallback directory
    ├── tests/
    │   ├── parser.test.js
    │   ├── calculator.test.js
    │   ├── pdf-generator.test.js
    │   ├── sender.test.js
    │   └── scenarios.test.js       ← all use case scenarios
    └── package.json
```

---

## Skill Manifest — `skill.json`

```json
{
  "name": "text-to-quote",
  "version": "1.0.0",
  "display_name": "Text-to-Quote",
  "description": "Convert WhatsApp messages or voice notes into professional branded PDF quotes sent directly to clients.",
  "pack": "core",
  "priority": "P0",
  "triggers": ["whatsapp_message", "email_received", "voice_note"],
  "required_config": [
    "business_name",
    "business_email",
    "gst_rate",
    "rate_card",
    "smtp_host",
    "smtp_user",
    "smtp_pass",
    "whatsapp_notify_number"
  ],
  "optional_config": [
    "logo_path",
    "brand_color",
    "terms_and_conditions",
    "payment_details",
    "crm_webhook_url",
    "outcome_endpoint",
    "registry_endpoint"
  ],
  "outcome_events": [
    "quote_created",
    "quote_sent",
    "quote_flagged",
    "quote_failed"
  ],
  "dashboard_metrics": [
    { "key": "quotes_created", "label": "Quotes Created", "icon": "file-text" },
    { "key": "quotes_sent", "label": "Quotes Sent", "icon": "send" },
    { "key": "quote_value_total", "label": "Total Quote Value", "icon": "dollar-sign", "format": "currency" }
  ],
  "lifecycle_hooks": ["install", "activate", "deactivate", "uninstall", "health-check", "self-test"]
}
```

---

## Config Schema — `config.schema.json`

```json
{
  "$schema": "http://json-schema.org/draft-07/schema#",
  "type": "object",
  "required": ["business_name", "business_email", "gst_rate", "rate_card", "smtp_host", "smtp_user", "smtp_pass", "whatsapp_notify_number"],
  "properties": {
    "tenant_id":               { "type": "string" },
    "business_name":           { "type": "string" },
    "business_email":          { "type": "string", "format": "email" },
    "business_phone":          { "type": "string" },
    "business_address":        { "type": "string" },
    "logo_path":               { "type": "string" },
    "brand_color":             { "type": "string", "default": "#E8612C" },
    "gst_rate":                { "type": "number", "default": 0.15 },
    "terms_and_conditions":    { "type": "string" },
    "payment_details":         { "type": "string" },
    "quote_validity_days":     { "type": "number", "default": 30 },
    "labour_rate_per_hour":    { "type": "number" },
    "confidence_threshold":    { "type": "number", "default": 0.7 },
    "rate_card": {
      "type": "array",
      "items": {
        "type": "object",
        "required": ["keyword", "label", "unit_price", "unit"],
        "properties": {
          "keyword":    { "type": "string" },
          "label":      { "type": "string" },
          "unit_price": { "type": "number" },
          "unit":       { "type": "string", "enum": ["each", "hour", "m2", "lm", "job"] }
        }
      }
    },
    "smtp_host":               { "type": "string" },
    "smtp_port":               { "type": "number", "default": 587 },
    "smtp_user":               { "type": "string" },
    "smtp_pass":               { "type": "string" },
    "whatsapp_notify_number":  { "type": "string" },
    "crm_webhook_url":         { "type": "string" },
    "outcome_endpoint":        { "type": "string" },
    "registry_endpoint":       { "type": "string" }
  }
}
```

---

## Lifecycle System

### Overview

Every skill pack must implement a full lifecycle. The `lifecycle/` scripts are called by OpenClaw's skill manager at the appropriate moment. Each lifecycle event must be logged to the Sync360 skill registry (`registry_endpoint`) and locally to `logs/lifecycle.jsonl` as fallback.

### `lifecycle/install.js`

Runs ONCE when the skill is first installed onto an OpenClaw instance.

**Must:**
1. Validate `config.json` against `config.schema.json` — fail loudly if invalid
2. Create `state.json` with initial values: `{ "last_quote_number": 0, "status": "installed", "installed_at": "<ISO timestamp>" }`
3. Create empty `logs/` directory if not present
4. Verify all required npm dependencies are installed — run `npm install` if not
5. Verify SMTP credentials by sending a test connection (no email sent)
6. Log `skill_installed` event to registry
7. Exit 0 on success, exit 1 with clear error message on failure

```javascript
// registry log payload for install
{
  "event": "skill_installed",
  "skill": "text-to-quote",
  "version": "1.0.0",
  "tenant_id": config.tenant_id,
  "timestamp": "<ISO>",
  "status": "success" | "failed",
  "details": { "config_valid": true, "smtp_reachable": true, "dependencies_ok": true }
}
```

### `lifecycle/activate.js`

Runs when the skill is enabled for a tenant after installation (or re-enabled after deactivation).

**Must:**
1. Run `health-check.js` — abort if health check fails
2. Run `self-test.js` in dry-run mode — abort if any scenario fails
3. Update `state.json`: `{ "status": "active", "activated_at": "<ISO>" }`
4. Log `skill_activated` event to registry
5. Exit 0 only if ALL checks pass — no partial activations

```javascript
{
  "event": "skill_activated",
  "skill": "text-to-quote",
  "tenant_id": config.tenant_id,
  "timestamp": "<ISO>",
  "status": "success" | "failed",
  "details": { "health_check": "passed", "self_test": "passed" }
}
```

### `lifecycle/deactivate.js`

Runs when the skill is temporarily disabled (tenant paused, plan downgrade, etc).

**Must:**
1. Update `state.json`: `{ "status": "inactive", "deactivated_at": "<ISO>", "deactivation_reason": "<reason>" }`
2. Flush any pending outcome events from local queue
3. Log `skill_deactivated` event to registry
4. Do NOT delete any data — deactivation is reversible

```javascript
{
  "event": "skill_deactivated",
  "skill": "text-to-quote",
  "tenant_id": config.tenant_id,
  "timestamp": "<ISO>",
  "reason": "<reason string passed by caller>",
  "status": "success"
}
```

### `lifecycle/uninstall.js`

Runs when the skill is permanently removed from an instance.

**Must:**
1. Check `state.json` — if status is `active`, run `deactivate.js` first
2. Export final outcomes summary to `logs/final-export.json` before deletion
3. Log `skill_uninstalled` event to registry BEFORE clearing local data
4. Delete `state.json`, `logs/`, and any generated files
5. Do NOT delete `config.json` or `skill.json` — the skill manager handles those
6. Exit 0 when complete

```javascript
{
  "event": "skill_uninstalled",
  "skill": "text-to-quote",
  "tenant_id": config.tenant_id,
  "timestamp": "<ISO>",
  "status": "success",
  "details": { "final_quote_number": 42, "total_quotes_sent": 38 }
}
```

### `lifecycle/health-check.js`

Runs on demand and before activation. Returns a health report.

**Must check:**
- [ ] `config.json` exists and is valid
- [ ] All required config fields are present and non-empty
- [ ] SMTP connection reachable (TCP handshake only — no email sent)
- [ ] `state.json` exists and status is not corrupted
- [ ] LLM endpoint reachable (`OPENAI_BASE_URL` ping)
- [ ] `templates/quote.html` exists
- [ ] Puppeteer (PDF engine) can launch headlessly

**Output:**
```json
{
  "skill": "text-to-quote",
  "tenant_id": "abc123",
  "timestamp": "<ISO>",
  "healthy": true,
  "checks": {
    "config_valid": { "pass": true },
    "smtp_reachable": { "pass": true },
    "llm_reachable": { "pass": true },
    "state_ok": { "pass": true },
    "template_exists": { "pass": true },
    "puppeteer_ok": { "pass": true }
  }
}
```

Exit 0 if all checks pass. Exit 1 if any check fails — include which check failed and why.

### `lifecycle/self-test.js`

Runs a full end-to-end smoke test using mock data. All external calls (email send, WhatsApp, CRM webhook, outcome endpoint) are intercepted and not executed. PDF generation IS executed for real.

**Must test all scenarios from the Use Cases section below.**

Run with: `node lifecycle/self-test.js --dry-run`

Output a pass/fail result per scenario. Exit 0 only if ALL scenarios pass.

---

## Use Cases & Scenarios

Build `src/parser.js`, `src/calculator.js`, `src/sender.js` to handle ALL of these. Each scenario must have a corresponding test in `tests/scenarios.test.js`.

### Group A — Standard Inputs

| # | Scenario | Input | Expected Behaviour |
|---|---|---|---|
| A1 | Full WhatsApp message | "Quote bathroom reno at 45 Queen St for John Smith john@email.com, supply and fit shower, tiles, vanity" | All fields extracted, confidence > 0.9, quote generated and sent |
| A2 | Email request | Email body with job description, client details in signature | Parsed from email body, quote sent to reply-to address |
| A3 | Voice note | Transcribed text from voice note | Treated same as text message, parsed normally |

### Group B — Missing Information

| # | Scenario | Input | Expected Behaviour |
|---|---|---|---|
| B1 | No client email | "Quote tiling job for Dave at 12 Main St" | Quote created, PDF generated, owner notified via WhatsApp to supply email, `quote_created` emitted NOT `quote_sent` |
| B2 | No client name | "Quote for bathroom reno at 45 Queen St" | Use "Valued Client" as placeholder, flag to owner, proceed |
| B3 | No location | "Quote shower install for John Smith john@email.com" | Omit location from quote, proceed normally |
| B4 | No scope items | "Can you quote something for me?" | Confidence < threshold, do NOT generate quote, notify owner with message asking for job details |
| B5 | No rate card match | Scope item not in rate card | Fall back to `labour_rate_per_hour` × 1hr line item, labelled as scope item text |

### Group C — Ambiguous or Complex Input

| # | Scenario | Input | Expected Behaviour |
|---|---|---|---|
| C1 | Low confidence message | Very vague job description | confidence < threshold, emit `quote_flagged`, WhatsApp owner asking to clarify |
| C2 | Multiple jobs in one message | "Quote bathroom reno AND also a deck at the back" | Split into two separate quotes, generate and send both, emit two outcome events |
| C3 | Urgent job | "Urgent — quote emergency leak repair for Mary" | Parse urgency = "urgent", add "URGENT" label to quote header, prioritise delivery |
| C4 | Quote amendment request | "Can you update the last quote and add a toilet?" | Detect amendment intent, load last quote from state, add line item, regenerate PDF with updated quote number suffix (e.g. Q-2026-0042-R1) |
| C5 | Price enquiry (not a quote) | "How much do you charge for tiling?" | Detect as enquiry not quote request, respond with rate card summary, do NOT generate formal quote |
| C6 | International client | Client email has international domain, price in NZD | Quote always in NZD, no currency conversion, include "All prices in NZD" footer note |

### Group D — Error & Edge Cases

| # | Scenario | Expected Behaviour |
|---|---|---|
| D1 | SMTP fails on send | Retry 3× with 5s backoff. If all fail: save PDF locally to `logs/unsent/`, emit `quote_failed` with reason, WhatsApp owner with failure alert |
| D2 | LLM unreachable | Emit `quote_failed`, WhatsApp owner "Digital Employee is temporarily unavailable", log full error to lifecycle log |
| D3 | PDF generation fails | Log error, emit `quote_failed`, do NOT send incomplete output to client |
| D4 | Duplicate message received | Detect identical message within 5 minutes (hash check), skip processing, log as `duplicate_skipped` |
| D5 | Config missing required field | Refuse to run, exit with clear error: "text-to-quote: missing required config field 'smtp_host'" |
| D6 | Skill is inactive | Check `state.json` status at entry point, if not `active` exit immediately with log: "Skill is not active — activation required" |
| D7 | Empty rate card | No `rate_card` entries in config | Fall back entirely to `labour_rate_per_hour`, warn owner via WhatsApp that rate card needs setup |
| D8 | Very large quote | 20+ line items | No item limit — generate full quote, paginate PDF if needed |
| D9 | Outcome endpoint unreachable | Write event to `logs/outcomes-queue.jsonl`, retry on next successful run |

---

## Registry Logger — `src/registry-logger.js`

All lifecycle events and critical runtime errors must be logged to TWO places:

1. **Remote** — POST to `config.registry_endpoint` (Sync360 control app)
2. **Local fallback** — append to `logs/lifecycle.jsonl` (one JSON object per line)

If remote POST fails, write to local only and queue for retry on next lifecycle event.

```javascript
// registry-logger.js interface
async function log(event, details, config) {
  const entry = {
    skill: 'text-to-quote',
    version: require('../skill.json').version,
    tenant_id: config.tenant_id || 'unknown',
    timestamp: new Date().toISOString(),
    event,          // e.g. "skill_installed", "skill_activated", "quote_failed"
    ...details
  };

  // 1. Try remote
  try {
    await fetch(config.registry_endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(entry),
      signal: AbortSignal.timeout(5000)
    });
  } catch (err) {
    // 2. Fall back to local
    fs.appendFileSync('logs/lifecycle.jsonl', JSON.stringify(entry) + '\n');
  }
}
```

---

## Core Logic

### `src/parser.js`

Use the LLM (via `OPENAI_BASE_URL` + `OPENAI_API_KEY` env vars) to extract structured job data.

**LLM prompt:**
```
Extract job request details from this message. Return ONLY valid JSON with these exact fields:
{
  "client_name": string | null,
  "client_email": string | null,
  "client_phone": string | null,
  "job_type": string,
  "location": string | null,
  "scope_items": string[],
  "urgency": "urgent" | "normal" | "flexible",
  "intent": "quote_request" | "price_enquiry" | "quote_amendment" | "other",
  "amendment_ref": string | null,
  "confidence": number (0.0–1.0)
}

Message: {raw_message}
```

Rules:
- Never fabricate values not present in the message
- If `intent` is not `quote_request`, return early — do not proceed to pricing
- If `confidence` < `config.confidence_threshold`, emit `quote_flagged` and stop

### `src/calculator.js`

- Match each `scope_item` to `rate_card` entries using case-insensitive keyword matching
- If multiple rate card entries match one scope item, use the closest match
- Unmatched items fall back to `labour_rate_per_hour × 1 hour`
- All values rounded to 2 decimal places
- `gst_amount = subtotal × config.gst_rate`
- `total_inc_gst = subtotal + gst_amount`

### `src/pdf-generator.js`

- Use `puppeteer` to render `templates/quote.html` to PDF
- Quote number: `Q-{YYYY}-{zero-padded-4-digit-counter}` e.g. `Q-2026-0042`
- Amendments: append revision suffix `Q-2026-0042-R1`
- Increment and persist counter in `state.json` atomically before generation
- Apply `config.brand_color` to header, table headers, totals row
- Include: logo, business details, client name, location, line items table, subtotal, GST, total, validity date, T&Cs, payment details
- If `config.logo_path` missing: render without logo, no error
- PDF page size: A4

### `src/sender.js`

1. Send email to `client_email` with PDF attached
   - Subject: `Quote from {business_name} — {job_type} (#{quote_number})`
   - If no `client_email`: save PDF to `logs/unsent/{quote_number}.pdf`, skip email step
2. WhatsApp owner notification
3. POST to `crm_webhook_url` if set
4. Retry failed email sends: 3 attempts, 5s backoff

### `src/outcome.js`

POST outcome event to `config.outcome_endpoint`. Fall back to `logs/outcomes-queue.jsonl` if unreachable.

```json
{
  "skill_pack": "text-to-quote",
  "event_type": "quote_sent",
  "tenant_id": "abc123",
  "timestamp": "<ISO>",
  "summary": "Quote Q-2026-0042 sent to John Smith — $2,553 incl. GST",
  "value": 2553.00,
  "status": "success",
  "metadata": {
    "quote_number": "Q-2026-0042",
    "client_name": "John Smith",
    "job_type": "bathroom renovation",
    "line_item_count": 3,
    "email_sent": true
  }
}
```

---

## Entry Point — `index.js`

```javascript
async function run(rawMessage) {
  const config = require('./config.json');

  // Guard: check skill is active
  const state = JSON.parse(fs.readFileSync('./state.json', 'utf8'));
  if (state.status !== 'active') {
    await registryLogger.log('skill_blocked', { reason: 'Skill not active', status: state.status }, config);
    throw new Error(`text-to-quote skill is not active (status: ${state.status}). Run activate first.`);
  }

  try {
    const parsed   = await parse(rawMessage, config);
    if (parsed.intent !== 'quote_request') return handleNonQuoteIntent(parsed, config);
    if (parsed.confidence < config.confidence_threshold) return handleLowConfidence(parsed, config);

    const pricing  = calculate(parsed.scope_items, config);
    const pdf      = await generatePDF(parsed, pricing, config);
    const result   = await send(pdf, parsed, pricing, config);
    await emitOutcome({ ...result, parsed, pricing }, config);

    return { status: 'success', quote_number: result.quote_number, total: pricing.total_inc_gst };

  } catch (err) {
    await emitOutcome({ event_type: 'quote_failed', summary: err.message, status: 'failed' }, config);
    await registryLogger.log('skill_error', { error: err.message, stack: err.stack }, config);
    throw err;
  }
}
```

---

## Environment Variables

Injected by OpenClaw at runtime. Read from `process.env` only — never from config.json.

```env
OPENAI_API_KEY=<tenant_litellm_virtual_key>
OPENAI_BASE_URL=https://litellm.stylesoftware.co.nz
```

---

## Dependencies

```json
{
  "name": "text-to-quote",
  "version": "1.0.0",
  "dependencies": {
    "nodemailer": "^6.9.0",
    "puppeteer": "^22.0.0",
    "openai": "^4.0.0",
    "ajv": "^8.0.0",
    "uuid": "^9.0.0",
    "node-fetch": "^3.0.0"
  },
  "devDependencies": {
    "jest": "^29.0.0"
  },
  "scripts": {
    "install-skill":  "node lifecycle/install.js",
    "activate":       "node lifecycle/activate.js",
    "deactivate":     "node lifecycle/deactivate.js",
    "uninstall-skill":"node lifecycle/uninstall.js",
    "health-check":   "node lifecycle/health-check.js",
    "self-test":      "node lifecycle/self-test.js --dry-run",
    "test":           "jest"
  }
}
```

---

## Activation Flow (How OpenClaw Deploys This)

```
1. npm install                          ← install dependencies
2. npm run install-skill                ← validate config, init state, check SMTP
3. npm run activate                     ← health check + self-test, mark active
4. node index.js --message "..."        ← skill is now operational
```

Deactivation:
```
npm run deactivate -- --reason "plan_downgrade"
```

Uninstall:
```
npm run uninstall-skill
```

---

## Definition of Shippable

The skill pack ships when ALL of the following are true:

- [ ] All files in folder structure exist and are non-empty
- [ ] `skill.json` and `config.schema.json` are valid JSON
- [ ] `npm run install-skill` exits 0 with a valid `config.json`
- [ ] `npm run health-check` exits 0 with all checks passing
- [ ] `npm run self-test` exits 0 with all scenarios A1–D9 passing
- [ ] `npm run activate` exits 0
- [ ] `npm run deactivate` exits 0 and state persists correctly
- [ ] `npm run uninstall-skill` exits 0 and local state is cleaned up
- [ ] `npm test` passes all unit tests
- [ ] Registry logger writes to `logs/lifecycle.jsonl` when `registry_endpoint` is not set
- [ ] The entire `skills/text-to-quote/` folder can be zipped, moved to a fresh machine with only Node.js installed, and complete the full activation flow successfully with a valid `config.json`
- [ ] Zero references to "Claude", "Anthropic", "LiteLLM", "OpenAI", or "OpenClaw" appear in any customer-facing output (PDF, emails, WhatsApp messages)

---

## What NOT To Do

- Do not hardcode any tenant data — everything comes from `config.json`
- Do not proceed past parser if `intent` is not `quote_request`
- Do not send to client without confirmation if confidence < threshold
- Do not send incomplete or error-state PDFs to clients
- Do not skip lifecycle logging — every event must be written somewhere
- Do not fail silently — every failure path must emit an outcome event AND log to registry
- Do not expose internal errors, stack traces, or system paths in customer-facing messages
- Do not allow the skill to run if `state.json` status is not `active`
