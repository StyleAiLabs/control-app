---
name: architecture-refactor
description: Shape Sync360 architecture changes, boundary decisions, and refactors through a spike-first workflow. Use when the request is about structure, ownership, module boundaries, data modeling, service contracts, or refactoring code to improve clarity and safety.
user-invocable: true
argument-hint: "[architecture or refactor request]"
---

Treat the request as a spike first unless the desired implementation path is already decision-complete.

## Workflow

1. Inspect the current architecture before proposing changes.
   - Read the relevant code, `artifacts/ARCHITECTURE.md`, `artifacts/MEMORY.md`, and `artifacts/AGENTIC_DEVELOPMENT.md`.
   - Trace the real boundaries and invariants in code rather than trusting older docs alone.
2. Reframe the request as a decision problem.
   - What is wrong with the current shape?
   - What decision needs to be made?
   - What constraints or invariants limit the options?
3. Compare 2-3 viable approaches.
   - Lead with the recommended option.
   - Be explicit about tradeoffs, risks, migration cost, and agent-friendliness.
4. Produce a spike outcome.
   - Recommendation
   - Tradeoffs
   - Risks
   - Initiative draft
   - Initial slice DAG
5. Only move into implementation when the path is decision-complete.
   - Break the refactor into safe vertical slices.
   - Prefer boundary-preserving or behavior-preserving slices first.
6. Before implementation starts, create the tracking container.
   - Ask the user for explicit confirmation before creating a new branch or writing code.
   - If the user is not ready to proceed, create or update the issue for later pickup instead of implementing.
   - Create or reuse the initiative issue for the chosen direction.
   - Create or reuse the initiative branch only after the user confirms implementation should begin.
   - Split risky or parallel slices into child branches/worktrees only after the initiative branch exists.
7. After implementation is committed and pushed, update the tracking issue.
   - Post a concise issue comment with the branch name, refactor/spike outcome, verification evidence, and any remaining migration or rollout notes.
   - Treat the issue comment as part of the architecture handoff, not optional bookkeeping.

## Output Contract

When shaping architecture or refactor work, provide:

- spike title
- decision to make
- current pain
- options considered
- recommended option
- tradeoffs and risks
- initiative draft
- initial slice DAG

Before implementation, ask for explicit confirmation to proceed.

If confirmation is not given, stop after shaping and create or update the issue for later reference.

Do not jump straight into wide refactors without first making the decision explicit.

After commit and push, update the issue comment before closing out the work.

## Branch Rule

- Use one branch per approved initiative after the architecture direction is chosen.
- Keep multiple safe slices on that branch when they belong to the same refactor initiative.
- Use child branches or separate worktrees only when risky or parallel slices need isolation.
- Do not begin the refactor on the current branch unless that branch is already the approved initiative branch.

## Issue Rule

- The spike output should become a real initiative issue before refactor implementation starts.
- If the issue already exists, link the recommended slices back to it instead of creating duplicate tracking.
- Issues created from this skill should carry the `/architecture-refactor` label.

## Guardrails

- Prefer deeper, clearer modules over scattered shallow edits.
- Do not widen runtime, auth, or provisioning boundaries accidentally.
- Call out migrations, compatibility risks, and rollback concerns explicitly.
- Keep durable architecture truth in canonical docs once behavior or ownership actually changes.
