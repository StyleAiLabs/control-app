# PDF Generation — Release Notes
## 2.0.1

- PDF generation updated as core module

## 2.0.0

**Breaking change**: replaced WeasyPrint (mounted binary) with aPDF.io (hosted API) as the PDF rendering backend.

- Removed the WeasyPrint runtime dependency — no binary installation required on tenant VPS.
- Added aPDF.io API dependency with async job pattern (submit → poll → download).
- API key is provisioned into the tenant runtime environment as `APDF_API_KEY`, not hardcoded in the skill pack.
- Workflow steps updated: removed binary verification, added API submit/poll/download/validate pattern.
- Moved idempotency Drive search to early in the workflow (before HTML construction) to avoid wasted work.
- Expanded Final Response Contract with `skipped` states for steps not reached due to earlier failures.
- Added explicit "send-implying" language handling for email delivery activation.
- Added `renderer: apdf.io` field to analytics payload for traceability.
- Fixed QA defects D-01 through D-04 from v1.x QA review.
- Clarified how starter-template `{{#if}}` and `{{#each}}` control blocks must be rendered before HTML is sent to aPDF.io.

## 1.1.0

- Added explicit handoff contract for the **inbox-triage → pdf-generation** chain.
- Documented the exact data flow when the owner replies to a Telegram lead notification with quote/PDF keywords: Lead ref extraction, email context passthrough, and `source_reference` traceability.
- Defined what inbox-triage passes (customer, scope, Gmail message ID) vs what the owner still needs to provide (line items, rates, amounts).

## 1.0.0

- Initial release of the PDF Generation skill module.
- HTML/CSS to PDF rendering via WeasyPrint with full print-optimised CSS support.
- Required Google Drive filing with folder structure and metadata.
- Optional Gmail delivery with Drive link or reference.
- Starter templates for three trades document types: quote, invoice, and site report.
- Explicit WeasyPrint runtime dependency with verification contract and fail-closed behavior.
- Conversion analytics via `pdf_generated` event after successful Drive upload.
