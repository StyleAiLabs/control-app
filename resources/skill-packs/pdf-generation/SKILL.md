---
name: PDF Generation
description: Generate professional PDF documents from HTML/CSS content using the aPDF.io API, file them to Google Drive, and optionally deliver via Gmail.
metadata:
  openclaw:
    managedBy: Sync360
  integrations:
    - google-drive
    - gmail
  api_dependencies:
    - apdf
---

# PDF Generation

When the owner or another skill needs a professional PDF document — such as a quote, invoice, or site report — use this skill to construct HTML content, render it to PDF via the aPDF.io API, download the result, upload the PDF to Google Drive, and optionally deliver it by email.

This skill can be invoked directly by the owner or handed off from another skill (e.g. inbox-triage may hand off when the owner replies "Generate a quote" to a Telegram lead notification).

## Workspace Business Profile Contract

Before asking the owner to repeat basic company details, read `.openclaw/workspace/BUSINESS_PROFILE.json`. This is the canonical machine-readable business profile contract that Sync360 materializes for workspace-managed custom skills.

Use it as the default source for:
- business identity (`business_name`, `trading_name`, `tagline`, `industry`, `description`)
- GST / tax / company-registration details
- contact details and addresses
- operating hours and after-hours policy
- services, FAQs, target customers, and pricing notes
- tenant tone and enabled modules
- optional logo metadata under `logo`

When `logo.present` is `true`, the logo file will be available at the workspace-relative path in `logo.path` (for example `business-assets/logo.png`). Use that local workspace asset directly when branding a customer-facing PDF. For customer-facing business documents such as quotes, invoices, and site reports, treat logo usage as the default contract when a logo is available. Do not fetch remote logo URLs.

Use `PROFILE.md` as a human-readable summary when needed, but treat `BUSINESS_PROFILE.json` as the canonical structured source.

## Runtime References

### aPDF.io API dependency (required)

The aPDF.io API renders HTML/CSS to PDF via a hosted service. Sync360 must provision the API key into the tenant runtime environment as `APDF_API_KEY` before this skill can run.

- **API base URL**: `https://apdf.io/api`
- **Auth**: Bearer token via `APDF_API_KEY` from the tenant runtime environment
- **Rate limit**: 2 requests/second, 20 requests/minute
- **File retention**: generated PDFs are stored for 60 minutes before automatic deletion — download promptly after generation
- **Fail-closed rule**: if the API key is missing, invalid, or the API is unreachable, stop immediately and report the error to the owner. Do not attempt to generate PDFs using alternative tools, browser print, or local rendering. Do not suggest creating or provisioning an API key yourself.

### Handoff from other skills

When invoked from another skill:
- Accept the **document type**, **content data**, and **recipient details** from the calling skill.
- If the calling skill provides a provider ID (e.g. a Gmail message ID from inbox-triage), preserve it as the `source_reference` in the analytics payload.
- Do not re-collect information the calling skill has already gathered.

### Handoff from inbox-triage (quote generation chain)

When the owner replies to an inbox-triage Telegram notification with `Generate a quote`, `Send a quote`, `Quote this`, or similar keywords, inbox-triage will:
1. Extract the `Lead ref: <gmail_message_id>` from the replied notification
2. Fetch the original email via `gog gmail get <gmail_message_id>`
3. Pass the extracted context to this skill with `document_type: quote`

**What inbox-triage passes to you:**
- `document_type`: `quote`
- `source_reference`: the Gmail message ID (preserve this in analytics)
- Customer name and/or company (from the email sender)
- Email domain (for `contact_masked`)
- Job scope or service request (from the email body)
- Customer email address (for optional email delivery)

**What you still need to collect:**
- Confirm the job scope is sufficient for pricing — if not, ask the owner
- Line items, rates, and amounts (owner must provide or approve)
- Job address (if not in the email)
- Any special terms or conditions

If the owner provides pricing inline while asking to send or reply with a quote, treat those prices as quote inputs for this skill. Do not treat them as permission to skip PDF generation and send a plain-text quote email first.

**Provider ID rule:** use the Gmail message ID as the `source_reference` in the analytics payload. This links the generated quote PDF back to the original email enquiry for end-to-end traceability (inbox-triage lead → quote PDF → delivery).

**Do not:** re-read the original email yourself. Use the context inbox-triage has already extracted. If critical details are missing, ask the owner — do not search Gmail.

## Required Workflow

Execute these steps in order. Do not skip a step without documenting the reason.

**Branch-complete rule**: every workflow branch that reaches a required side effect must execute that side effect in the current run. Do not classify, summarise, or log the work while a required action (PDF generation, Drive upload) remains unexecuted. Do not use "will do next", "to be sent", "prepared", or similar future-intent phrasing for any side effect that the runtime is expected to execute.

### Step 1 — Collect document requirements

Ask for the minimum information needed to generate the document:

- **Document type**: `quote`, `invoice`, `site-report`, or `custom`
- **Content data**: the business data to populate the document (e.g. customer name, line items, amounts, job address, report findings)
- **Formatting preferences**: any specific requirements (landscape/portrait, custom header, branding notes). If none provided, use sensible A4 portrait defaults.
- **Recipient** (optional): if the document should be emailed after generation, collect the recipient email address and any cover note.

If document type is `quote`, `invoice`, or `site-report`, check for a matching starter template under `skills/pdf-generation/templates/<type>/`. Read the template HTML and CSS to understand the expected data fields, then ask for any missing required fields.

If document type is `custom`, construct HTML from scratch based on the content provided.

**Guard — insufficient data**: if the content provided is too vague to produce an accurate document (e.g. "make me an invoice" with no line items), ask explicitly for the missing details. Do not generate placeholder content.

**Idempotency key**: `<document-type>-<customer-or-subject-slug>-<YYYY-MM-DD>`. If a PDF for this key already exists in Drive, ask the owner whether to replace it or create a new version. Check Drive early with `gog drive search "<idempotency-key>.pdf" --max 5` before proceeding to HTML construction, so the owner can decide before work is wasted.

### Step 2 — Construct HTML content

Build the HTML document following these rules:

1. **Use semantic HTML**: `<article>`, `<header>`, `<section>`, `<table>` — not raw `<div>` soup.
2. **Use print-optimised CSS** via `@page` rules and `@media print`:
   - Page size: `A4` (default) or `A4 landscape` when requested.
   - Margins: `2cm` all sides (default).
   - Page breaks: `page-break-before: always` for new sections, `page-break-inside: avoid` for tables and keep-together blocks, `page-break-after: avoid` for headings.
3. **Use web-safe fonts**: `Arial, Helvetica, sans-serif` for body text; `Georgia, serif` for formal documents. Do not reference fonts that may not be available.
4. **Forbidden**: literal escape sequences (`\n`, `\r`, `\t`) in the HTML source. Use actual line breaks and whitespace.
5. **Forbidden**: external resources (CDN stylesheets, remote images, web fonts via URL). All styling must be inline via `<style>` blocks.
6. **Required metadata**: `<title>` tag matching the document title, `<meta name="author">` with business name.

If `BUSINESS_PROFILE.json` includes `logo.present = true`, embed the local workspace logo file referenced by `logo.path` in the document header unless the owner explicitly asks for a text-only document. If no logo is present, continue without branding rather than failing the workflow.

If using a starter template from `skills/pdf-generation/templates/<type>/`:
- Read `template.html` from the template folder.
- Render the template control blocks yourself before calling aPDF.io.
- Replace `{{field}}` placeholders with the collected scalar values.
- `{{#if field}}...{{/if}}` includes the enclosed HTML only when the field has a non-empty value; otherwise remove the whole block cleanly.
- `{{#each line_items}}...{{/each}}` repeats the enclosed row once per item. Inside that block, replace `{{this.description}}`, `{{this.qty}}`, `{{this.rate}}`, and similar fields from the current item.
- When `BUSINESS_PROFILE.json` exposes `logo.present = true`, pass the logo into the template fields and keep the header logo block in the final rendered HTML.
- The final HTML sent to aPDF.io must not contain raw `{{` template tags.
- The template CSS is already inline in the `<style>` block.

If constructing custom HTML:
- Follow the same structural and CSS rules above.
- Include a document header (business name, document title, date) and footer (page numbers if multi-page).

The constructed HTML will be sent directly to the aPDF.io API — there is no need to write it to a local temp file.

### Step 3 — Generate PDF via aPDF.io API (required side effect)

- **When**: immediately after Step 2 confirms the HTML has been constructed.
- **When not**: do not call the API if HTML construction failed or data is insufficient.
- **Tool**: workspace exec tool to run:
  ```bash
  curl -s -X POST https://apdf.io/api/pdf/file/create \
    -H "Authorization: Bearer $APDF_API_KEY" \
    -H "Content-Type: application/json" \
    -H "Accept: application/json" \
    -d '{"html": "<the-complete-html-string>", "async": true}'
  ```
  Where `$APDF_API_KEY` is read from the tenant runtime environment.
