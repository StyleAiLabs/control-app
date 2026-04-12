# Sync360 Onboarding Wizard — Implementation Spec
**For:** Codex agent implementation in the Control App  
**Model:** LiteLLM → claude-sonnet-4-6  
**Goal:** Collect business context → generate OpenClaw MD files → connect channel

---

## Overview

```
STEP 1: Website URL
STEP 2: Verify Extracted Info          [LiteLLM call #1 — scrape + extract]
STEP 3: Agent Personality
STEP 4: Agent Capabilities
STEP 5: Channel Connection
STEP 6: Done — Agent is Live
```

Files written to OpenClaw instance via SSH after Step 4 completes.  
Channel config applied after Step 5.

---

## STEP 1 — Business Website

**UI:** Single input field  
**Label:** "Enter your business website URL"  
**Placeholder:** `https://yourbusiness.com`  
**Fallback:** If no website → show 3 manual fields (see Step 2 fallback)

**Action on submit:**
```
POST /api/onboarding/extract
Body: { url: "https://yourbusiness.com", tenant_id: "xxx" }
```

---

## STEP 2 — Verify Extracted Info

**LiteLLM Call #1 — Web Extract Prompt:**

```
You are extracting business information from a website to configure an AI assistant.

Scrape or analyze the provided URL and extract the following in JSON:
{
  "business_name": "...",
  "tagline": "...",
  "description": "2-3 sentences about what the business does",
  "industry": "...",
  "services": ["service1", "service2", ...],
  "target_customers": "who they serve in one sentence",
  "tone_hint": "professional | friendly | formal | casual — infer from website copy",
  "location": "city/country if mentioned",
  "contact_email": "...",
  "contact_phone": "...",
  "business_hours": "...",
  "faqs": [{ "q": "...", "a": "..." }]  // if found on site
}

URL: {{url}}

Return ONLY valid JSON. If a field cannot be found, use null.
```

**UI:** Show extracted data in editable form fields  
**Fields shown:**
- Business Name *(editable)*
- What your business does *(editable textarea)*
- Industry *(editable)*
- Services list *(editable, tag-style)*
- Your customers are *(editable)*

**Fallback (no website):**
Show manual input for:
- Business Name
- What do you do? (textarea)
- Who are your customers? (textarea)

**Action on confirm:** Store extracted JSON in tenant session.

---

## STEP 3 — Agent Personality

**UI:** Single-choice card selector  
**Label:** "How should your digital employee communicate?"

| Option | Description |
|--------|-------------|
| **Friendly & Warm** | Approachable, conversational, uses first names |
| **Professional** | Polished, clear, business-appropriate |
| **Formal** | Reserved, precise, traditional |
| **Casual & Fun** | Relaxed, upbeat, informal |

**Pre-select:** Use `tone_hint` from Step 2 extraction as default  
**Stored as:** `{ tone: "friendly" }` in tenant session

---

## STEP 4 — Agent Capabilities

**UI:** Multi-select toggle cards  
**Label:** "What should your digital employee handle?"

| Toggle | Description | Default |
|--------|-------------|---------|
| Answer FAQs | Respond to common questions about your business | ON |
| Take Messages | Capture name, contact, and query for follow-up | ON |
| Book Appointments | Guide customers to book (link or form) | OFF |
| Handle Complaints | Acknowledge and escalate issues | ON |
| Share Pricing | Discuss service costs if provided | OFF |
| After-hours Responses | Notify customers when you're unavailable | ON |

**Stored as:** `{ capabilities: ["faqs", "messages", "complaints", "after_hours"] }`

---

## MD FILE GENERATION

**Trigger:** After Step 4 confirmed  
**LiteLLM Call #2 — File Generation Prompt:**

```
You are configuring an AI assistant for a small business. 
Using the business data below, generate the content for each agent configuration file.
Return ONLY a JSON object with keys: identity, soul, user, bootstrap

Business Data:
{{extracted_json}}
Tone: {{tone}}
Capabilities: {{capabilities[]}}

Generate:
{
  "identity": "<markdown content>",
  "soul": "<markdown content>",
  "user": "<markdown content>",
  "bootstrap": "<markdown content>"
}

Follow the exact format instructions for each file below.
```

---

### → IDENTITY.md

**Feeds from:** Business name, description, industry, tagline

**Template structure:**
```markdown
# Agent Identity

## Who I Am
I am the digital assistant for **{{business_name}}**.
{{tagline}}

## My Role
{{description}}

## Industry
{{industry}}

## I Represent
- Business: {{business_name}}
- Location: {{location}}
- Contact: {{contact_email}} | {{contact_phone}}
```

---

### → SOUL.md

**Feeds from:** Tone selection (Step 3), capabilities (Step 4), services

**Template structure:**
```markdown
# Agent Soul

## Communication Style
{{tone_instructions}}
— Friendly: warm, use first names, empathetic, conversational
— Professional: clear, structured, business-appropriate, no slang
— Formal: precise, reserved, complete sentences, no contractions
— Casual: upbeat, relaxed, short sentences, natural

## Core Values
- Always represent {{business_name}} with honesty and respect
- Never make promises the business cannot keep
- Always offer to escalate if unsure

## What I Will Do
{{capabilities_as_rules}}

## What I Will Not Do
- Discuss competitor businesses
- Share internal business information
- Make bookings or payments directly unless integrated
- Respond outside my defined capabilities

## Escalation Rule
If a customer query is outside my capabilities or requires human judgment,
I will collect their name and contact details and assure them someone will follow up.
```

