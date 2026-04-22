---
name: Inbox Triage
description: Monitor email inbox, identify high-value leads, and route qualified opportunities.
metadata:
  openclaw:
    managedBy: Sync360
  integrations:
    - gmail
    - google-drive
---

# Inbox Triage

When a customer inquiry is delivered from Gmail, use this skill to categorize the message, assess lead quality, flag high-value opportunities, notify the connected Telegram channel when appropriate, create a Google Drive triage log, emit analytics when appropriate, and suggest the next action (Quote Generation, Calendar Booking, or human follow-up).

Sync360 may deliver polled Gmail messages as internal inbox events from `sync360-inbox-monitor`. Treat those events as neutral triggers only. The trigger has not classified the email as high-value; you must decide the category, lead quality, and next action from this skill's instructions and the email context.

When handling an internal inbox event, use the email metadata/body and tenant workspace files as the source of truth. Do not use `web_search`, public web browsing, or public website research unless the owner explicitly asks you to research the sender or company.

## Google Workspace Context

This skill uses GOG (Google Workspace OAuth), which is pre-configured on the OpenClaw server, to inspect the referenced Gmail message when needed and to write Google Drive triage logs. Before taking Gmail or Drive actions:

1. **Use the configured GOG account** — Do not ask the owner to choose an account unless tooling explicitly reports multiple accounts or no default.
2. **Verify the connection before tool actions** — If GOG fails, stop and report the error to the operator instead of guessing from missing context.
3. **Do not create your own watcher** — Sync360 owns polling and de-dupe. This skill owns evaluation and follow-up after an email event is delivered.

## Required Workflow

Complete these steps in order for every delivered Gmail inquiry:

1. Evaluate the message.
   - Analyze inquiry content, sender details, company/domain context, urgency, and fit against the tenant's ideal customer profile.
   - Categorize the inquiry as sales inquiry, support request, quote request, appointment inquiry, spam, low-intent, ambiguous, or another clear category.
   - Decide `lead_quality` as `high`, `medium`, `low`, or `ambiguous`.
   - Flag high-value only when there is genuine buying intent and ICP fit. Do not flag spam, newsletters, generic form spam, or low-intent messages.
2. Build a stable lead id.
   - Prefer the Sync360 `Job ID` from the trigger when present.
   - Otherwise use the Gmail message id.
   - Reuse this id for Telegram idempotency reasoning, Google Drive log naming, and analytics `conversion_id`.
3. If `lead_quality` is `high`, send exactly one Telegram notification using the Telegram Notifications section.
   - The notification must include `Lead ref: <gmail_message_id>`.
   - Include `Thread ref: <gmail_thread_id>` when a thread id is available.
4. Create or verify the Google Drive triage log using the Google Drive Triage Log section.
5. Emit analytics independently when the lead meets the Analytics Contract. A Telegram or Google Drive failure must not block analytics.
6. Final response must summarize:
   - category
   - lead quality
   - suggested action
   - Telegram result when attempted
   - Google Drive log result or exact failure
   - analytics result or why analytics was not emitted

Do not report the workflow as complete until each required side effect has either succeeded or has an explicit captured failure.

## Telegram Notifications

The Telegram channel is pre-configured on the OpenClaw server. Send a notification immediately after classifying a lead as high-value.

**When to notify:**
- Only when `lead_quality` is `high`.
- Do not notify for medium, low, or ambiguous leads.
- Send exactly one notification per lead — do not re-notify on re-evaluation.

**Message format:**
```
🔔 High-Value Lead Detected

From: <company name or contact>
Domain: <email domain>
Category: <inquiry category>
Subject: <first 80 chars of subject line>
Lead ref: <gmail_message_id>
Thread ref: <gmail_thread_id or omit when unavailable>

Suggested action: <next step>
```

**Tool contract:**
- Use only the normal runtime `message` tool send path.
- Required fields:
  - `action`: `send`
  - `channel`: `telegram`
  - `target`: `<telegram_default_chat_id>`
  - `message`: the formatted notification body above
- Do not include poll-only or unrelated fields on a normal Telegram send, including `poll*`, `limit`, `pageSize`, `duration*`, buttons, interactive payloads, or poll options.
- If `telegram_default_chat_id` is missing or null, skip Telegram notification, record `telegram_skipped: missing_default_chat_id`, and continue to Drive logging and analytics when applicable.
- Treat the Telegram send as successful only when the tool result confirms the message was sent, such as an ok/success status or a Telegram message id.
- If the tool returns an error, capture the exact error and continue to Drive logging and analytics when applicable.

**Follow-up rule:**
- Treat `Lead ref` as the canonical handle for future owner follow-up requests.
- If the owner replies to this Telegram notification with requests such as `Get from email`, `Generate a quote`, `Draft reply`, or `Book site visit`, read the replied notification, extract `Lead ref`, and use `gog gmail get <Lead ref>` before asking for email details.
- Do not fall back to guessed Gmail searches when the notification already contains a `Lead ref`.
- If the owner did not reply to the original notification or the replied message does not contain `Lead ref`, ask them to reply to the original lead notification again or paste the lead reference.

