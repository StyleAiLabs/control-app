# PDF Generation — Dependencies

## aPDF.io API

### What it is

aPDF.io is a hosted HTML-to-PDF rendering API. It accepts HTML/CSS content via a REST endpoint and returns a professionally rendered PDF document. The API runs asynchronously: submit a job, poll for completion, then download the result.

### Dependency type

- **Type**: managed API (SaaS)
- **Provided by Sync360**: yes — Sync360 must provision `APDF_API_KEY` into the tenant runtime environment
- **Required**: yes — the skill cannot function without it
- **Fallback**: fail closed — do not attempt alternative PDF generation

### API details

| Property | Value |
|----------|-------|
| Base URL | `https://apdf.io/api` |
| Auth | Bearer token (`Authorization: Bearer <API_KEY>`) |
| Runtime env key | `APDF_API_KEY` in tenant runtime environment |
| Rate limit | 2 req/sec, 20 req/min |
| File retention | 60 minutes |
| Max upload | 100 MB |

### Endpoints used by this skill

| Endpoint | Purpose |
|----------|---------|
| `POST /pdf/file/create` | Submit HTML for PDF rendering (async) |
| `POST /job/status/check` | Poll for job completion |
| `POST /pdf/metadata/read` | Read PDF metadata (optional) |

### Async workflow

```
1. POST /pdf/file/create  →  {"job_id": "..."}
2. POST /job/status/check  →  {"status": "running", ...}
3. POST /job/status/check  →  {"status": "successful", "result": {"file": "https://...", "pages": 2, "size": 51690}}
4. GET <result.file URL>   →  download PDF binary
```

### Verification

The skill verifies API availability by checking the response to the create call:
- `200 OK` with `job_id` → API is working
- `401 Unauthorized` → API key is invalid
- `429 Too Many Requests` → rate limited, wait and retry
- Network error → API unreachable

### Common failure modes

| Symptom | Likely cause | Resolution |
|---------|-------------|------------|
| `401 Unauthorized` | API key invalid or expired | Re-provision the key in the tenant runtime environment |
| `422 Validation Error` | Malformed HTML or bad request params | Fix the HTML content or request format |
| `429 Too Many Requests` | Rate limit exceeded | Wait 30s and retry; reduce concurrent requests |
| Job `status: "failed"` | HTML rendering error | Check HTML for invalid CSS or unsupported features |
| Empty/corrupt download | S3 URL expired (>60 min) | Re-generate the PDF and download promptly |
| `page_size: letter` | `@page { size: A4 }` not fully applied | aPDF may default to US Letter for some CSS |

### What the skill does NOT do

- Create or manage aPDF.io accounts
- Provision or rotate API keys
- Store API keys in skill files (must be injected into runtime env by Sync360)
- Use aPDF.io for email delivery (email uses GOG Gmail)
- Call aPDF.io endpoints not listed above

### Available aPDF.io capabilities (not used in v2.0)

These additional aPDF.io endpoints are available but out of scope for this version:

- Split PDF, Merge PDFs, Compress PDF
- PDF to Image conversion
- Extract/Delete/Rotate pages
- Overlay/Underlay pages
- Search/Extract content, OCR
- Password protection (add/remove)

These could be added in a future "advanced PDF operations" module.

## Google Drive (GOG)

- **Type**: managed auth / integration
- **Provided by Sync360**: yes, via GOG
- **Required**: yes — PDF must be uploaded to Drive
- **Verification**: `gog drive --help` returns usage info

## Gmail (GOG)

- **Type**: managed auth / integration
- **Provided by Sync360**: yes, via GOG
- **Required**: no — email delivery is optional
- **Verification**: `gog gmail --help` returns usage info
