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
- Report the event_id and conversion_id back to the user so they can verify it on the dashboard.

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

### Response validation
- Inspect the helper's JSON output after running the command.
- Treat the conversion as successful **only** when the output contains `"ok": true` and an `event_id`.
- If the helper fails or returns non-JSON output, report the exact command error/output to the user instead of claiming the conversion succeeded.

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
