# Sync360 External Skill Module Framework And Authoring Prompt

This is the canonical framework for Sync360-compatible skill modules.

Use it when:

- building a new skill module outside this repo
- reviewing whether a third-party skill can join the Sync360 catalog
- deciding whether a skill is instructions-only or needs a real runtime capability
- authoring or updating the pack contents for a Sync360 skill

This file now serves two roles:

- framework/spec for how skills fit the Sync360 ecosystem
- copy-pasteable authoring prompt for generating the actual pack contents

Companion artifacts:

- starter pack: `resources/skill-packs/examples/starter-skill-module/`
- handoff checklist: `resources/skill-packs/SYNC360-DEV-AGENT-HANDOFF-CHECKLIST.md`

## 1. Design Goal

A Sync360-compatible skill module must behave like a platform module, not just a prompt bundle.

That means it must be:

- packageable
- catalogable
- assignable to tenants
- materializable into the OpenClaw workspace
- verifiable in the live runtime
- supportable by operators
- safe to roll out across many tenants

If any of those are missing, the skill is not yet ready for Sync360 production use.

## 2. Skill Compatibility Model

Every candidate skill must be classified into exactly one primary runtime model.

### `sync360_workspace`

Use this when the skill is primarily instructions, workflow, templates, module-local scripts, and file-local guidance that Sync360 materializes into:

`.openclaw/workspace/skills/<skill-id>/`

This model is appropriate when:

- the behavior can run with tools already available in the tenant runtime
- no new host binary, container mount, system dependency, or managed auth surface is required
- the skill mainly teaches the agent how to do a workflow reliably

### `openclaw_native`

Use this when the skill is provided by OpenClaw itself or another runtime-managed source outside Sync360 workspace materialization.

### `runtime_capability`

Use this when the skill depends on an external binary, mounted toolchain, host-managed auth/config, or other dependency that must exist outside the plain skill folder.

This model is appropriate when:

- the skill needs a real executable renderer, generator, browser, or service client
- the dependency must be installed, mounted, and verified by Sync360
- the skill is not reliable if it only exists as instructions

Examples:

- `gog`
- a future PDF rendering toolchain
- a CRM module that needs a real provider SDK, auth mount, or managed integration surface beyond local helper files

## 3. Two-Layer Rule: Behavior vs Execution

Sync360 treats skill behavior and execution capability as separate contracts.

### Layer A: Behavior Contract

Defined by the skill pack:

- what the skill is for
- when it should activate
- what workflow it follows
- what outputs it should produce
- what side effects are required

### Layer B: Execution Contract

Defined by the runtime capability surface:

- which tools, binaries, or libraries actually exist
- what auth/config is available
- how Sync360 verifies live readiness
- whether the tenant runtime can really perform the promised action

Important rule:

- a skill being present on disk is not proof that the runtime can perform the action
- a skill being assigned in the catalog is not proof that the runtime has activated it
- a customer-facing workflow is only production-ready when both the behavior contract and execution contract are satisfied

## 4. Required Package Layout

Every external skill module must ship in a normalized pack layout:

```text
<skill-id>/
  manifest.json
  SKILL.md
  agent-instructions.md
  RELEASE_NOTES.md
  docs/
  scripts/
  templates/
  assets/
  examples/
  vendor/
```

Rules:

- `manifest.json` is required
- `SKILL.md` is required
- `agent-instructions.md` is required
- `RELEASE_NOTES.md` is required
- optional richer guidance belongs under `docs/`
- reusable templates and examples should be explicit rather than embedded as long prose inside `SKILL.md`
- helper scripts that the agent is expected to run should live under `scripts/`
- module-local libraries, packaged helpers, or vendored runtime assets that are part of the skill pack should live under a clearly named folder such as `vendor/`, `lib/`, or another explicit module-local root
- do not nest `skills/<skill-id>/` inside the pack

Inside this repo, the canonical location is:

`resources/skill-packs/<skill-id>/`

External repos should preserve the same root-level structure so Sync360 import remains deterministic.

## 5. Scripts, Templates, And Module-Local Libraries

Sync360 skill modules may include executable helpers and local supporting code when that makes the workflow more reliable.

Allowed examples:

- `scripts/*.sh` wrappers for deterministic command assembly
- `scripts/*.js` or `scripts/*.php` helpers for payload shaping or structured export
- module-local templates for email, document, CRM note, or PDF source generation
- vendored helper libraries or SDK fragments that are intentionally shipped with the module

Rules:

- scripts must be intentional, documented, and directly tied to the skill workflow
- `SKILL.md` must say when to call the script, what inputs it needs, what output/success looks like, and what to do on failure
- if a script depends on a runtime that may not exist in all tenants, that dependency must be declared explicitly
- module-local libraries do not magically become platform capabilities; if the workflow still depends on a system binary, renderer, browser, auth mount, or provider runtime outside the pack, that remains a `runtime_capability` concern
- local helper code may shape content, files, or requests, but it must not bypass Sync360’s approved external side-effect surfaces
- credentials must not be embedded in scripts, templates, vendored libraries, or examples
- if a module ships vendored code, operator docs must say what it is, why it is included, and how it is expected to run in tenant environments

Practical rule of thumb:

- if the module can carry the helper with it and run using runtimes already guaranteed in the tenant environment, it can stay a workspace skill
- if the module needs Sync360 to install or verify a binary, renderer, browser, auth mount, or provider runtime outside the pack, it also needs a runtime-capability contract

## 6. Manifest Standard

Every skill pack must declare a stable manifest contract.

Minimum required fields:

- `skill_id`
- `version`
- `label`
- `description`
- `category`
- `runtime_type`
- `production_ready`
- `openclaw_skill_ids`
- `default_agent_skill_ids`
- `applicable_industries`
- `analytics`

Additional framework rules:

- `skill_id` must be lowercase kebab-case and stable forever
- `version` must increment for any material behavior, metadata, docs, operator-facing contract, script contract, or dependency-contract change
- `runtime_type` must describe the actual operating model, not aspiration
- `openclaw_skill_ids` and `default_agent_skill_ids` must align exactly with the real activation path
- if the skill depends on another runtime capability, that dependency must be declared explicitly in the pack contract and supporting docs

## 7. Runtime Dependency Declaration

External skills must clearly state whether they are:

- instructions-only
- instructions plus existing runtime tools
- dependent on a required runtime capability

For every dependency, define:

- dependency name
- dependency type: native skill, mounted binary, library/toolchain, managed auth, external integration, or module-local helper
- whether Sync360 provides it today
- what verification proves it is ready
- what the skill must do if it is missing

If the dependency is required for business completion, the skill must fail closed rather than silently degrade into imaginary success.

## 8. Activation Contract

Every skill module must be activation-safe in the live runtime.

That means:

- materialization path is deterministic
- allowlist ids match the real skill id
- `agent-instructions.md` points to the exact materialized `skills/<skill-id>/SKILL.md`
- Sync360 can verify the skill through the live runtime skill surface
- trigger delivery can be blocked if the required skill is not truly active

Activation checklist:

- `skill_id`
- materialized folder name
- `openclaw_skill_ids`
- `default_agent_skill_ids`
- `agent-instructions.md` pointer
- runtime verification output

All of those must align exactly.

## 9. Workflow Contract Standard

`SKILL.md` must define an operationally complete workflow.

Every workflow needs:

- activation conditions
- ordered steps
- branch rules
- idempotency key(s)
- source-of-truth ids from the triggering system
- required side effects
- success criteria
- failure criteria
- final reporting contract

Required side effects must never be implied vaguely.

Do not write:

- "notify the team"
- "log it"
- "follow up with the customer"
- "create the document"

unless the skill also specifies:

- the exact tool or command family
- minimum payload fields
- validation signal
- failure handling
- privacy limits
- dedupe key

## 10. Execution Truthfulness Rules

A Sync360-compatible skill must be evidence-based.

Rules:

- required actions must be executed in the current run when the workflow branch requires them
- future-intent language is invalid for required runtime actions
- the final summary must reflect actual tool/command outcomes
- required side effects must report explicit states such as `succeeded`, `skipped_with_reason`, and `failed_with_error`

The runtime must never claim `sent`, `drafted`, `uploaded`, `logged`, `created`, or `synced` without matching execution evidence in the same run.

## 11. Customer-Facing Messaging Standard

If a skill sends customer-facing text, it must define the copy contract explicitly.

Required rules:

- tone source must be named
- formatting must be named
- forbidden formatting artifacts must be named
- send vs draft behavior must be named

Default Sync360 standard for plain-text business messaging:

- follow the tenant onboarding tone and workspace voice sources
- use concise, plain language
- prefer one paragraph unless the business workflow needs another format
- forbid literal escape sequences such as `\n`, `\r`, and `\t`
- default to clean ASCII-safe copy
- avoid decorative special characters, emoji, markdown, smart quotes, and ornamental formatting unless exact business text requires them

## 12. Analytics And Privacy Standard

If the skill emits analytics, it must use the Sync360 analytics contract.

Requirements:

- emit only after authoritative success
- use the Sync360 helper rather than direct DB writes
- keep `skill_key` and `skill_version` aligned with the manifest
- store minimal customer data
- prefer masked contact references
- avoid raw private transcripts unless absolutely necessary

Operational logs, drafts, notifications, and intent are not proof of conversion unless the business outcome rules say they are.

## 13. Operator Support Standard

