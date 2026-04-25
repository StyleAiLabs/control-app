# Sync360 Custom Skill Authoring / Contract Prompt

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
- `runtime_type`: one of `sync360_workspace`, `openclaw_native`, or `runtime_capability`. Use `sync360_workspace` for repo-authored markdown skills that are materialized into `.openclaw/workspace/skills/<skill-id>/`.
- `production_ready`: boolean.
- `openclaw_skill_ids`: list of OpenClaw skill IDs to enable. For `sync360_workspace`, this must contain only the skill's own workspace skill id.
- `default_agent_skill_ids`: list of default agent skills to attach. For `sync360_workspace`, this must contain only the skill's own workspace skill id.
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
- `sync360_workspace` skills are real OpenClaw workspace skills loaded from `.openclaw/workspace/skills/<skill-id>/`; they should be allowlisted in `openclaw.json` and should not ask the agent to read `/app/skills/<skill-id>/SKILL.md`.
- Use `openclaw_native` only when the skill truly exists outside Sync360's workspace materialization path, such as a bundled or managed OpenClaw skill.
- Use `runtime_capability` only for Sync360-managed host/container dependencies such as external binaries, mounted auth/config, and real OpenClaw-native skills provided by that runtime dependency.
- Emit analytics only after an authoritative successful business outcome.
- Do not emit on intent, partial data collection, tentative next steps, or pending human follow-up.
- Emit exactly once per business outcome.
- Always provide a stable `event_id`.
- Always provide a stable `conversion_id`. It is scoped within this skill, not globally unique across skills.
- For external event triggers with a provider id, such as a Gmail message id or calendar event id, use that provider id as the primary business handle unless the skill explicitly defines a safer canonical id. Do not substitute internal job ids for operator-facing references when the provider id is needed for exact follow-up.
- `skill_key` must equal `manifest.json.skill_id`.
- `skill_version` must equal the manifest version string exactly.
- The Sync360 helper resolves `skill_key` and `skill_version` from the deployed registry; the skill instructions should not hardcode event rows or write SQL directly.

Runtime activation rules:
- Treat runtime activation as a separate contract from file materialization. A valid custom skill must work when Sync360 materializes it into `.openclaw/workspace/skills/<skill-id>/`, allowlists it in `openclaw.json`, and verifies it through the live runtime skill list.
- For `sync360_workspace`, keep `manifest.json.skill_id`, `openclaw_skill_ids[0]`, `default_agent_skill_ids[0]`, the materialized folder name, and the `agent-instructions.md` pointer aligned to the same exact skill id. Do not create alias ids, alternate folder names, or mixed identifiers unless the runtime design explicitly requires them.
- Do not rely on `AGENTS.md` or `agent-instructions.md` alone to make the skill usable. Those files are discovery hints. The skill must still be valid when Sync360 verifies runtime eligibility independently.
- Assume Sync360 may fail closed when a trigger requires this skill but the live runtime has not verified it yet. Write trigger descriptions, command contracts, and required side effects so the runtime can safely withhold delivery until the skill is truly active.
- If the skill depends on another runtime-managed capability or native skill, say so explicitly in the manifest/runtime contract instead of implying it only in prose.

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

Required tool-contract rules:
- Every required side effect must name the exact tool or command family, allowed fields or flags, forbidden fields or flags, success signal, failure behavior, privacy boundary, and idempotency key.
- Do not describe required side effects with vague verbs such as "notify", "upload", "sync", "log", or "update" unless the skill also provides the exact tool/command contract and validation rule.
- If a workflow branch requires a side effect, the skill must require the agent to execute that side effect in the current run, not merely plan or promise it. Phrases like "next I will email them", "this will be logged", or "a follow-up will be sent" are invalid unless a matching tool/command result already exists.
- The final response contract must be evidence-based: never let the agent claim `sent`, `drafted`, `uploaded`, `logged`, `created`, or similar completion states without a matching successful tool/command result in the same run.
- When a side effect is required by the workflow, define explicit outcome states such as `succeeded`, `skipped_with_reason`, and `failed_with_error`, and require the final summary to report the real outcome instead of future intent.
- For Telegram notifications through the runtime `message` tool, normal sends must use only `action: "send"`, `channel: "telegram"`, `target: <chat id>`, and `message: <body>` unless the skill is explicitly creating another message type.
- Telegram normal sends must not include poll-only or unrelated fields such as `poll*`, `limit`, `pageSize`, `duration*`, buttons, interactive payloads, or poll options.
- For `gog` commands, use only command shapes already proven in Sync360 runtime guidance or tell the agent to inspect the exact service help before running the action.
- Do not invent `gog` flags. If a command fails because of unsupported flags, the skill must capture the exact error, inspect help once, and report the supported syntax or failure instead of retrying with guessed flags.
- Do not use workspace patch/file-edit tools as a substitute for an external side effect. If a skill promises Google Drive, CRM, calendar, Telegram, or another external action, success requires the corresponding external tool/command to confirm that action.
- Internal workflow triggers should use tenant workspace files and event payloads as source material. Do not use public web browsing or `web_search` unless the owner explicitly asks for external research or the skill defines a verified research step.
- If the skill uses provider ids from incoming events, the final contract must preserve those ids exactly through follow-up actions, references, notifications, analytics, and logs whenever they are the safest handle for reopening the same record later.
- Required side effects must be branch-complete. Do not leave a branch in a state where the agent can classify, summarize, or log the work while the required customer-facing action remains unexecuted.
- If a required side effect is intentionally draft-only or human-review-only, the skill must say that explicitly and define the exact drafted/not-sent outcome state. Never let the agent quietly downgrade a required send into a draft without that branch being designed to do so.
- For customer-facing messages such as Gmail replies, SMS, WhatsApp, or chat follow-ups, the skill must define the copy format explicitly. When the business has an onboarding tone or workspace voice source, require the skill to follow that tone rather than inventing a new style ad hoc.
- For customer-facing plain-text messages, explicitly forbid literal escape sequences such as `\n`, `\r`, and `\t` in the sent body, and say whether the message should be a single paragraph or another constrained format.
- If the business wants clean plain-text customer messaging, say so directly: require ASCII-safe body text by default and forbid decorative special characters, emoji, markdown, smart quotes, bullets, or other formatting noise unless the exact business/customer text requires them.

