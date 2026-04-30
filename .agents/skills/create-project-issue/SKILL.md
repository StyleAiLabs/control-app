---
name: create-project-issue
description: Create a Sync360 GitHub issue and add it to the Sync360 GitHub Project with the correct workflow metadata. Use when work is agreed and needs a real initiative, slice, or spike issue before implementation begins.
user-invocable: true
argument-hint: "[initiative, slice, or spike description]"
---

Create project tracking before implementation starts.

## Workflow

1. Confirm the work type from the request.
   - `Initiative` for new feature or multi-slice work
   - `Slice` for one concrete implementation chunk
   - `Spike` for uncertainty reduction or architectural decision work
2. Convert the request into issue-ready structure.
   - title
   - why / problem
   - success criteria or definition of done
   - constraints / invariants
   - scope
   - dependencies
   - verification
3. Create the GitHub issue in `StyleAiLabs/control-app`.
   - Apply exactly one framework label based on the work type:
     - `Initiative` created from `/new-feature` -> `/new-feature`
     - `Slice` or bugfix created from `/bugfix` -> `/bugfix`
     - `Spike` or refactor initiative created from `/architecture-refactor` -> `/architecture-refactor`
4. Add it to the `Sync360` GitHub Project.
5. Set the project metadata explicitly.
   - `Workflow Stage`
   - `Work Type`
   - `Verification Status`
6. Report back with:
   - issue URL
   - project placement
   - recommended next branch name if implementation should begin

## Default Project Mapping

- `Initiative` -> `Workflow Stage: Intent Ready`, `Verification Status: Not Planned`
- `Slice` -> `Workflow Stage: Slice Ready`, `Verification Status: Planned`
- `Spike` -> `Workflow Stage: Intent Ready`, `Verification Status: Not Planned`

## Required Labels

Every issue created through this workflow must have exactly one of these framework labels:

- `/new-feature`
- `/bugfix`
- `/architecture-refactor`

## Branch Hint

After creating the issue:

- recommend one branch per approved initiative
- if the item is a slice under an existing initiative, recommend the initiative branch unless isolation is needed
- if the item is a standalone slice or bug, recommend a dedicated branch only when it does not belong on an existing initiative branch

## Guardrails

- Do not create duplicate issues when one already exists for the same work.
- Prefer concise, implementation-ready issues over vague placeholders.
- Keep the issue aligned with the kanban-first framework in `artifacts/AGENTIC_DEVELOPMENT.md`.
- If the work is being parked for later pickup, still create or update the issue and apply the framework label.
