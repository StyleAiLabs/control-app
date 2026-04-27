# PDF Generation — Template Guide

This guide documents the starter templates shipped with the PDF Generation skill and provides guidance on building custom HTML for aPDF.io rendering.

## Business Profile Input Contract

Sync360 now materializes `.openclaw/workspace/BUSINESS_PROFILE.json` for workspace-managed custom skills. Treat it as the canonical structured source for customer-facing business details before falling back to `PROFILE.md`.

Relevant fields include:
- `business_name`, `trading_name`, `tagline`, `industry`, `description`
- `contact_email`, `contact_phone`, `contact_mobile`
- `physical_address`, `postal_address`, `city`, `country`
- `tax_number`, `company_reg_number`
- `business_hours`, `after_hours_policy`
- `services`, `faqs`, `target_customers`, `pricing_notes`
- `logo.present` and `logo.path`

When `logo.present` is true, the referenced workspace asset path (for example `business-assets/logo.png`) is the default source for branded headers in generated PDFs. For customer-facing business documents, include the logo in the header unless the owner explicitly asks for a text-only output.

## Shipped Templates

All templates live under `skills/pdf-generation/templates/<type>/` and use a small Handlebars-style syntax that the agent must render into plain HTML before calling aPDF.io.

Rendering rules:
- `{{field}}`: replace with a scalar value
- `{{#if field}}...{{/if}}`: include the enclosed HTML only when the field is non-empty
- `{{#each items}}...{{/each}}`: repeat the enclosed HTML once per item
- `{{this.field}}`: current item field inside an `each` block
- Final output must not contain raw `{{` tags

### Quote (`templates/quote/template.html`)

A professional NZ trades quote with:
- Business header with trade licence
- Customer and job site details
- Scope of work description
- Itemised line items table (description, qty, rate, amount)
- Subtotal, GST (15%), and total
- Terms and conditions
- Acceptance call-to-action

**Required data fields:**
| Field | Example |
|-------|---------|
| `business_logo_path` | `"business-assets/logo.png"` (optional but expected when `logo.present` is true) |
| `business_name` | `"Smith Electrical Ltd"` |
| `business_address` | `"42 High Street, Christchurch 8011"` |
| `business_phone` | `"03 555 1234"` |
| `business_email` | `"info@smithelectrical.co.nz"` |
| `trade_licence` | `"EW12345"` (optional) |
| `quote_reference` | `"Q-2026-047"` |
| `date_issued` | `"27 April 2026"` |
| `valid_until` | `"27 May 2026"` |
| `customer_name` | `"Jane Wilson"` |
| `customer_company` | `"Wilson Property Group"` (optional) |
| `customer_address` | `"15 Park Avenue, Riccarton"` |
| `job_address` | `"22 Main Road, Addington"` |
| `scope_description` | `"Install 6x LED downlights..."` |
| `line_items` | Array of `{description, qty, rate, amount}` |
| `subtotal` | `"1,200.00"` |
| `gst_amount` | `"180.00"` |
| `total_incl_gst` | `"1,380.00"` |
| `payment_terms` | `"50% deposit on acceptance, balance on completion"` |

---

### Invoice (`templates/invoice/template.html`)

A professional NZ trades invoice with:
- Business header with GST number
- Invoice status badge (due / paid / overdue)
- Customer and job site details
- Job reference link
- Itemised line items table
- Subtotal, GST (15%), and total
- Payment details (bank account, reference)
- Terms footer

**Additional fields beyond quote:**
| Field | Example |
|-------|---------|
| `business_logo_path` | `"business-assets/logo.png"` (optional but expected when `logo.present` is true) |
| `invoice_number` | `"INV-2026-089"` |
| `due_date` | `"11 May 2026"` |
| `status` | `"due"`, `"paid"`, or `"overdue"` |
| `gst_number` | `"123-456-789"` |
| `job_reference` | `"JOB-2026-015"` (optional) |
| `quote_reference` | `"Q-2026-047"` (optional) |
| `bank_account` | `"06-0123-0456789-00"` |
| `bank_reference` | `"INV-2026-089"` |

---

### Site Report (`templates/site-report/template.html`)

A professional site visit / inspection report with:
- Business header
- Site, client, and visit detail blocks
- Purpose of visit section
- Findings table with priority indicators (high / medium / low)
- Numbered recommendations
- Additional notes
- Inspector sign-off
- Disclaimer

**Required data fields:**
| Field | Example |
|-------|---------|
| `business_logo_path` | `"business-assets/logo.png"` (optional but expected when `logo.present` is true) |
| `report_reference` | `"SR-2026-012"` |
| `report_date` | `"27 April 2026"` |
| `inspector_name` | `"Mike Smith"` |
| `site_address` | `"22 Main Road, Addington"` |
| `customer_name` | `"Jane Wilson"` |
| `customer_company` | `"Wilson Property Group"` (optional) |
| `visit_type` | `"Measure-up"`, `"Assessment"`, `"Pre-start"` |
| `visit_duration` | `"1.5 hours"` |
| `visit_purpose` | `"Assess existing wiring..."` |
| `findings` | Array of `{area, description, priority, action}` |
| `recommendations` | Array of strings |
| `additional_notes` | Free text (optional) |

---

## Building Custom HTML

When no starter template fits, construct HTML following these rules:

### Structure
```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="author" content="Business Name">
  <title>Document Title</title>
  <style>
    /* Print CSS goes here — see below */
  </style>
</head>
<body>
  <article>
    <header>...</header>
    <section>...</section>
  </article>
</body>
</html>
```

### Print CSS Essentials

```css
@page {
  size: A4;            /* or A4 landscape */
  margin: 2cm;
  @bottom-center {
    content: "Page " counter(page) " of " counter(pages);
    font-size: 9pt;
    color: #888;
  }
}

body {
  font-family: Arial, Helvetica, sans-serif;
  font-size: 10.5pt;
  line-height: 1.5;
}

/* Keep tables and sections together */
table, .keep-together { page-break-inside: avoid; }

/* Force new page */
.new-page { page-break-before: always; }

/* Never orphan headings */
h2, h3 { page-break-after: avoid; }
```

### Common Traps

| Trap | Fix |
|------|-----|
| Missing fonts | Use web-safe: Arial, Helvetica, Georgia, Times New Roman |
| Absolute image paths | Use relative workspace paths such as `business-assets/logo.png` or inline base64 |
| No page size set | Always set `@page { size: A4; }` |
| Large images | Compress or resize before embedding |
| External resources | Inline everything — no CDN links |
| Literal escape sequences | Use real whitespace, never `\n` in HTML |

### Fonts

Use only fonts guaranteed on the host:
- **Sans-serif**: `Arial, Helvetica, sans-serif`
- **Serif**: `Georgia, 'Times New Roman', serif`
- **Monospace**: `'Courier New', Courier, monospace`

Do not reference Google Fonts or other web fonts by URL — the rendered HTML sent to aPDF.io must be self-contained.
