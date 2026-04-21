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

## Behavior
- Monitor your Gmail inbox 24/7 for incoming customer messages.
- Analyze inquiry content, sender details, and context to assess customer quality and intent.
- Categorize each inquiry (sales inquiry, support request, quote request, appointment inquiry, etc.).
- Flag only messages that meet your threshold for "high-value lead" (genuine buying intent, matching your ICP).
- Do not flag spam, form submissions, or low-intent messages.
- Create a searchable triage log in Google Drive for your records.
- Suggest next steps based on inquiry type (e.g., "This looks like a quote request—use Quote Generation skill").
- Offer human review when lead quality is ambiguous or when the inquiry needs clarification before proceeding.

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
