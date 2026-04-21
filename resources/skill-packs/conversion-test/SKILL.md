---
name: Conversion Test
description: A minimal skill to verify that the Sync360 conversion analytics pipeline works end-to-end.
metadata:
  openclaw:
    managedBy: Sync360
---

# Conversion Test

When an operator or developer asks you to "run a conversion test" or "test the analytics pipeline", use this skill to emit a single test conversion event and confirm it was recorded.

## Behavior
- Ask the user for a short test label (e.g., their name or "test-1"). If they don't provide one, use "manual-test".
- Confirm you are about to emit a test conversion event.
- Emit the conversion using the helper below.
- Read the helper JSON output.
- Report the event_id and conversion_id back to the user only after the helper returns JSON with `"ok": true`.
- If the helper fails or does not return JSON with `"ok": true`, report the exact command error/output and do not say the test completed.

## Analytics Contract

### When to emit
- Emit after the user confirms they want to run the test.
- Do not emit automatically without user confirmation.
- Emit exactly once per test run.

### Required invocation
Use the workspace exec tool to run:
```bash
sh .sync360/bin/log-skill-conversion --skill conversion-test --conversion-id <test-label> --payload-json '<json>'
```

The helper must return JSON similar to:
```json
{
  "ok": true,
  "mode": "log",
  "event_id": "test-<timestamp>",
  "conversion_id": "<test-label>",
  "skill_key": "conversion-test",
  "db_path": "/path/to/skill-events.sqlite",
  "inserted": true
}
```

### Required payload fields
Include all of these in the JSON payload:
- `event_id`: stable unique identifier (use `test-<timestamp>`)
- `occurred_at`: ISO-8601 timestamp
- `customer_label`: the test label provided by the user
- `outcome.test_result`: always `"pass"`

### Recommended payload shape
```json
{
  "event_id": "test-<timestamp>",
  "occurred_at": "<ISO-8601 timestamp>",
  "customer_label": "<test-label>",
  "contact_masked": null,
  "outcome": {
    "test_result": "pass"
  }
}
```

### Privacy note
- This skill is for testing only. No real customer data should be used.
- Never reply with "results shortly" or any other completion message unless the helper has already succeeded and you can include the returned `event_id`.
