---
name: Inbox Triage
description: Monitor email inbox, identify high-value leads, and route qualified opportunities.
metadata:
  openclaw:
    managedBy: Sync360
  integrations:
    - gmail
    - google-drive
    - google-sheets
---

# Inbox Triage

When a customer inquiry is delivered from Gmail, use this skill to categorize the message, assess lead quality, flag high-value opportunities, notify the connected Telegram channel when appropriate, create a Google Drive triage log, write qualified lead details to Google Sheets, emit analytics when appropriate, and suggest the next action (Quote Generation, Calendar Booking, or human follow-up).

Sync360 may deliver polled Gmail messages as internal inbox events from `sync360-inbox-monitor`. Treat those events as neutral triggers only. The trigger has not classified the email as high-value; you must decide the category, lead quality, and next action from this skill's instructions and the email context.

When handling an internal inbox event, use the email metadata/body and tenant workspace files as the source of truth. Start with `.openclaw/workspace/BUSINESS_PROFILE.json` for core company facts, then read `.openclaw/workspace/WORKSPACE_CONTENT_INDEX.json` and the referenced `knowledge/*` files for pricing, policies, rate sheets, and website content. Do not use `web_search`, public web browsing, or public website research unless the owner explicitly asks you to research the sender or company.

## Critical Runtime Contracts

For internal `sync360-inbox-monitor` events, complete all required side effects before reporting final status.

- Telegram high-value notification: use the normal runtime `message` send path with `action: send`, `channel: telegram`, `target: <telegram_default_chat_id>`, and `message: <notification body>`. Do not use Telegram poll fields for a normal send.
- Telegram gate: send Telegram only when `lead_quality` is `high`. Do not send Telegram for `medium`, `ambiguous`, `low`, spam, or low-intent messages; those still need Drive logging and analytics when they qualify for analytics.
- High-value signal: a concrete commercial quote/request-for-service email with site count, floor area, timeline, role/contact details, or operational urgency is high intent unless the tenant profile clearly excludes that work.
- High-value Telegram body: when Telegram is sent, use the `High-Value Lead Detected` format from the Telegram Notifications section, including category, suggested action, and references.
- Lead id for Gmail events: when `gmail_message_id` is present, it is the primary lead id. The Telegram body must include the exact line `Lead ref: <gmail_message_id>` using the Gmail message id, not the Sync360 Job ID, and must not write synonyms such as `Lead Reference`.
- Google Drive triage log: create `.sync360/tmp/sync360-inbox-triage-<lead-id>.md`, then run exactly `gog drive upload .sync360/tmp/sync360-inbox-triage-<lead-id>.md`. Do not use `apply_patch`, workspace patch tools, or local-only file edits as a substitute for Google Drive logging.
- Google Sheets qualified lead row: for every qualified lead where `lead_quality` is `high`, `medium`, or `ambiguous` and the message is not spam or low-intent, find or create spreadsheet `Sync360 Inbox Triage Qualified Leads`, tab `Qualified Leads`, verify `<lead-id>` is not already in the lead-id column, then append exactly one row.
- Analytics: for every qualified lead where `lead_quality` is `high`, `medium`, or `ambiguous` and the message is not spam or low-intent, run `sh .sync360/bin/log-skill-conversion --skill inbox-triage --conversion-id <lead-id> --payload-json '<json>'`. For Gmail events, use the Gmail message id as `<lead-id>` and include required payload fields: `event_id`, `occurred_at`, `customer_label`, `outcome.lead_quality`, `outcome.inquiry_category`, and `outcome.suggested_action`.
- Minimal analytics payload for Gmail events: `{"event_id":"inbox-triage-<gmail_message_id>","occurred_at":"<ISO-8601 timestamp>","customer_label":"<company or contact>","outcome":{"lead_quality":"high","inquiry_category":"quote-request","suggested_action":"pdf-generation"}}`.
- A Telegram success does not finish the workflow. Continue to Drive logging, Sheets logging, and analytics. A Telegram, Drive, or Sheets failure must not block analytics.
- Basic enquiry reply gate: low-risk support and business-information enquiries must execute exactly one Gmail reply action when they enter this branch. Send exactly one direct Gmail reply when the answer is grounded, or send exactly one clarifying question when the answer is incomplete. Do not auto-reply to quotes, pricing, custom scope, timeline commitments, complaints, legal/payment disputes, or undocumented business policies.
- Expired-trial reply safety gate: when the Sync360 trigger or delivery-policy context says customer-facing Gmail replies are not allowed, do not send a Gmail reply or create a Gmail draft in that run. Continue classification, operator notification, Drive logging, Sheets logging, and analytics as applicable, and report that customer-facing replies were paused by policy.
- Planned reply language is invalid. Do not say a clarifying question or follow-up email will be sent later unless you have already executed `gog gmail send` or `gog gmail drafts create` successfully in the current run.