**If the Telegram send fails:** Log the failure to the Google Drive triage log and continue — do not retry in a loop or block the triage workflow.

## Google Drive Triage Log

Create a searchable Google Drive record for every evaluated business inquiry, including low, medium, ambiguous, and high-quality leads. This is a required operational log, not a conversion signal.

### Log location and naming

- File name: `sync360-inbox-triage-<lead-id>.md`
- Local path: `.sync360/tmp/sync360-inbox-triage-<lead-id>.md`
- If the trigger includes a Gmail message id, include it in the file body.
- Use the deterministic file name as the idempotency key for reasoning. If `gog drive search "sync360-inbox-triage-<lead-id>.md"` clearly shows an existing log, report that existing log instead of creating a duplicate.

### Required log fields

Include only the minimum useful business evidence:

- timestamp evaluated
- lead id
- Gmail message id and thread id when available
- sender display name or company
- sender domain
- subject
- category
- lead quality
- suggested action
- short rationale, maximum 5 bullets
- Telegram result if attempted
- analytics result if emitted

Do not store full raw email bodies, attachments, credentials, private notes, or unnecessary personal contact details in the Drive log. Mask direct contact details when they are not needed for follow-up.

### Required GOG command shape

Use the workspace exec tool and the configured GOG account. For this v1 workflow, keep the Drive command shape intentionally small and use only proven commands.

Recommended flow:

1. Search for an existing lead log:
   `gog drive search "sync360-inbox-triage-<lead-id>.md" --max 10`
2. Write the markdown log to `.sync360/tmp/sync360-inbox-triage-<lead-id>.md`.
3. Upload a new log with exactly:
   `gog drive upload .sync360/tmp/sync360-inbox-triage-<lead-id>.md`
4. Do not add unverified Drive flags such as `--share`, `--parent`, `--replace`, `--name`, or `--json` to the upload command.
5. If `gog drive upload` fails, inspect `gog drive upload --help` once, report the exact supported syntax or error, and do not invent alternative flags.

### Response validation

- Inspect the `gog` output after search and upload operations.
- Treat the Drive log as successful only when the upload output confirms a file was created or returns a file id/link/name for the uploaded log.
- If Drive logging fails, include the exact command error/output in the final response and still continue to Telegram/analytics steps when those are applicable.

## Analytics Contract

### When to emit
- Emit analytics after a lead has been evaluated, categorized, and routing decision made.
- Emit for every qualified lead where `lead_quality` is `high`, `medium`, or `ambiguous` and the message is not spam or low-intent.
- Do not emit for incoming email volume or incomplete triage decisions.
- Emit exactly once per evaluated lead.
- Do not use Google Drive logging as proof of conversion; Drive logging is operational evidence. Analytics is emitted only when the lead quality/category threshold is met.
- Attempt analytics even when Telegram or Google Drive logging failed. Required side-effect failures are reported separately and do not change whether the lead qualified for analytics.

### Required invocation
Use the workspace exec tool to run:
```bash
sh .sync360/bin/log-skill-conversion --skill inbox-triage --conversion-id <lead-id> --payload-json '<json>'
```

### Response validation
- Inspect the helper's JSON output after running the command.
- Treat the conversion as successful **only** when the output contains `"ok": true` and an `event_id`.
- If the helper fails or returns non-JSON output, report the exact command error/output to the user instead of claiming the conversion succeeded.

### Required payload fields
Include all of these in the JSON payload:
- `event_id`: stable unique identifier for this triage event
- `occurred_at`: ISO-8601 timestamp (e.g., "2026-04-20T14:30:00Z")
- `customer_label`: company or contact name of the inquiry sender
- `outcome.lead_quality`: assessment level (high, medium, low)
- `outcome.inquiry_category`: type of inquiry (sales, support, quote-request, booking-request, etc.)
- `outcome.suggested_action`: next step recommended (quote-generation, google-calendar-booking, human-follow-up, etc.)

### Recommended payload shape
```json
{
  "event_id": "<stable-event-id>",
  "occurred_at": "<ISO-8601 timestamp>",
  "session_id": "<optional runtime session id>",
  "customer_label": "<company-name-or-contact>",
  "contact_masked": "<masked email>",
  "outcome": {
    "lead_quality": "high",
    "inquiry_category": "<sales|support|quote-request|booking-request|partnership|other>",
    "suggested_action": "<quote-generation|google-calendar-booking|human-review|follow-up>",
    "subject_line_summary": "<first-50-chars-of-subject>",
    "email_address_domain": "<company-domain-only>"
  },
  "estimated_value_amount": "<optional expected deal size>",
  "currency": "<tenant-currency>"
}
```

### Privacy note
- Store only minimum customer information needed for analytics.
- Use masked email addresses; do not include full email body or attachments.
- Store company domain only, not individual contact details.