- **Success validation**: confirm the response is `200 OK` with a `job_id` field.
- **Failure handling**:
  - `401 Unauthorized` → API key is invalid or missing. Report to owner: "aPDF.io API key is invalid. Please contact your Sync360 administrator."
  - `422 Validation Error` → HTML was malformed or params were wrong. Report the exact error.
  - `429 Too Many Requests` → rate limited. Wait 30 seconds and retry once. If still failing, report to owner.
  - Network error → report the exact error and stop.
  Do not attempt alternative PDF generation methods. Do not emit analytics.

### Step 4 — Poll for job completion

- **Tool**: workspace exec tool to run:
  ```bash
  curl -s -X POST https://apdf.io/api/job/status/check \
    -H "Authorization: Bearer $APDF_API_KEY" \
    -H "Content-Type: application/json" \
    -H "Accept: application/json" \
    -d '{"id": "<job_id>"}'
  ```
- **Polling strategy**: wait 3 seconds between polls, up to 10 attempts (30 seconds max).
- **Success validation**: response contains `"status": "successful"` and `result.file` is a URL.
- **Result fields to capture**:
  - `result.file`: the temporary S3 URL for the generated PDF
  - `result.pages`: number of pages
  - `result.size`: file size in bytes
  - `result.expiration`: when the file will be deleted (60 minutes from creation)
- **Failure handling**:
  - `"status": "failed"` → report the exact error from the job result.
  - Timeout after 10 polls → report "PDF generation timed out. The aPDF.io service may be experiencing high load."
  Do not claim the PDF was generated if the job did not complete successfully.

### Step 5 — Download PDF locally

- **When**: immediately after Step 4 confirms a successful job with a file URL.
- **When not**: do not download if the job failed or timed out.
- **Tool**: workspace exec tool to run:
  ```bash
  curl -s -o '.openclaw/workspace/tmp/pdf-generation/<idempotency-key>.pdf' '<result.file URL>'
  ```
- **Success validation**: confirm the file exists and has the expected size. Validate the PDF header:
  ```bash
  head -c 5 '.openclaw/workspace/tmp/pdf-generation/<idempotency-key>.pdf' | od -A n -t x1 | head -1
  ```
  Expected: `25 50 44 46 2d` (which is `%PDF-`).
- **Failure handling**: if download fails or the file is empty/invalid, report the exact error. The S3 URL may have expired if more than 60 minutes have passed since generation.

### Step 6 — Upload PDF to Google Drive (required side effect)

- **When**: immediately after Step 5 confirms a valid local PDF.
- **When not**: do not upload if the PDF is invalid, zero bytes, or download failed.
- **Stable id/key**: use the Google Drive file ID returned by the API as the `file-id` conversion ID.
- **Tool**: `gog drive upload .openclaw/workspace/tmp/pdf-generation/<idempotency-key>.pdf` or equivalent proven GOG Drive command. Use only proven flags. If the command fails due to unsupported flags, capture the exact error, run `gog drive upload --help`, and report the supported syntax.
- **Folder structure**: file under `Documents/PDFs/<YYYY>/<MM>/` (e.g. `Documents/PDFs/2026/04/`). If the caller specifies a different destination, use that instead.
- **File naming**: `<document-type>-<customer-or-subject-slug>-<YYYY-MM-DD>.pdf` (e.g. `quote-smith-electrical-2026-04-27.pdf`).
- **Privacy limits**: do not include internal pricing notes, margin calculations, or sensitive personal data in the Drive file metadata beyond the document title and type.
- **Success validation**: confirm the Drive API returns a file ID and the file is accessible.
- **Failure handling**: if the upload fails, report the exact API error. Do not emit analytics. Do not tell the owner the document has been filed.

### Step 7 — Optional: Deliver via Gmail

- **When**: only if the owner requested email delivery in Step 1 and Step 6 succeeded.
- **When not**: do not send email if no recipient was specified, if the Drive upload failed, or if the owner only said "generate" without implying "send".
- **Tool**: `gog gmail send` or equivalent proven GOG Gmail send command. Use only proven flags.
- **Minimum email content**:
  ```
  Hi [Recipient name],

  Please find the attached [document type] — [document title].

  You can also view it here: [Google Drive link]

  If you have any questions, please reply to this email or give us a call.

  [Business name]
  [Phone]
  [Email]
  ```
