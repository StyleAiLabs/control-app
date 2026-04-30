---
name: bugfix
description: Handle Sync360 bug reports as narrow slice work with reproduction, invariants, regression coverage, and focused verification. Use when the user reports incorrect behavior, a failure, regression, or safety issue that should be fixed as narrowly as possible.
user-invocable: true
argument-hint: "[bug report]"
---

Treat the request as a slice by default. Escalate to an initiative only when the bug clearly spans multiple independent fixes or unresolved product decisions.

## Workflow

1. Ground in repo truth first.
   - Find the real code path, tests, and any existing canonical notes.
   - Reproduce the bug or gather the strongest available evidence before changing code.
2. Restate the bug as a slice.
   - Why it matters
   - Current behavior
   - Desired behavior
   - Constraints / invariants
   - Narrow definition of done
3. Keep the fix tight.
   - Change the smallest safe surface.
   - Preserve unrelated behavior.
   - If the root cause points to a larger architecture issue, note it separately instead of silently expanding scope.
4. Add or tighten regression coverage.
   - Prefer direct tests around the broken path.
   - If a test is not practical, provide an explicit manual verification path.
5. Verify before claiming success.
   - Run the focused tests or checks that prove the bug is fixed.
   - Report the actual evidence, not confidence language.
6. Before implementation starts, make the work container explicit.
   - Ask the user for explicit confirmation before creating a new branch or writing code.
   - If the user is not ready to proceed, create or update the bug issue for later pickup instead of implementing.
   - Attach the fix to the current initiative issue/branch when it clearly belongs there.
   - Otherwise create or reuse a dedicated bug issue and branch only after implementation is confirmed.
7. After implementation is committed and pushed, update the issue.
   - Post a concise issue comment with the branch name, fix summary, verification evidence, and any remaining risk or follow-up.
   - Treat the comment as part of the fix handoff so the issue stays current without reading git history.
8. After commit and push, create the pull request and cross-link the issue.
   - Open or update a pull request for the pushed branch.
   - Add the pull request link to the issue comment, or post a follow-up issue comment if needed.
   - Include the issue link in the pull request body so the fix thread and review thread stay connected.

## Output Contract

When shaping a bugfix, provide:

- slice title
- bug summary
- why it matters
- definition of done
- constraints / invariants
- likely code paths
- required verification

Before implementation, ask for explicit confirmation to proceed.

If confirmation is not given, stop after shaping and create or update the issue for later reference.

When implementing, keep the scope narrow and policy-aware.

After commit and push, update the issue comment before closing out the work.
Also create the pull request and link the issue to that pull request before closing out the work.

## Escalation Rule

Promote the work from `Slice` to `Initiative` or `Spike` only when:

- multiple independent fixes are required
- the intended behavior is ambiguous
- the fix depends on an architectural decision
- the bug is really exposing a missing product rule rather than broken code

## Branch Rule

- Put the bugfix on the current initiative branch when it clearly belongs to that initiative.
- Use a dedicated bugfix branch only when the work is separate from current initiative work.
- Split to a child branch or worktree if the fix becomes risky or needs parallel handling.
- Do not implement on an arbitrary branch without first confirming where the fix belongs.

## Issue Rule

- Reuse the existing initiative issue when the bug is part of that initiative.
- Create a dedicated bug issue when the fix is separate enough to stand on its own.
- Issues created from this skill should carry the `/bugfix` label.

## Guardrails

- Preserve expired-trial policy splits, runtime boundaries, and control-plane truth where relevant.
- Do not broaden a bugfix into a refactor without explicitly naming that scope change.
- Update canonical docs only if shipped behavior actually changes.