---

### → USER.md

**Feeds from:** Target customers (extracted), industry

**Template structure:**
```markdown
# User Context

## Who I Am Talking To
{{target_customers}}

## What They Typically Need
- Quick answers about services
- Contact information
- Pricing guidance
- Support or complaint resolution

## How to Treat Them
- Assume they are not technical
- Be patient and clear
- Confirm understanding before closing a conversation
- Always thank them for reaching out to {{business_name}}
```

---

### → BOOTSTRAP.md

**Feeds from:** Services, FAQs, business hours, contact info, pricing (if captured)

**Template structure:**
```markdown
# Bootstrap Knowledge

## Business: {{business_name}}

## Services Offered
{{services_as_list}}

## Business Hours
{{business_hours | "Please contact us to confirm current hours."}}

## Contact Information
- Email: {{contact_email}}
- Phone: {{contact_phone}}

## Frequently Asked Questions
{{faqs_as_q_and_a | "No FAQs extracted. Add them manually."}}

## Pricing
{{pricing | "Pricing is available on request. Direct customers to contact us."}}

## Important Notes
- This agent was configured on {{setup_date}}
- Skill Pack: {{skill_pack}}
- Industry: {{industry}}
```

---

### → HEARTBEAT.md (Sync360-managed — NOT wizard step)

Written by Sync360 at deployment. Customer does not configure this.

```markdown
# Heartbeat

## Managed by Sync360
- Instance: {{tenant_id}}
- Deployed: {{deploy_date}}
- Model: claude-sonnet-4-6 via LiteLLM
- Skill Pack: {{skill_pack}}
- Channel: {{channel}}

## Health Checks
- Respond to /ping with status OK
- Log errors to Sync360 monitoring
```

---

### → MEMORY (Not generated — starts empty)

OpenClaw builds this over time from real conversations.  
No wizard input required.

---

### → TOOLS (Channel config — Step 5)

Not an MD file. Applied separately via channel connection flow.

---

## STEP 5 — Channel Connection

**UI:** Two large cards  
**Label:** "How will customers reach your digital employee?"

```
[ WhatsApp ]          [ Telegram ]
Connect via           Connect via
WhatsApp Business     Telegram Bot
API                   Token
```

**WhatsApp flow:**
1. Enter WhatsApp Business phone number
2. Enter API token (from Meta Business Manager)
3. Sync360 registers webhook → connects to OpenClaw instance

**Telegram flow:**
1. Enter Bot Token (from @BotFather)
2. Sync360 registers webhook → connects to OpenClaw instance

**Stored as:**
```json
{
  "channel": "whatsapp",
  "whatsapp_number": "+64xxxxxxxxx",
  "whatsapp_token": "xxx"
}
```

---

## STEP 6 — Done

**UI:** Success screen  
**Show:**
- Agent name (from IDENTITY.md business_name)
- Channel connected
- Status: Live
- Button: "Open Dashboard"

**Dashboard then shows:** The card layout already built (Workspace is live, Skill Pack, Industry, Trial Status)

---

## SSH Write Sequence

After Step 4 generate, before Step 6:

```
1. SSH into tenant OpenClaw instance
2. Write /openclaw/IDENTITY.md
3. Write /openclaw/SOUL.md
4. Write /openclaw/USER.md
5. Write /openclaw/BOOTSTRAP.md
6. Write /openclaw/HEARTBEAT.md  (Sync360 template, not generated)
7. Restart OpenClaw agent process
8. Confirm health check /ping → OK
9. Apply channel webhook config
10. Update tenant DB: status = "live"
```

---

## LiteLLM API Call Config

```javascript
const client = new LiteLLM({
  baseURL: process.env.LITELLM_BASE_URL,
  apiKey: process.env.LITELLM_API_KEY
});

const response = await client.chat.completions.create({
  model: "claude-sonnet-4-6",  // or your LiteLLM model alias
  messages: [
    { role: "user", content: prompt }
  ],
  temperature: 0.3,  // low — we want consistent structured output
  max_tokens: 4000
});
```

---

## Tenant DB Fields to Update

```sql
tenants:
  business_name       VARCHAR   -- from Step 2
  industry            VARCHAR   -- from Step 2 / signup
  skill_pack          VARCHAR   -- from signup
  tone                VARCHAR   -- from Step 3
  capabilities        JSON      -- from Step 4
  channel             VARCHAR   -- from Step 5
  channel_config      JSON      -- from Step 5
  onboarding_status   ENUM      -- pending | in_progress | complete
  agent_status        ENUM      -- offline | live
  setup_date          TIMESTAMP
```

---

## Summary Table

| Wizard Step | Input | LiteLLM? | Writes To |
|-------------|-------|----------|-----------|
| 1 — Website URL | URL | Call #1 (extract) | Session |
| 2 — Verify Info | Editable fields | — | Session |
| 3 — Personality | Tone selection | — | Session |
| 4 — Capabilities | Toggle selection | Call #2 (generate) | IDENTITY.md, SOUL.md, USER.md, BOOTSTRAP.md |
| 4 → SSH | Automatic | — | OpenClaw instance |
| 5 — Channel | API credentials | — | TOOLS config + webhook |
| 6 — Done | — | — | Tenant DB status = live |

Sync360-only writes: HEARTBEAT.md (deploy time), MEMORY (runtime)