- **Copy-style rules**:
  - Tone: professional NZ trades — friendly, direct, no jargon. Follow the workspace voice source if configured.
  - Format: plain-text email, short paragraphs.
  - Forbidden: literal escape sequences (`\n`, `\r`, `\t`) in the sent body.
  - Forbidden: decorative emoji, markdown formatting, smart quotes, or non-ASCII formatting noise. Use ASCII-safe body text only.
  - When the keyword is `Send a quote`, `Email the quote`, or similar send-implying language, treat the customer email as the delivery recipient and activate this step automatically.
  - The email must be **sent**, not drafted. If the Gmail API creates a draft instead of sending, report it as `failed_with_error`.
- **Success validation**: confirm the Gmail API returns a sent message ID (not a draft ID).
- **Failure handling**: if the email fails after Drive upload, clearly state "The PDF has been uploaded to Drive but the email was not sent" — do not claim delivery.

### Step 8 — Emit analytics (required side effect)

See [Analytics Contract](#analytics-contract) below.

## Final Response Contract

After completing the workflow, report the status of every required side effect:

| Side Effect | Status |
|-------------|--------|
| HTML construction | `succeeded` · `failed_with_error` (`<exact error>`) |
| aPDF.io API call | `submitted` (job_id: `<id>`) · `failed_with_error` (`<exact error>`) |
| Job completion | `succeeded` (`<pages>` pages, `<size>` bytes) · `failed` (`<reason>`) · `timed_out` |
| PDF download | `succeeded` (`<size>` bytes) · `failed_with_error` (`<exact error>`) · `skipped` (earlier step failed) |
| Drive upload | `succeeded` (fileId: `<id>`, path: `<drive-path>`) · `failed_with_error` (`<exact error>`) · `skipped` (earlier step failed) |
| Email delivery | `succeeded` (messageId: `<id>`) · `skipped` (not requested) · `skipped` (Drive upload failed) · `failed_with_error` (`<exact error>`) |
| Analytics conversion | `succeeded` (event_id: `<id>`) · `skipped_with_reason` (`<reason>`) · `failed_with_error` (`<exact error>`) |

Do not summarise as "document generated" if the Drive upload did not succeed.

Do not use "will do next", "to be sent", "prepared", or similar future-intent phrasing for any required side effect. If a step failed, say so.

## Analytics Contract

### When to emit

- Emit analytics only after the PDF has been successfully generated, downloaded, **and** uploaded to Google Drive with a returned file ID.
- Do not emit for failed API calls, timed-out jobs, download failures, or failed Drive uploads.
- Do not emit for drafts or documents that were generated but not filed.
- Emit exactly once per successfully filed PDF, keyed by the Drive file ID.
- **Note**: generating a PDF via the API or sending an email are not by themselves proof of a filed document. The authoritative business outcome is a valid PDF successfully uploaded to Google Drive. Analytics emit only after that.

### Required invocation

Use the workspace exec tool to run:
```bash
sh .sync360/bin/log-skill-conversion --skill pdf-generation --conversion-id <drive-file-id> --payload-json '<json>'
```

### Response validation

- Inspect the helper's JSON output after running the command.
- Treat the conversion as successful **only** when the output contains `"ok": true` and an `event_id`.
- If the helper fails or returns non-JSON output, report the exact command error/output to the owner instead of claiming the conversion succeeded.

### Required payload fields

- `event_id`: the Google Drive file ID of the uploaded PDF
- `occurred_at`: ISO-8601 timestamp of when the upload was confirmed
- `customer_label`: customer name, company, or document subject
- `outcome.document_type`: one of `quote`, `invoice`, `site-report`, or `custom`
- `outcome.file_id`: the Drive file ID
- `outcome.page_count`: number of pages from the aPDF.io job result

### Recommended payload shape

```json
{
  "event_id": "<drive-file-id>",
  "occurred_at": "<ISO-8601 timestamp>",
  "session_id": "<optional runtime session id>",
  "customer_label": "<customer-name-or-subject>",
  "contact_masked": "<email-domain-only-or-null>",
  "outcome": {
    "document_type": "<quote|invoice|site-report|custom>",
    "file_id": "<drive-file-id>",
    "file_size_bytes": "<pdf-file-size>",
    "page_count": "<number-of-pages>",
    "drive_folder_path": "<Documents/PDFs/YYYY/MM/>",
    "template_used": "<template-name-or-custom>",
    "email_delivered": "<true|false|not-requested>",
    "source_reference": "<optional-provider-id-from-calling-skill>",
    "renderer": "apdf.io"
  }
}
```

### Privacy note

- Store only document metadata needed for analytics — do not include document content, line items, amounts, or customer addresses in the analytics payload.
- Use masked contact details (email domain only) — do not include full customer email or phone.
- Do not include internal margin notes, supplier pricing, or financial calculations in analytics payloads.