Required `SKILL.md` content:
- YAML frontmatter with `name` and `description`.
- A short section explaining when to use the skill.
- A required workflow section describing ordered steps, success criteria, idempotency keys, and final reporting expectations.
- Concrete instructions for every required side effect such as notification, file creation, CRM update, calendar creation, Drive logging, or analytics emission.
- For each required side effect, include:
  - when to perform it
  - when not to perform it
  - the stable id/key to use for de-dupe
  - the tool or command family to use
  - the minimum payload/content fields
  - privacy limits
  - exact success validation
  - exact failure handling
- For customer-facing send/draft actions, include copy-style rules near the command contract: tone source, allowed formatting, forbidden escape sequences, paragraph/list expectations, and any character-set restrictions the business wants.
- Do not leave required side effects as vague bullets like "log this", "notify the team", or "update records". If the agent must do it, provide enough command/tool guidance and validation rules that it can prove completion.
- Do not let the skill describe a required side effect as a future handoff unless that handoff is truly outside the runtime and explicitly marked as such. If the runtime is supposed to send the email, post the message, create the calendar item, or write the record, the skill must require execution now plus output validation now.
- A final response contract requiring the agent to report each required side effect as succeeded, skipped with reason, or failed with exact error/output.
- For trigger-driven skills, a short section naming the required runtime references and source-of-truth ids from the incoming event. If the trigger includes an exact provider id, require the skill to reuse it instead of re-discovering the same record through a fuzzy search.
- For any branch that depends on a live runtime side effect, language that forbids "will do next", "to be sent", "prepared", or similar future-intent phrasing unless the branch is explicitly draft-only and the draft action already succeeded.
- An `Analytics Contract` section that says:
  - when success is authoritative
  - which `conversion_id` to use
  - which payload fields are required
  - when not to emit analytics
  - the exact helper invocation pattern
  - why operational logs or notifications are not by themselves proof of conversion unless the skill's business success criteria are met

Required `agent-instructions.md` content:
- This file provides the compact index entry Sync360 injects into the agent's `agent.md` when the skill is assigned to a tenant.
- It must NOT contain full workflow instructions — those live in `SKILL.md` and are read on demand.
- Keeping this file short is critical: `agent.md` is loaded into context at every session start. If it grows too large across many installed skills, the agent will truncate it and skills listed near the bottom will silently stop firing.
- Must contain exactly ONE index entry in this format:

```
- <skill-id>: <one-line trigger description> → read `skills/<skill-id>/SKILL.md` and follow it exactly. Do not use your default <capability> behavior.
```

Example:
```
- quote-generation: customer asks for a quote or pricing estimate → read `skills/quote-generation/SKILL.md` and follow it exactly. Do not use your default document generation behavior.
```

- The trigger description must be specific enough to fire on real user requests without false positives.
- The trigger description must also be strong enough that Sync360 can safely use it as a runtime-required skill declaration for automated workflows. Avoid vague catch-all wording that could overlap with unrelated default assistant behavior.
- The pointer to `skills/<skill-id>/SKILL.md` is the workspace path materialized by Sync360 — do not use `/app/skills/`.
- The "Do not use your default" clause is required to override OpenClaw's built-in behavior.

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
- The skill must not claim a workflow is complete while a required side effect was skipped silently.
- The skill must make optional actions explicitly optional; everything else needs validation and failure handling.
- Supporting docs should be concise and placed under `docs/` only when they materially improve skill behavior.

Verification checklist:
- `php artisan sync360:skills:scan --skill=<skill-id>` reports the manifest as valid.
- `php artisan sync360:skills:import --skill=<skill-id>` imports the skill.
- Scan/import fails if `RELEASE_NOTES.md` is missing, empty, or does not include the current manifest version.
- Runtime materialization places files under `.openclaw/workspace/skills/<skill-id>/`.
- Runtime materialization does not create `.openclaw/workspace/skills/<skill-id>/skills/<skill-id>/`.
- The skill id shown in `manifest.json`, `openclaw_skill_ids`, `default_agent_skill_ids`, `agent-instructions.md`, and the materialized folder name all match exactly.
- The runtime can expose the skill through the live eligibility surface (`openclaw skills list --eligible`) after Sync360 assignment/apply, rather than only showing the files on disk.
- If the skill is intended for automated trigger delivery, verify at least one regression path where the runtime is missing the skill at first and Sync360 must either self-heal or fail closed instead of delivering to the wrong/default behavior.
- The emitted analytics payload passes helper validation.
- Duplicate `event_id` does not create duplicate conversion records.
- Required non-analytics side effects are tested or manually verified from runtime transcripts, including proof that the agent used the intended tool/command and inspected success output.
- A regression transcript or test covers at least one failure path for each required side effect where practical.
- For customer-facing messaging skills, verify at least one transcript or regression path proving the sent/drafted body does not contain literal escape sequences, matches the intended tone source, and respects any plain-text/ASCII formatting rule the skill declares.
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
