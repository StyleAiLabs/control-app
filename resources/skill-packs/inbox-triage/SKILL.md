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

When a customer inquiry arrives in your Gmail inbox, use this skill to monitor and categorize the message, assess lead quality, flag high-value opportunities, and suggest the next action (Quote Generation, Calendar Booking, or human follow-up).

## Google Workspace Connection (Required Before Monitoring)

This skill uses GOG (Google Workspace OAuth), which is pre-configured on the OpenClaw server. Before monitoring begins:

1. **Connect via GOG** — Use GOG to connect to the tenant's Google Workspace account.
2. **Verify the connection** — Confirm the GOG connection is active before starting. If it fails, stop and report the error to the operator — do not attempt to monitor without a confirmed connection.
3. **Maintain the connection** — If the GOG connection drops during a monitoring session, reconnect before continuing. Never silently skip emails due to a lost connection.

## Behavior
- Monitor your Gmail inbox 24/7 for incoming customer messages using the active Google Workspace OAuth connection.
- Analyze inquiry content, sender details, and context to assess customer quality and intent.
- Categorize each inquiry (sales inquiry, support request, quote request, appointment inquiry, etc.).
- Flag only messages that meet your threshold for "high-value lead" (genuine buying intent, matching your ICP).
- Do not flag spam, form submissions, or low-intent messages.
- **When a high-value lead is detected, immediately send a Telegram notification to the connected channel** — see Telegram Notifications below.
- Create a searchable triage log in Google Drive for your records.
- Suggest next steps based on inquiry type (e.g., "This looks like a quote request—use Quote Generation skill").
- Offer human review when lead quality is ambiguous or when the inquiry needs clarification before proceeding.

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

Suggested action: <next step>
```

**If the Telegram send fails:** Log the failure to the Google Drive triage log and continue — do not retry in a loop or block the triage workflow.

## Analytics Contract

### When to emit
- Emit analytics after a lead has been evaluated, categorized, and routing decision made.
- Emit only for leads that meet your minimum quality threshold (not spam/low-intent).
- Do not emit for incoming email volume or incomplete triage decisions.
- Emit exactly once per evaluated lead.

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