## Google Workspace Context

This skill uses GOG (Google Workspace OAuth), which is pre-configured on the OpenClaw server, to inspect the referenced Gmail message when needed, write Google Drive triage logs, and write qualified lead rows to Google Sheets. Before taking Gmail, Drive, or Sheets actions:

1. **Use the configured GOG account** — Do not ask the owner to choose an account unless tooling explicitly reports multiple accounts or no default.
2. **Verify the connection before tool actions** — If GOG fails, stop and report the error to the operator instead of guessing from missing context.
3. **Do not create your own watcher** — Sync360 owns polling and de-dupe. This skill owns evaluation and follow-up after an email event is delivered.

## Basic Enquiry Reply Flow

Use this branch only for low-risk support and business-information enquiries. The source of truth is limited to tenant workspace files plus the exact Gmail message or thread context already available in the workspace. Do not use public web research for this flow.

### Allowed auto-reply categories

- business hours or operating availability
- service area or location coverage
- offered services when clearly documented in tenant files
- simple support or status questions when the answer is already present in tenant files or the current email thread
- appointment or contact-routing clarifications that do not commit pricing, scope, dates, turnaround, or legal promises

### Never auto-reply for these

- quotes, pricing, or estimates
- custom scope, project timelines, negotiated commitments, or bespoke delivery promises
- complaints, disputes, refunds, billing conflicts, or legal/policy issues
- ambiguous enquiries where the answer is not grounded in tenant material
- anything that would require public research or undocumented assumptions

### Reply policy

1. Read the exact Gmail message first with `gog gmail get <gmail_message_id>` when a Gmail message id is available.
2. Classify the enquiry as `basic-info`, `basic-support`, or non-basic.
3. Gather the answer only from:
   - `BUSINESS_PROFILE.json`, `WORKSPACE_CONTENT_INDEX.json`
   - the referenced `knowledge/*` files when the content index points to pricing, policy, service, or website details
   - `PROFILE.md`, `IDENTITY.md`, `SOUL.md`, `USER.md`, `BOOTSTRAP.md`
   - assigned skill files when relevant
   - the exact Gmail message and thread context
4. If the answer is grounded and low-risk, send exactly one Gmail reply.
5. If customer-facing Gmail replies are paused by policy, do not reply or draft; continue the non-reply parts of the workflow and report the policy block clearly.
6. If the enquiry is basic but the answer is missing from tenant material, send exactly one short clarifying question instead of guessing.
7. If the enquiry is outside the allowed categories, do not auto-reply; continue with the normal lead/opportunity or human-follow-up path.
8. For Sync360-triggered inbox work, default to direct send. Use draft-only flow only when the owner explicitly asked for a draft workflow and customer-facing Gmail replies are allowed by policy.

### Reply copy rules

- Keep replies concise, businesslike, and plain.
- Match the tenant communication style captured during onboarding. Use `SOUL.md`, `USER.md`, `PROFILE.md`, and the tenant tone hint as the voice source before drafting the reply.
- Answer only what is known from tenant files or the email thread.
- Do not invent pricing, service guarantees, policies, turnaround times, or availability promises.
- Ask one focused clarifying question when the answer is incomplete.
- Preserve a human handoff option when appropriate.
- Do not promise a later reply, later follow-up, or “next” email unless that message has already been sent or drafted in the current run.
- Send the Gmail body as one plain-text paragraph unless a true list is required by the customer question.
- Do not include literal escape sequences such as `\n`, `\r`, or `\t` in the reply body.
- Keep the reply body ASCII-only and avoid decorative symbols, emoji, markdown formatting, smart quotes, bullets, or other special characters unless the business name or customer-provided text requires them exactly.

### Required final reply outcome fields

When a basic enquiry reply is attempted, include these in the final summary:

- `inquiry_category`
- `reply_mode`: `auto_reply` or `clarifying_question`
- `reply_status`: `sent`, `drafted`, `not_attempted`, or `failed`
- `reply_reason`
- `used_clarification`: `true` or `false`

### Reply outcome rules

- If this branch required a reply and you executed `gog gmail send` successfully, report `reply_status: sent`.
- If the owner explicitly wanted draft-only flow and you executed `gog gmail drafts create` successfully, report `reply_status: drafted`.
- If a reply was required but the Gmail command failed, report `reply_status: failed` and include the exact command error in `reply_reason`.
- If a reply was not required because the enquiry was classified outside the basic-enquiry branch, report `reply_status: not_attempted` and explain why in `reply_reason`.
- Do not report `reply_status: sent` or `reply_status: drafted` without a matching successful Gmail tool result in the current run.

### Regression example

- Example: if the message asks, `What are your services and are you open next Monday?`, and tenant files document services but say business hours are not confirmed, classify it as a basic business-information enquiry, answer with the documented services, send exactly one clarifying question about availability instead of inventing hours, and do not send a `High-Value Lead Detected` Telegram notification.

