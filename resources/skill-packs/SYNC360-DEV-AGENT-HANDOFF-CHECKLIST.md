# Sync360 Dev Agent Handoff Checklist

Use this checklist when a remote team or dev agent hands a custom skill module back to the Sync360 team.

This checklist is the delivery gate that sits next to:

- [SYNC360-SKILL-FRAMEWORK.md](/Users/gayanhewage/Projects/openclaw-saas/resources/skill-packs/SYNC360-SKILL-FRAMEWORK.md)
- [starter-skill-module](/Users/gayanhewage/Projects/openclaw-saas/resources/skill-packs/examples/starter-skill-module/README.md)

## 1. Delivery Package

The handoff must include:

- full skill-pack folder
- release notes for the delivered version
- any supporting docs
- any helper scripts or local libraries used by the module
- one short implementation summary
- one short dependency summary
- one short verification summary

## 2. Required Questions The Dev Agent Must Answer

The handoff must answer all of these clearly:

1. What is the canonical `skill_id`?
2. What runtime model does this module use?
3. Does it need a real `runtime_capability` in addition to the workspace pack?
4. What exact external side effects does it perform?
5. What exact provider ids or business ids are the source of truth?
6. What must be true before Sync360 can safely deliver triggers to this skill?
7. What evidence proves a successful run?
8. What are the main failure modes?

## 3. Structure Checklist

- pack contains `manifest.json`
- pack contains `SKILL.md`
- pack contains `agent-instructions.md`
- pack contains `RELEASE_NOTES.md`
- optional docs/helpers are in explicit folders
- no nested `skills/<skill-id>/` folder exists

## 4. Manifest Checklist

- `skill_id` is stable lowercase kebab-case
- `version` is present and intentional
- `runtime_type` matches reality
- `openclaw_skill_ids` align with activation expectations
- `default_agent_skill_ids` align with activation expectations
- analytics contract is explicit

## 5. Workflow Contract Checklist

- trigger conditions are clear
- exclusions are clear
- branches are explicit
- required side effects are explicit
- idempotency key is defined
- source-of-truth ids are defined
- final reporting contract is explicit
- future-intent language is not used for required runtime actions

## 6. Scripts And Local Library Checklist

If the module includes scripts or local libraries, the handoff must include:

- exact file paths
- what each helper does
- what runtime each helper expects
- whether the helper is optional or required
- what output proves the helper succeeded
- why the helper does or does not require a separate runtime capability

## 7. Customer-Facing Messaging Checklist

If the skill sends customer-facing messages:

- tone source is named
- formatting contract is named
- escape sequences are forbidden where required
- special-character rules are explicit
- send vs draft behavior is explicit

## 8. Dependency Checklist

For each dependency, the handoff must state:

- dependency name
- dependency type
- whether Sync360 already provides it
- whether the remote team expects Sync360 to install or verify it
- what should happen if it is missing

## 9. Verification Checklist

The handoff must include evidence for:

- one happy path
- one failure or missing-dependency path when relevant
- proof that required side effects executed, not just that the skill planned them
- proof that any module-local helper was actually used when it is part of the workflow

## 10. Final Handoff Format

The remote dev agent should deliver:

### Summary

- what the module does
- what runtime model it uses
- whether it is ready for import only or also needs runtime-capability work

### Files

- list of delivered files and folders

### Dependencies

- explicit dependency list

### Verification

- exact tests, transcripts, or manual checks performed

### Risks

- what is still unknown
- what Sync360 must implement before production rollout
