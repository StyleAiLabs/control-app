# Sync360 Kanban-First Agentic Development Framework

> [!IMPORTANT]
> This document defines the current repo-specific workflow for planning and delivering Sync360 work with humans and agents. The board is the live execution surface. This file exists to keep new sessions aligned, not to replace the board.

## Purpose

Sync360 uses a kanban-first operating model for agentic development:

- the board is the canonical live control surface
- each execution card is a thin vertical slice
- docs exist to preserve alignment at clean handoff points
- humans review at initiative approval and merge/rollout gates
- agents work in short, fresh-context sessions to stay in the smart zone

This framework is designed to reduce long-context drift, improve parallel agent throughput, and keep repo truth ahead of planning prose.

## Board Structure

Use one primary board with these columns:

1. `Inbox`
2. `Intent Ready`
3. `Slice Ready`
4. `In Progress`
5. `Review`
6. `Merge Ready`
7. `Rollout / Verify`
8. `Done`
9. `Archived`

Definitions:

- `Inbox`: raw ideas, bugs, feature requests, discoveries, or operator pain points
- `Intent Ready`: problem is clear enough to shape, but not yet broken into execution slices
- `Slice Ready`: decision-complete slice cards that an agent can pull without inventing requirements
- `In Progress`: active implementation owned by one agent or one human-agent pair
- `Review`: implementation complete and awaiting code review or behavior review
- `Merge Ready`: reviewed, green, and ready to integrate
- `Rollout / Verify`: merged or deployed and awaiting real verification evidence
- `Done`: verification recorded and required docs/memory updated
- `Archived`: closed, superseded, or intentionally stale work

## Initiative Shape

Every meaningful initiative should have:

- one initiative card
- one or more slice cards
- optional spike cards when uncertainty must be reduced before slicing
- an optional lightweight design packet only when the work is non-trivial

Initiative cards should capture:

- goal
- business reason
- success criteria
- constraints
- links to supporting docs or production evidence

Slice cards should map to user-visible or operator-visible value. Prefer examples like:

- onboarding dependency health visible in customer UI
- inbox-triage reply contract hardening with tests
- runtime cost sync guardrail plus admin evidence
- skill rollout auto-resync for live tenants

Avoid horizontal cards like `database layer`, `frontend pass`, or `controller cleanup` unless the work is strictly internal and still verifiable in isolation.

## Card Templates

Use these repo templates:

- [Initiative Card Template](../templates/agentic-development/initiative-card.template.md)
- [Slice Card Template](../templates/agentic-development/slice-card.template.md)
- [Design Packet Template](../templates/agentic-development/design-packet.template.md)

Repo-local helper skills are also available under `.agents/skills/`:

- `/new-feature` for initiative shaping and first-slice execution
- `/bugfix` for narrow slice-based fixes and regression work
- `/architecture-refactor` for spike-first architecture and refactor decisions
- `/create-project-issue` for creating initiative, slice, or spike issues directly in the Sync360 GitHub Project before implementation

Every `Slice Ready` card must be decision complete before implementation starts.

Required slice fields:

- `Why`
- `Definition of done`
- `Scope`
- `Interfaces`
- `Constraints / invariants`
- `Verification`
- `Dependencies`
- `Canonical updates`
- `Runtime boundary check`
- `Tenant safety check`
- `Operational evidence`

## Branch And Worktree Policy

Sync360's default implementation container is the initiative branch.

Branch rules:

- create one branch per approved initiative by default
- allow multiple slice cards on the same branch when they belong to that initiative
- do not create a new branch for every slice unless isolation is actually needed

Split work into a child branch or separate worktree when:

- a slice becomes risky enough that it should not destabilize the main initiative branch
- a slice is blocked and another slice from the same initiative needs to continue independently
- multiple agents need to implement disjoint slices in parallel
- review or rollback would be materially safer with isolation

Operational guidance:

- the board card is the source of truth for what the branch is supposed to contain
- the initiative branch is the default merge target for child slice branches or parallel worktrees
- when a slice branches off for isolation, record that branch/worktree relationship on the card
- when the initiative completes, archive the temporary planning artifact but keep the canonical behavior updates in code and core docs
- before implementation starts, create or confirm both the tracking issue and the initiative branch explicitly; do not rely on the current branch accidentally being correct

## Full Delivery Loop

### 1. Intake and intent shaping

For every new initiative:

1. start with a short grilling/shaping pass
2. convert the request into a clear initiative card
3. decide success criteria before discussing implementation details
4. split oversized work into a dependency-aware DAG of slices

Mandatory human gate:

- a human approves the initiative framing before slices move into `Slice Ready`

Outputs:

- initiative card
- optional design packet for non-trivial work

The design packet should be compact and board-supporting:

- problem
- intended outcome
- main decisions
- known risks
- initial slice DAG

### 2. Slice planning

Break each initiative into thin vertical slices with explicit dependencies.

Planning rules:

- each slice should fit inside one smart-zone implementation session where possible
- each slice should produce feedback through tests, UI evidence, logs, or operator surfaces
- uncertainty-heavy work becomes a spike, not an oversized implementation card
- if a slice requires multiple agents with non-trivial coordination, it is usually too large

### 3. Implementation loop

Each implementation session should be card-scoped and fresh-context:

1. start from the slice card plus only the minimum supporting repo context
2. read current code and canonical docs, not stale chat history
3. restate invariants and required verification
4. implement with TDD or an explicit fast feedback loop when practical
5. update the card with outcomes, surprises, and follow-up work
6. hand off to review

Subagents are appropriate only for bounded, non-overlapping side tasks:

- code-path exploration
- schema or contract validation
- disjoint slice implementation
- review evidence gathering

Branching during implementation should follow the initiative policy:

- default to the initiative branch
- keep related slices together on that branch when they share the same approved initiative
- move a risky or parallel slice to a child branch or separate worktree rather than forcing all work through one mutable branch state

Do not keep implementation sessions alive longer than needed. Prefer reset-and-restart from the card and fresh repo truth.

### 4. Review and merge gate

Mandatory human gate:

- human review is required before merge or rollout

Review should focus on:

- behavior regressions
- invariant violations
- hidden coupling
- test quality
- operational risk
- whether the slice actually achieved its intended visible outcome

A slice moves to `Merge Ready` only when:

- acceptance checks pass
- reviewer concerns are addressed
- required canonical updates are identified
- no open ambiguity remains for rollout

### 5. Rollout and verification

Rollout cards must explicitly state:

- local verification
- tenant/runtime safety concerns
- admin/customer evidence path
- production or staging observation needed to confirm success

Examples of acceptable evidence:

- admin heartbeat timestamp updates correctly
- inbox monitor transitions show expected state
- dependency health recomputes correctly after reconnect
- cost observability rows appear and reconcile

Work moves to `Done` only after verification evidence is recorded on the card.

## Documentation Rules

Artifact hierarchy:

1. board card
2. design packet
3. canonical docs
4. archived notes

Repo rules:

- keep design packets short and link them from the initiative card
- archive completed initiative notes rather than pretending they remain live control surfaces
- do not let historical roadmap notes compete with canonical docs for current truth
- write docs so a fresh agent can use them as clean bootstrap context

When behavior changes, consider whether updates are needed in:

- [`artifacts/MEMORY.md`](MEMORY.md)
- [`artifacts/ARCHITECTURE.md`](ARCHITECTURE.md)
- [`artifacts/RELEASE_NOTES.md`](RELEASE_NOTES.md)

## Sync360 Planning Invariants

Every planning and review artifact should remind agents of these repo-specific invariants:

- `goLive()` must remain workspace-only unless an initiative explicitly changes that boundary
- credential generation, runtime auth seeding, and compose regeneration must follow stored control-plane truth
- Google Workspace / `gog` / runtime capability work must respect the host-managed capability model
- customer-facing copy should avoid backend platform leakage
- expired-trial policy, inbox polling, runtime replies, and LiteLLM activity are separate control surfaces
- historical docs are not safe as current truth unless confirmed against code plus canonical docs
- operator-impacting changes need observable evidence in admin/customer surfaces or logs

## Pilot Adoption

Validate this framework on the next 2-3 real initiatives.

Pilot success criteria:

- each initiative is split into vertical slices instead of horizontal phases
- at least one slice is completed in a fresh-context implementation session
- review comments are attached to card outcomes instead of being lost in chat
- canonical docs are updated only when behavior changes
- at least one staging or production verification step is recorded on-card
- implementers do not need to invent missing requirements mid-slice

Review after the pilot:

- average slice size
- where agents got stuck
- which template fields were missing
- whether docs were too heavy or too light
- whether the review gates were placed correctly
