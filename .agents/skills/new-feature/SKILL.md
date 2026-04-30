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

## Guardrails

- Keep `goLive()` workspace-only unless the initiative explicitly changes that boundary.
- Preserve control-plane source of truth for runtime config, credentials, and auth state.
- Avoid backend/platform leakage in customer-facing copy.
- Update canonical docs only when durable behavior changes.
