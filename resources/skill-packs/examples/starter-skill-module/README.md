# Sync360 Starter Skill Module Pack

This is a starter pack for remote teams building Sync360-compatible skill modules outside this repo.

It is intentionally nested under `resources/skill-packs/examples/` so Sync360 catalog scanning does not treat it as a live importable skill.

## What this pack is for

Use this starter when a remote team needs:

- the expected folder structure
- starter file shapes
- placeholders for workflow contracts
- examples of scripts, templates, and operator docs
- a predictable handoff back to the Sync360 team

## How to use it

1. Copy this folder into a new external repo or working directory.
2. Rename the folder to the real `<skill-id>`.
3. Rename `*.template.*` files to their real names.
4. Replace all placeholder values.
5. Remove any folders you do not need.
6. Keep any helper scripts, templates, and docs that genuinely support the workflow.
7. Validate the finished pack against:
   - [SYNC360-SKILL-FRAMEWORK.md](/Users/gayanhewage/Projects/openclaw-saas/resources/skill-packs/SYNC360-SKILL-FRAMEWORK.md)
   - [SYNC360-DEV-AGENT-HANDOFF-CHECKLIST.md](/Users/gayanhewage/Projects/openclaw-saas/resources/skill-packs/SYNC360-DEV-AGENT-HANDOFF-CHECKLIST.md)

## Included files

- `manifest.template.json`
- `SKILL.template.md`
- `agent-instructions.template.md`
- `RELEASE_NOTES.template.md`
- `docs/OPERATIONS.template.md`
- `docs/DEPENDENCIES.template.md`
- `scripts/example-helper.template.sh`
- `templates/customer-message.template.txt`

## Important

This starter pack is a scaffold, not a publishable skill. Do not import it into the catalog as-is.
