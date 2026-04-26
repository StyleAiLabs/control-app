---
name: Replace Me Skill
description: One-sentence description of the workflow.
metadata:
  openclaw:
    managedBy: Sync360
---

# Replace Me Skill

## When To Use

Use this skill when:

- trigger condition 1
- trigger condition 2

Do not use this skill when:

- exclusion 1
- exclusion 2

## Required Runtime References

- Source-of-truth provider id: `<provider-id>`
- Tenant workspace files:
  - `PROFILE.md`
  - `IDENTITY.md`
  - any other required local context

## Workflow

1. Validate the trigger and extract the source-of-truth ids.
2. Read only the tenant files needed for this workflow.
3. Decide which branch applies.
4. Execute every required side effect for that branch in the current run.
5. Validate each side effect from actual tool or command output.
6. Report final outcomes using explicit status fields.

## Branch Rules

### Happy Path

- Required side effects:
  - side effect 1
  - side effect 2
- Idempotency key:
  - `<provider-id>` or another stable business key
- Success criteria:
  - exact evidence that proves the branch completed

### Missing Data Path

- Ask for or gather the missing information safely.
- Do not invent missing business facts.
- If the skill is trigger-driven and cannot continue safely, fail with an exact reason.

### Draft-Only Or Human-Review Path

- Use this branch only when the business explicitly requires draft-only or review-first behavior.
- Report drafted/not-sent outcomes explicitly.

## Required Tool Contracts

For each required side effect, define:

- when to run it
- exact tool or command family
- required arguments or payload fields
- forbidden arguments or payload fields
- privacy limits
- idempotency key
- exact success signal
- exact failure handling

Example structure:

### External Action: Replace Me

- Run when:
  - exact condition
- Do not run when:
  - exact exclusion
- Tool:
  - `message`
  - `gog`
  - workspace `exec`
  - another approved runtime surface
- Minimum payload:
  - field 1
  - field 2
- Forbidden:
  - guessed flags
  - unrelated fields
- Success validation:
  - exact command/tool evidence
- Failure handling:
  - report exact error and stop or branch safely

## Scripts And Module-Local Helpers

If this skill includes helper scripts or local libraries:

- name the exact script path or entrypoint
- explain what it does
- state what runtime it depends on
- say what output proves success
- do not hide critical behavior in the helper

Example:

- helper: `scripts/example-helper.sh`
- purpose: normalize payload fields into a deterministic plain-text export
- success signal: exits `0` and writes the expected file

## Customer-Facing Copy Rules

If the workflow sends customer-facing text:

- follow the onboarding tone and workspace voice source
- keep the message plain text unless the business requires otherwise
- do not include literal escape sequences such as `\n`, `\r`, or `\t`
- default to ASCII-safe copy
- avoid decorative special characters unless exact business text requires them

## Analytics Contract

- Emit analytics only after authoritative success.
- Use the Sync360 helper via workspace `exec`.
- Do not treat logging, drafts, or notifications as proof of business completion unless that is the actual success rule.

## Final Response Contract

Report:

- `workflow_status`
- `side_effect_status`
- `side_effect_reason`
- any required provider ids or references

Do not claim completion without matching tool or command evidence.
