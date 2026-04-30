---
name: new-feature
description: Shape and execute new Sync360 feature work through the kanban-first framework. Use when the request is a new feature, capability, workflow, UI addition, or multi-slice product change that should start as an initiative.
user-invocable: true
argument-hint: "[feature requirement]"
---

Treat the request as a framework initiative unless the user explicitly says it is already a single slice.

## Workflow

1. Read the current repo truth before proposing work.
   - Start with `README.md`, `artifacts/MEMORY.md`, `artifacts/ARCHITECTURE.md`, and `artifacts/AGENTIC_DEVELOPMENT.md` when they are relevant.
   - Search the codebase for the current implementation shape before asking questions.
2. Convert the user request into initiative language.
   - Problem
   - Why now
   - Success criteria
   - Constraints / invariants
   - Out of scope
   - Known context
3. Produce an initiative-ready summary.
   - Write the initiative in a form that could become a GitHub issue or initiative card.
   - If the request is still vague, ask only the smallest high-impact clarification questions that cannot be answered from the repo.
4. Break the initiative into a thin vertical slice DAG.
   - Prefer user-visible or operator-visible slices.
   - Avoid horizontal phases like `DB first`, `API second`, `UI last`.
   - Turn uncertainty into a spike instead of bloating a slice.
5. Recommend the first slice.
   - Explain why it is the safest or highest-leverage starting point.
   - Default to implementing only the first approved slice unless the user explicitly asks for more.
6. Before implementation starts, make the implementation container explicit.
   - Create or reuse the GitHub initiative issue when the work is not yet tracked.
   - Create or reuse the initiative branch before changing code.
   - Record the branch or worktree relationship on the initiative or slice card when possible.

## Output Contract

When shaping a feature, provide:

- initiative title
- problem
- why now
- success criteria
- constraints / invariants
- out of scope
- initial slice DAG
- recommended first slice

When the user asks to implement, implement only the first approved slice by default.

## Branch Rule

- Default to one branch per approved initiative.
- Multiple slices may live on that initiative branch when they belong together.
- Split to a child branch or separate worktree only when a slice becomes risky, blocked, or needs parallel implementation.
- Do not start implementation on the current branch by accident. Create or confirm the initiative branch first.

## Issue Rule

- Treat the GitHub issue or initiative card as part of the required setup, not optional after-the-fact bookkeeping.
- If the feature is not yet represented in the project, create the issue before implementation or explicitly say why you are not doing so.

## Guardrails

- Keep `goLive()` workspace-only unless the initiative explicitly changes that boundary.
- Preserve control-plane source of truth for runtime config, credentials, and auth state.
- Avoid backend/platform leakage in customer-facing copy.
- Update canonical docs only when durable behavior changes.
