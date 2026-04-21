# Sync360 Custom Skill Authoring Prompt

Use this prompt when creating or updating a Sync360 custom skill pack. It defines the platform criteria for repo layout, runtime behavior, analytics, tracking, privacy, and verification.

```text
You are creating a Sync360 custom skill pack for the OpenClaw tenant runtime.

Create the skill so it can be cataloged by Sync360, assigned to tenants, materialized into `.openclaw/workspace/skills/<skill-id>/`, and tracked through Sync360 custom-skill conversion analytics.

Required source layout:
- Put the skill under `resources/skill-packs/<skill-id>/`.
- The pack root must contain `manifest.json`.
- The pack root must contain `SKILL.md`.
- The pack root must contain `agent-instructions.md`.
- The pack root must contain `RELEASE_NOTES.md`.
- Optional supporting guidance belongs under `docs/`.
- Do not create a nested `skills/<skill-id>/` folder inside the pack. Sync360 already materializes the pack into the runtime `skills/<skill-id>/` folder.

Required `manifest.json` fields:
- `skill_id`: stable lowercase kebab-case identifier. This must match the catalog skill id and the analytics `skill_key`.
- `version`: semantic version string.
- `label`: human-readable skill name.
- `description`: concise business-purpose summary.
- `category`: catalog grouping.
- `production_ready`: boolean.
- `openclaw_skill_ids`: list of raw OpenClaw skill IDs to enable only when the pack wraps a real bundled OpenClaw skill under `/app/skills`; use `[]` for Sync360 markdown-only workspace skills.
- `default_agent_skill_ids`: list of default agent skills to attach only for real bundled OpenClaw skills; use `[]` for Sync360 markdown-only workspace skills.
- `applicable_industries`: list, empty when not industry-specific.
- `analytics`: required for conversion-tracked skills.

Versioning rules:
- Increment `manifest.json.version` for every change that affects skill behavior, instructions, metadata, analytics, privacy guidance, or supporting docs.
- Increment the version even when the change feels like "just skill info", such as edits to `label`, `description`, `SKILL.md`, `agent-instructions.md`, analytics defaults, trigger guidance, or docs.
- Do not edit a previously published version in place without changing the version; tenant assignments are version-pinned until an operator rolls out a newer published version.
- Add a short entry to `RELEASE_NOTES.md` for every version increment.

Analytics manifest contract:
- `analytics.enabled`: true for skills that emit conversion analytics.
- `analytics.conversion_type`: stable lowercase underscore conversion type, such as `appointment_booked`.
- `analytics.success_event_type`: must be `conversion_succeeded`.
- `analytics.required_success_fields`: fields the helper must see in `--payload-json`.
- `analytics.roi_defaults.human_effort_minutes`: Sync360 benchmark estimate for a human to complete this outcome.
- `analytics.roi_defaults.agent_effort_minutes`: Sync360 benchmark estimate for the agent to complete this outcome.
- Optional `analytics.roi_defaults.value_amount` and `analytics.roi_defaults.currency` only when there is a defensible default value. If no value is known, omit these so dashboards do not show misleading zero-value metrics.

Analytics event rules:
- Emit analytics only after an authoritative successful business outcome.
- Do not emit on intent, partial data collection, tentative next steps, or pending human follow-up.
- Emit exactly once per business outcome.
- Always provide a stable `event_id`.
- Always provide a stable `conversion_id`. It is scoped within this skill, not globally unique across skills.
- `skill_key` must equal `manifest.json.skill_id`.
- `skill_version` must equal the manifest version string exactly.
- The Sync360 helper resolves `skill_key` and `skill_version` from the deployed registry; the skill instructions should not hardcode event rows or write SQL directly.

Required analytics invocation pattern:
- The skill must use the workspace exec tool.
- The skill must call the Sync360-owned helper:
  `sh .sync360/bin/log-skill-conversion --skill <skill-id> --conversion-id <conversion-id> --payload-json '<json>'`
- The skill must inspect the helper JSON output and treat the conversion as successful only when it contains `"ok": true` and an `event_id`.
- If the helper fails or returns non-JSON output, the skill must report the exact command error/output instead of claiming the conversion succeeded.
- The skill must not write directly to `.openclaw/data/analytics/skill-events.sqlite`.
- The skill must not create its own analytics writer.

Recommended payload shape:
{
  "event_id": "<stable-event-id>",
  "occurred_at": "<ISO-8601 timestamp>",
  "session_id": "<optional runtime session id>",
  "customer_label": "<minimal customer display reference>",
  "contact_masked": "<masked email/phone or null>",
  "outcome": {
    "...": "skill-specific success evidence"
  },
  "estimated_value_amount": "<optional numeric override>",
  "currency": "<optional currency when value is present>",
  "effort_override": {
    "human_effort_minutes": "<optional numeric override>",
    "agent_effort_minutes": "<optional numeric override>"
  }
}

Privacy rules:
- Store the minimum customer information needed for analytics and operator evidence.
- Prefer masked contact details.
- Do not include full raw emails, phone numbers, private notes, credentials, payment details, or unnecessary conversation transcripts in analytics payloads.
- Put skill-specific success evidence under `outcome`.

Required `SKILL.md` content:
- YAML frontmatter with `name` and `description`.
- A short section explaining when to use the skill.
- A behavior section describing the business workflow and success criteria.
- An `Analytics Contract` section that says:
  - when success is authoritative
  - which `conversion_id` to use
  - which payload fields are required
  - when not to emit analytics
  - the exact helper invocation pattern

Required `agent-instructions.md` content:
- This file tells the OpenClaw agent to use the custom skill instead of its default behavior.
- Without this file, the agent will handle requests using built-in capabilities and skip the custom workflow and analytics.
- Must include a `Why this skill exists` section explaining what the custom skill adds over default behavior (workflow control, analytics, privacy rules).
- Must include a `When to activate` section listing specific trigger conditions (user requests, upstream skill suggestions, etc.).
- Must include a `What to do` section with numbered steps: read SKILL.md, follow the workflow, run the analytics helper, do not fall back to default behavior.
- Must explicitly state: "Do not fall back to your default [capability] behavior."
- Must reference the SKILL.md path: `skills/<skill-id>/SKILL.md`.

Required `RELEASE_NOTES.md` content:
- A short heading for the skill release notes.
- A section for the current manifest version.
- One to three concise bullets describing what changed and why operators should roll it out.
- No customer private data, credentials, or long implementation logs.

Quality bar:
- The skill must not invent external confirmations.
- The skill must ask for missing required customer data before claiming success.
- The skill must offer human follow-up when the business workflow is uncertain.
- The skill must use clear customer-facing language and avoid backend platform names.
- Supporting docs should be concise and placed under `docs/` only when they materially improve skill behavior.

Verification checklist:
- `php artisan sync360:skills:scan --skill=<skill-id>` reports the manifest as valid.
- `php artisan sync360:skills:import --skill=<skill-id>` imports the skill.
- Scan/import fails if `RELEASE_NOTES.md` is missing, empty, or does not include the current manifest version.
- Runtime materialization places files under `.openclaw/workspace/skills/<skill-id>/`.
- Runtime materialization does not create `.openclaw/workspace/skills/<skill-id>/skills/<skill-id>/`.
- The emitted analytics payload passes helper validation.
- Duplicate `event_id` does not create duplicate conversion records.
- Tenant dashboard shows successful conversions and estimated time saved.
- Admin Skill Analytics shows the skill and tenant rollups.

Now create the complete skill pack:
1. `resources/skill-packs/<skill-id>/manifest.json`
2. `resources/skill-packs/<skill-id>/SKILL.md`
3. `resources/skill-packs/<skill-id>/agent-instructions.md`
4. `resources/skill-packs/<skill-id>/RELEASE_NOTES.md`
5. optional `resources/skill-packs/<skill-id>/docs/*.md`
6. focused tests or updates proving scan/import/runtime materialization, `AGENTS.md` instruction injection/removal, version bump/release note coverage, and analytics behavior where practical.
```
