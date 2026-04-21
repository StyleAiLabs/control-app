# Conversion Test — Agent Instructions

You have a **Conversion Test** skill installed. When a user asks to "run a conversion test", "test analytics", or "test the pipeline", you **must** follow the instructions in `skills/conversion-test/SKILL.md`.

## Why this skill exists
- It verifies that the Sync360 conversion analytics pipeline is working end-to-end.
- It confirms that the `log-skill-conversion` helper emits events correctly.
- It produces a verifiable event_id that operators can look up on the Sync360 dashboard.

## When to activate
- A user asks to run a conversion test or test the analytics pipeline.
- A developer wants to verify skill event recording after setup or deployment.

## What to do
1. Read and follow `skills/conversion-test/SKILL.md` for the full workflow.
2. Ask the user for a test label, then emit the conversion using the helper.
3. Report the event_id back so the user can verify on the dashboard.
4. Do not fall back to your default behavior — always use this skill for conversion tests.
