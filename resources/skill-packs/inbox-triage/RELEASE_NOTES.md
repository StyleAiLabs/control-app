# Inbox Triage — Release Notes

## 1.5.6

- Made the Gmail message id the primary lead id for Gmail-triggered events, including Telegram `Lead ref`, Drive log naming, and analytics conversion ids.
- Added a top-level warning not to substitute Sync360 Job IDs or label variants such as `Lead Reference` for the exact `Lead ref: <gmail_message_id>` follow-up contract.
- Front-loaded the minimum analytics payload shape so runtime agents include `event_id` and the manifest-required outcome fields before invoking the helper.

## 1.5.5

- Front-loaded the critical Telegram, Drive, and analytics contracts so runtime agents see them even when they initially read only the top of `SKILL.md`.
- Explicitly forbade using workspace patch/file-edit tools as a substitute for Google Drive triage logging.
- Clarified that the workflow must continue to Drive and analytics after a successful Telegram notification.

## 1.5.4

- Added a strict Telegram send contract so high-value notifications use only the normal `message` send fields and never include poll-only fields.
- Simplified Google Drive triage logging to the smallest supported `gog drive upload <localPath>` command shape while forbidding unverified Drive flags.
- Clarified that analytics must be attempted independently for qualified leads and that inbox-triggered workflows must not use public web research unless the owner asks.

## 1.5.3

- Added `Lead ref: <gmail_message_id>` to the required high-value Telegram notification format, with optional thread reference.
- Added follow-up guidance telling the agent to read the replied notification, extract `Lead ref`, and use `gog gmail get <Lead ref>` instead of guessed Gmail searches.
- Added explicit fallback guidance to ask the owner to reply to the original notification again when no lead reference is available.

## 1.5.2

- Converted the loose Drive logging bullet into a required Google Drive triage-log workflow with folder/file naming, idempotency, privacy, command-shape, and validation guidance.
- Added an explicit ordered workflow so Telegram, Drive logging, analytics, and final reporting cannot be silently skipped.
- Clarified stable lead-id reuse across notification, Drive log naming, and analytics conversion ids.

## 1.5.1

- Added Sync360 inbox monitor trigger guidance so neutral `sync360-inbox-monitor` Gmail events are routed through this skill.
- Clarified that Sync360 polling does not classify emails as high-value; the skill remains responsible for category, lead quality, notification, logging, and analytics decisions.
- Reworded Gmail guidance so the skill evaluates delivered events and does not try to create its own watcher.

## 1.5.0

- Added Telegram notification step — agent sends a formatted alert to the pre-configured Telegram channel immediately when a lead is classified as high-value.
- Notification is one-per-lead only; medium/low/ambiguous leads are not notified.
- Telegram send failures are logged to the Google Drive triage log and do not block the triage workflow.

## 1.4.0

- Added Google Workspace Connection section to `SKILL.md` — agent must connect via GOG (pre-configured on the OpenClaw server) and verify the connection before monitoring begins.
- Made 24/7 monitoring contingent on an active GOG connection; agent must reconnect on drop rather than silently skipping emails.

## 1.3.0

- Replaced verbose `agent-instructions.md` with compact one-line index entry to prevent `agent.md` bloat when many skills are installed.
- Added `runtime_type: sync360_workspace` to manifest.

## 1.2.0

- Added helper response validation to SKILL.md — the agent must check for `"ok": true` and an `event_id` in the helper output before claiming conversion success, and must surface command errors to the user.

## 1.1.0

- Added `occurred_at` to manifest `required_success_fields` so the analytics helper enforces timestamp validation on every conversion payload.
- Changed hardcoded `"currency": "USD"` to `"<tenant-currency>"` placeholder in the recommended payload shape to support non-US tenants.
- Added `agent-instructions.md` so the OpenClaw agent uses this custom skill instead of its default email handling behavior.
