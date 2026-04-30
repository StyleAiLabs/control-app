# Portable Agentic Development Playbook

> [!IMPORTANT]
> This playbook is the project-agnostic version of Sync360's operating model. Use it when starting a new repo, onboarding collaborators, or sharing the method outside this codebase.

## Core Principles

- keep agents in the smart zone with small sessions and small slices
- treat the board as the operating system, not the chat transcript
- default to vertical slices
- separate intent shaping from implementation
- put humans at high-leverage gates: initiative approval and merge/rollout review
- require fast feedback loops through tests, type checks, UI evidence, or operational proof
- prefer reset over long-context compaction
- use docs as stable bootstrap artifacts, not as a substitute for a board

## Standard Workflow

1. capture the initiative
2. grill the request until success criteria are concrete
3. write one initiative card
4. break the work into dependency-aware vertical slices
5. make each slice decision complete
6. let an agent implement one slice at a time with fresh context
7. run verification
8. require human review before merge or ship
9. update durable docs only for long-lived truth
10. archive stale planning artifacts

## Default Branching Model

Use branches as implementation containers, not as substitutes for cards.

Default rule:

- one branch per initiative
- multiple slices may be completed on that branch when they belong to the same approved initiative
- if a slice becomes risky, blocked, or needs parallel implementation, split it to a child branch or separate worktree

Why this default works:

- it keeps branch count lower than one-branch-per-slice policies
- it preserves initiative-level review context
- it still gives isolation when risk or parallelism actually justifies it

Record branch exceptions on the work item so humans and agents can see whether the slice is on the initiative branch or a child branch/worktree.

## Standard Board Shape

Recommended baseline columns:

1. `Inbox`
2. `Intent Ready`
3. `Slice Ready`
4. `In Progress`
5. `Review`
6. `Merge Ready`
7. `Rollout / Verify`
8. `Done`
9. `Archived`

Projects may rename columns, but should preserve the same handoff points:

- intake
- clarified intent
- decision-complete execution
- active work
- review
- integration readiness
- real-world verification
- historical closure

## Required Templates

Every project should define:

- board columns
- initiative card template
- slice card template
- optional design packet template
- human review gates
- required verification types
- durable docs list
- project invariants checklist

Minimum slice card template:

- goal
- user-visible or operator-visible outcome
- scope
- interfaces touched
- acceptance checks
- dependencies
- invariants
- evidence required for done

## Defaults For New Projects

If a new project has no established process yet, start with these defaults:

- board-first workflow
- vertical slices as the execution grain
- one branch per initiative
- multiple slices allowed on the initiative branch when they belong together
- child branch or separate worktree only for risky, blocked, or parallel slices
- one human gate before slice queueing
- one human gate before merge/rollout
- short-lived implementation sessions
- TDD or explicit fast verification loops
- lightweight initiative docs, not heavyweight specs by default
- archived planning notes marked stale once the work is complete

## Smells To Correct Early

Intervene when you see these patterns:

- cards are horizontal layers instead of vertical outcomes
- agents need long chat history to keep working
- review comments live only in chat and not on the work item
- initiative docs try to replace the board
- slices repeatedly require mid-flight decision making
- rollout verification is assumed instead of recorded
- historical docs are treated as live truth

## Adoption Advice

Pilot the method on 2-3 initiatives before standardizing it broadly.

After the pilot, review:

- slice size
- handoff clarity
- verification quality
- review bottlenecks
- documentation weight
- whether humans were involved at the right points