## Required Workflow

Complete these steps in order for every delivered Gmail inquiry:

1. Evaluate the message.
   - Analyze inquiry content, sender details, company/domain context, urgency, and fit against the tenant's ideal customer profile.
   - Categorize the inquiry as sales inquiry, support request, quote request, appointment inquiry, spam, low-intent, ambiguous, or another clear category.
   - Decide `lead_quality` as `high`, `medium`, `low`, or `ambiguous`.
   - Flag high-value only when there is genuine buying intent and ICP fit. Do not flag spam, newsletters, generic form spam, or low-intent messages.
   - Do not classify a low-risk basic enquiry as high-value. Questions about services, opening hours, coverage, or simple support should not trigger `High-Value Lead Detected` unless the message separately shows real commercial buying intent.
2. Build a stable lead id.
   - For Gmail-triggered events, use `gmail_message_id` as the primary lead id for Telegram `Lead ref`, Google Drive log naming, and analytics `conversion_id`.
   - Use the Sync360 `Job ID` only for internal traceability or when no Gmail message id exists.
   - Reuse this id for Telegram idempotency reasoning, Google Drive log naming, and analytics `conversion_id`.
3. If `lead_quality` is `high`, send exactly one Telegram notification using the Telegram Notifications section.
   - The notification must include `Lead ref: <gmail_message_id>`.
   - Include `Thread ref: <gmail_thread_id>` when a thread id is available.
4. Create or verify the Google Drive triage log using the Google Drive Triage Log section.
5. Emit analytics independently when the lead meets the Analytics Contract. A Telegram or Google Drive failure must not block analytics.
6. Write or verify a Google Sheets row when the lead meets the Google Sheets Qualified Lead Log threshold. A Google Sheets failure must not change or block the analytics result.
7. Final response must summarize:
   - category
   - lead quality
   - suggested action
   - basic enquiry reply outcome when attempted
   - Telegram result when attempted
   - Google Drive log result or exact failure
   - Google Sheets row result or exact failure
   - analytics result or why analytics was not emitted
   - never claim that a reply will happen later unless the summary also reports a successful send/draft result from this run

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
- The line must be written exactly as `Lead ref: <gmail_message_id>`. Do not write `Lead Reference`, `Lead ID`, or the Sync360 Job ID in place of the Gmail message id.
- If the owner replies to this Telegram notification with requests such as `Get from email`, `Generate a quote`, `Send a quote`, `Draft reply`, or `Book site visit`, read the replied notification, extract `Lead ref`, and use `gog gmail get <Lead ref>` before asking for email details.
- When the owner says `Generate a quote`, `Send a quote`, `Quote this`, or similar quote/PDF keywords after extracting the email content, invoke the **pdf-generation** skill with `document_type: quote`. Pass the extracted customer name, email domain, job scope, and the Gmail message ID as `source_reference`. Read `skills/pdf-generation/SKILL.md` and follow it exactly — do not use your default document generation behavior.
- Do not fall back to guessed Gmail searches when the notification already contains a `Lead ref`.
- If the owner did not reply to the original notification or the replied message does not contain `Lead ref`, ask them to reply to the original lead notification again or paste the lead reference.

**If the Telegram send fails:** Log the failure to the Google Drive triage log and continue — do not retry in a loop or block the triage workflow.

## Gmail Reply Contract

For low-risk basic enquiries only, use the native verified Gmail write surface below.

### Reply directly to the original email

Use `gog gmail send` when you are sending the answer now:

```bash
gog gmail send --reply-to-message-id <gmail_message_id> --subject '<subject>' --body '<plain-text-body>'
```

Notes:

- `--reply-to-message-id <gmail_message_id>` is the primary reply anchor.
- If the original message has multiple recipients and the business should reply to all, add `--reply-all`.
- If a quoted reply is useful, add `--quote`.
- Do not combine `--reply-to-message-id` with `--thread-id` in the standard Inbox Triage reply flow.
- Do not invent unsupported Gmail write flags.

### Create a draft instead of sending

Use `gog gmail drafts create` only when the owner explicitly wants a draft workflow:

```bash
gog gmail drafts create --reply-to-message-id <gmail_message_id> --subject '<subject>' --body '<plain-text-body>'
```

### Validation

- Treat the reply as successful only when the command output confirms send/draft creation.
- If Gmail reply sending fails, capture the exact command error and include it in the final summary.
- Do not silently fall back to Telegram, public research, or an invented email flow when the Gmail command fails.
- For Sync360-triggered basic enquiries, a missing Gmail send/draft command means the reply workflow is incomplete. Do not mark the enquiry handled until `reply_status` reflects the real send/draft/failure outcome.

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
6. Do not use `apply_patch`, workspace patch tools, or local-only file edits as a substitute for Google Drive logging. The log only counts as a Drive log when a `gog drive upload` command succeeds or reports an existing Drive file.

