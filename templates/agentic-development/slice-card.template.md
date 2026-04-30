# Slice Card Template

## Title

`<short vertical slice name>`

## Why

- user/operator/business outcome:

## Definition Of Done

- `<observable result>`

## Scope

In:

- `<what changes>`

Out:

- `<what does not change>`

## Interfaces

- routes:
- commands/jobs:
- schema/fields:
- events:
- workspace/runtime artifacts:
- UI states or operator surfaces:

## Constraints / Invariants

- `<general behavioral invariants>`

## Runtime Boundary Check

- does this preserve the current runtime boundary?
- if not, what explicit approved boundary change is being made?

## Tenant Safety Check

- could this regenerate, overwrite, or desync tenant credentials or runtime auth?
- what guardrail prevents that?

## Verification

- exact tests:
- exact manual checks:
- exact admin/customer evidence:

## Dependencies

- blocking slices:
- required env/data/setup:

## Canonical Updates

- `artifacts/MEMORY.md`: `<yes/no and why>`
- `artifacts/ARCHITECTURE.md`: `<yes/no and why>`
- `artifacts/RELEASE_NOTES.md`: `<yes/no and why>`

## Operational Evidence

- what evidence proves the slice worked in reality?
- where will that evidence appear?

## Implementation Notes

- likely code paths:
- follow-up risks:
- surprises discovered during execution:
