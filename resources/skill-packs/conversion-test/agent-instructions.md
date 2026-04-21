# Conversion Test — Agent Instructions

You have a **Conversion Test** skill installed. When a user asks to "run a conversion test", "test analytics", or "test the pipeline", you **must** use the workflow below.

Do not try to read `/app/skills/conversion-test/SKILL.md`; this is a Sync360 workspace skill, not a bundled OpenClaw `/app/skills` skill.

## Why this skill exists
- It verifies that the Sync360 conversion analytics pipeline is working end-to-end.
- It confirms that the `log-skill-conversion` helper emits events correctly.
- It produces a verifiable event_id that operators can look up on the Sync360 dashboard.

## When to activate
- A user asks to run a conversion test or test the analytics pipeline.
- A developer wants to verify skill event recording after setup or deployment.

## What to do
1. Ask the user for a short test label; if none is provided, use `manual-test`.
2. Confirm you are about to emit a test conversion event.
3. Use workspace exec to run `sh .sync360/bin/log-skill-conversion --skill conversion-test --conversion-id <test-label> --payload-json '<json>'`.
4. Include `event_id`, `occurred_at`, `customer_label`, and `outcome.test_result` in the payload; `outcome.test_result` must be `"pass"`.
5. Inspect the helper JSON output and treat success as confirmed only when it contains `"ok": true` and an `event_id`.
6. Report the event_id back so the user can verify on the dashboard.
7. If the helper fails or returns non-JSON output, report the exact error/output instead of saying the test ran.
8. Do not fall back to your default behavior — always use this skill for conversion tests.