### Response validation

- Inspect the `gog` output after search and upload operations.
- Treat the Drive log as successful only when the upload output confirms a file was created or returns a file id/link/name for the uploaded log.
- If Drive logging fails, include the exact command error/output in the final response and still continue to Telegram/analytics steps when those are applicable.

## Google Sheets Qualified Lead Log

Write one row for every qualified lead. A qualified lead is any non-spam, non-low-intent message where `lead_quality` is `high`, `medium`, or `ambiguous`. This threshold must match the Analytics Contract. Do not write rows for `low`, spam, newsletters, generic form spam, or low-intent messages.

### Sheet location and idempotency

- Spreadsheet name: `Sync360 Inbox Triage Qualified Leads`
- Tab name: `Qualified Leads`
- Use `<lead-id>` as the idempotency key.
- If multiple spreadsheets with the target name are returned, use the first returned spreadsheet and report that multiple matches existed. Do not create another spreadsheet.
- Before appending, read the existing rows and confirm the lead-id column does not already contain `<lead-id>`. If it does, report `sheets_skipped: duplicate_lead_id` and do not append a duplicate row.

### Required columns

Use exactly these columns, in this order:

`Occurred At | Lead ID | Gmail Message ID | Gmail Thread ID | Customer | Sender Domain | Subject | Lead Quality | Category | Suggested Action | Drive Log Result | Analytics Result`

### Required GOG command shape

Use the workspace exec tool and the configured GOG account. Keep the Sheets command shape small and do not invent extra flags.

Recommended flow:

1. Search for the spreadsheet:
   `gog drive search "Sync360 Inbox Triage Qualified Leads" --max 10`
2. If no matching spreadsheet exists, create it:
   `gog sheets create "Sync360 Inbox Triage Qualified Leads" --sheets "Qualified Leads"`
3. Read existing rows before appending:
   `gog sheets get <spreadsheetId> 'Qualified Leads!A:L'`
4. If the sheet is empty or missing headers, write the header row:
   `gog sheets update <spreadsheetId> 'Qualified Leads!A1:L1' '<header-pipe-row>'`
5. Append the qualified lead row:
   `gog sheets append <spreadsheetId> 'Qualified Leads!A:L' '<pipe-delimited-row>'`

### Row content

- `Occurred At`: ISO-8601 timestamp for evaluation time.
- `Lead ID`: `<lead-id>`.
- `Gmail Message ID`: Gmail message id when available.
- `Gmail Thread ID`: Gmail thread id when available.
- `Customer`: company or contact label.
- `Sender Domain`: sender domain only.
- `Subject`: concise subject, no raw full body.
- `Lead Quality`: `high`, `medium`, or `ambiguous`.
- `Category`: inquiry category.
- `Suggested Action`: next step.
- `Drive Log Result`: success, existing, skipped, or exact failure summary.
- `Analytics Result`: success, skipped, pending, or exact failure summary.

Do not store full raw email bodies, attachments, credentials, private notes, or unnecessary personal contact details in the sheet. Mask direct contact details when they are not needed for follow-up.

### Response validation

- Inspect `gog` output after search, create, get, update, and append operations.
- Treat the Sheets row as successful only when `gog sheets append` confirms the row was appended, or when an existing row with the same lead id is found.
- If Sheets logging fails, include the exact command error/output in the final response and still continue to analytics when applicable.

## Analytics Contract

### When to emit
- Emit analytics after a lead has been evaluated, categorized, and routing decision made.
- Emit for every qualified lead where `lead_quality` is `high`, `medium`, or `ambiguous` and the message is not spam or low-intent.
- Do not emit for incoming email volume or incomplete triage decisions.
- Emit exactly once per evaluated lead.
- Do not use Google Drive logging as proof of conversion; Drive logging is operational evidence. Analytics is emitted only when the lead quality/category threshold is met.
- Attempt analytics even when Telegram, Google Drive, or Google Sheets logging failed. Required side-effect failures are reported separately and do not change whether the lead qualified for analytics.

### Required invocation
Use the workspace exec tool to run:
```bash
sh .sync360/bin/log-skill-conversion --skill inbox-triage --conversion-id <lead-id> --payload-json '<json>'
```
For Gmail-triggered events, `<lead-id>` must be the Gmail message id when available, not the Sync360 Job ID.

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
- `outcome.suggested_action`: next step recommended (pdf-generation, google-calendar-booking, human-follow-up, etc.)

For Gmail-triggered events, use `event_id` such as `inbox-triage-<gmail_message_id>` so the helper can validate the payload and de-dupe the event.

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
    "suggested_action": "<pdf-generation|google-calendar-booking|human-review|follow-up>",
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
