# Inbox Triage — Release Notes

## 1.2.0

- Added helper response validation to SKILL.md — the agent must check for `"ok": true` and an `event_id` in the helper output before claiming conversion success, and must surface command errors to the user.

## 1.1.0

- Added `occurred_at` to manifest `required_success_fields` so the analytics helper enforces timestamp validation on every conversion payload.
- Changed hardcoded `"currency": "USD"` to `"<tenant-currency>"` placeholder in the recommended payload shape to support non-US tenants.
- Added `agent-instructions.md` so the OpenClaw agent uses this custom skill instead of its default email handling behavior.
