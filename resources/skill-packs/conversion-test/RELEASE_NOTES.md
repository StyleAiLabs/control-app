# Conversion Test — Release Notes
## 1.1.1

- production ready : true

## 1.1.0

- Added helper response validation to SKILL.md — the agent must check for `"ok": true` and an `event_id` in the helper output before claiming conversion success, and must surface command errors to the user.

## 1.0.0

- Initial release of the conversion test skill.
- Emits a single `test_completed` conversion event to verify the Sync360 analytics pipeline end-to-end.
- No external integrations required — can be tested immediately after import.