Every external skill must be supportable by an operator who did not author it.

That means the skill pack should make clear:

- what the skill does
- what it depends on
- what healthy looks like
- what common failures look like
- what evidence proves success
- what evidence proves the runtime is missing a dependency
- what should trigger human follow-up

Recommended docs under `docs/`:

- `OPERATIONS.md`
- `DEPENDENCIES.md`
- `EXAMPLES.md`
- `TESTING.md`

Keep them concise and practical.

## 14. Rollout Standard

Every skill module must be safe to publish and roll out.

Required rollout lifecycle:

1. package validation
2. catalog scan/import
3. publish a version
4. assign to a tenant
5. apply to materialize runtime files
6. verify live activation
7. verify a real workflow transcript
8. roll out intentionally to broader tenants

Rules:

- publishing a catalog version is not the same as live tenant adoption
- existing tenant assignments are version-pinned until rollout updates them
- if a changed skill affects runtime behavior, version bump is mandatory
- release notes must tell operators why the rollout matters

## 15. Verification Standard

Every external skill must ship with a minimum verification plan.

Baseline verification:

- manifest scan passes
- import passes
- release notes include the current version
- materialization path is correct
- no nested `skills/<skill-id>/skills/<skill-id>/`
- runtime id alignment is exact
- live runtime verification sees the skill or dependency when expected

Behavior verification:

- at least one happy-path regression
- at least one missing-dependency or fail-closed regression when relevant
- proof that required side effects were executed, not only summarized
- proof that source-of-truth ids are preserved through follow-up actions

Messaging verification:

- customer-facing copy matches the declared tone source
- literal escape sequences are absent
- formatting rules are respected

Runtime-capability verification:

- host-level install or readiness proof
- container/runtime visibility proof
- one real end-to-end smoke path using the actual dependency

## 16. Decision Tree For New Skills

Use this decision tree before authoring:

1. Is the skill mostly instructions, templates, and local helpers?
   - yes -> start with `sync360_workspace`
2. Does it require a real binary, renderer, browser, auth surface, system library stack, or mounted toolchain outside the pack?
   - yes -> define a `runtime_capability` plan too
3. Is the capability already provided by OpenClaw outside Sync360 workspace materialization?
   - yes -> use `openclaw_native`
4. Can Sync360 verify live readiness before delivery?
   - if no, the skill is not ready for production automation
5. Can operators understand rollout, failure modes, and support expectations?
   - if no, the module is not ready for catalog publication

## 17. External Skill Acceptance Checklist

Before an externally developed skill joins Sync360, confirm all of these:

- package structure matches the framework
- manifest contract is complete
- runtime model is correctly classified
- dependencies are explicit
- activation ids are aligned
- workflow contracts are operationally complete
- side effects are verifiable
- customer-facing copy rules are explicit where applicable
- analytics/privacy rules are satisfied
- operator docs exist where needed
- scan/import succeeds
- live runtime activation can be verified
- regression coverage exists for the core workflow
- release notes explain the current version clearly

If any item is missing, the skill should be treated as draft or internal-only rather than production-ready.

## 18. Remote Team Starter And Handoff

When a remote team is building modules outside this repo, give them all three of these:

1. this framework
2. the starter pack in `resources/skill-packs/examples/starter-skill-module/`
3. the delivery checklist in `resources/skill-packs/SYNC360-DEV-AGENT-HANDOFF-CHECKLIST.md`

The framework explains the rules.
The starter pack gives them the folder and file skeleton.
The handoff checklist tells them exactly what evidence and answers must come back with the module.

## 19. Authoring Prompt

Use the prompt below when creating or updating a Sync360 custom skill pack. It defines the platform criteria for repo layout, runtime behavior, analytics, tracking, privacy, and verification.

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
- Optional helper scripts belong under `scripts/`.
- Optional reusable templates/assets/examples should live under explicit top-level folders such as `templates/`, `assets/`, or `examples/`.
- Optional module-local libraries or vendored helper code should live under an explicit folder such as `vendor/` or `lib/`, and the skill must document what they are for.
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
- If the skill ships module-local scripts, templates, or helper libraries, document whether they run entirely within the existing tenant runtime or whether they still depend on an external runtime capability. Do not present local helper code as proof that an external binary, provider SDK, browser, renderer, or auth surface exists.

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
- If the skill depends on a module-local script or helper library, name the exact script path or helper entrypoint and define its success/failure contract the same way you would for any other required tool.
- Do not hide critical behavior in opaque helper code. `SKILL.md` must still explain what the helper does, what data it touches, and how the agent validates the outcome.
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
- If the skill includes module-local scripts or helper libraries, verify at least one path showing the agent used the intended module-local entrypoint and still respected the declared side-effect/output contract.
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
