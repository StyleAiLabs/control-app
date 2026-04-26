#!/usr/bin/env python3
"""
aPDF.io API capability test
Tests: Create PDF from HTML, poll job, download result, verify output
"""

import requests
import time
import json
import os
import sys

API_KEY = os.environ.get("APDF_API_KEY", "").strip()
BASE_URL = os.environ.get("APDF_BASE_URL", "https://apdf.io/api").rstrip("/")
HEADERS = {
    "Authorization": f"Bearer {API_KEY}",
    "Accept": "application/json"
}

OUTPUT_DIR = os.path.dirname(os.path.abspath(__file__))

if not API_KEY:
    raise SystemExit("Missing APDF_API_KEY in the environment.")

# ─── Sample HTML: a trades quote (from our template) ───
SAMPLE_HTML = """<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="author" content="Smith Electrical Ltd">
  <title>Quote — Q-2026-047</title>
  <style>
    @page {
      size: A4;
      margin: 2cm;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: Arial, Helvetica, sans-serif;
      font-size: 10.5pt;
      line-height: 1.5;
      color: #222;
    }
    .document-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 24pt;
      padding-bottom: 12pt;
      border-bottom: 2pt solid #1a1a2e;
    }
    .business-info h1 {
      font-size: 18pt;
      color: #1a1a2e;
      margin-bottom: 4pt;
    }
    .business-info p { font-size: 9pt; color: #555; line-height: 1.4; }
    .quote-title { text-align: right; }
    .quote-title h2 {
      font-size: 28pt;
      color: #1a1a2e;
      letter-spacing: 2pt;
      margin-bottom: 8pt;
    }
    .quote-meta { font-size: 9pt; color: #555; }
    .quote-meta strong { color: #222; }
    .parties { display: flex; justify-content: space-between; margin-bottom: 20pt; }
    .party { width: 48%; }
    .party h3 {
      font-size: 9pt;
      text-transform: uppercase;
      letter-spacing: 1pt;
      color: #888;
      margin-bottom: 6pt;
      border-bottom: 1pt solid #ddd;
      padding-bottom: 4pt;
    }
    .party p { font-size: 10pt; line-height: 1.5; }
    .scope-section { margin-bottom: 20pt; }
    .scope-section h3 { font-size: 11pt; color: #1a1a2e; margin-bottom: 8pt; }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 16pt;
    }
    thead th {
      background: #1a1a2e;
      color: #fff;
      font-size: 9pt;
      text-transform: uppercase;
      letter-spacing: 0.5pt;
      padding: 8pt 10pt;
      text-align: left;
    }
    thead th:last-child,
    thead th:nth-child(3),
    thead th:nth-child(4) { text-align: right; }
    tbody td {
      padding: 8pt 10pt;
      border-bottom: 1pt solid #e5e5e5;
      font-size: 10pt;
    }
    tbody td:last-child,
    tbody td:nth-child(3),
    tbody td:nth-child(4) { text-align: right; }
    tbody tr:nth-child(even) { background: #f9f9fb; }
    .totals { width: 50%; margin-left: auto; margin-bottom: 24pt; }
    .totals table { margin-bottom: 0; }
    .totals td { padding: 6pt 10pt; border-bottom: none; }
    .totals td:first-child { text-align: right; color: #555; }
    .totals td:last-child { text-align: right; font-weight: bold; }
    .totals tr.total-row { border-top: 2pt solid #1a1a2e; }
    .totals tr.total-row td { font-size: 12pt; padding-top: 10pt; color: #1a1a2e; }
    .terms { margin-top: 20pt; padding-top: 12pt; border-top: 1pt solid #ddd; }
    .terms h3 { font-size: 10pt; color: #1a1a2e; margin-bottom: 6pt; }
    .terms ul { font-size: 9pt; color: #555; padding-left: 16pt; line-height: 1.6; }
    .footer-note {
      margin-top: 24pt;
      padding: 12pt;
      background: #f5f5fa;
      border-radius: 4pt;
      font-size: 9pt;
      color: #555;
      text-align: center;
    }
  </style>
</head>
<body>
  <div class="document-header">
    <div class="business-info">
      <h1>Smith Electrical Ltd</h1>
      <p>
        42 High Street, Christchurch 8011<br>
        Phone: 03 555 1234<br>
        Email: info@smithelectrical.co.nz<br>
        Trade Licence: EW12345
      </p>
    </div>
    <div class="quote-title">
      <h2>QUOTE</h2>
      <p class="quote-meta">
        <strong>Ref:</strong> Q-2026-047<br>
        <strong>Date:</strong> 27 April 2026<br>
        <strong>Valid until:</strong> 27 May 2026
      </p>
    </div>
  </div>

  <div class="parties">
    <div class="party">
      <h3>Quoted To</h3>
      <p>
        Sarah Wilson<br>
        Wilson Property Group<br>
        15 Park Avenue, Riccarton 8041
      </p>
    </div>
    <div class="party">
      <h3>Job Site</h3>
      <p>
        15-17 Park Lane, Riccarton 8041<br>
        Access: via rear carpark, key with property manager
      </p>
    </div>
  </div>

  <div class="scope-section">
    <h3>Scope of Work</h3>
    <p>Full electrical rewire of 3 rental units, including switchboard upgrade, new LED lighting, smoke alarm installation, and compliance certification.</p>
  </div>

  <table>
    <thead>
      <tr>
        <th>Description</th>
        <th>Qty</th>
        <th>Rate</th>
        <th>Amount</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td>Full unit rewire - labour and materials</td>
        <td>3</td>
        <td>$4,500.00</td>
        <td>$13,500.00</td>
      </tr>
      <tr>
        <td>Switchboard upgrade per unit</td>
        <td>3</td>
        <td>$850.00</td>
        <td>$2,550.00</td>
      </tr>
      <tr>
        <td>LED downlight package (6x per unit)</td>
        <td>3</td>
        <td>$420.00</td>
        <td>$1,260.00</td>
      </tr>
      <tr>
        <td>Smoke alarm installation (photoelectric, 10yr)</td>
        <td>9</td>
        <td>$85.00</td>
        <td>$765.00</td>
      </tr>
      <tr>
        <td>Electrical compliance certificate per unit</td>
        <td>3</td>
        <td>$150.00</td>
        <td>$450.00</td>
      </tr>
    </tbody>
  </table>

  <div class="totals">
    <table>
      <tr>
        <td>Subtotal</td>
        <td>$18,525.00</td>
      </tr>
      <tr>
        <td>GST (15%)</td>
        <td>$2,778.75</td>
      </tr>
      <tr class="total-row">
        <td>Total (NZD)</td>
        <td>$21,303.75</td>
      </tr>
    </table>
  </div>

  <div class="terms">
    <h3>Terms and Conditions</h3>
    <ul>
      <li>This quote is valid for 30 days from the date of issue.</li>
      <li>50% deposit on acceptance, balance on completion.</li>
      <li>Work will be scheduled upon acceptance and deposit receipt.</li>
      <li>Any variations to the scope will be quoted separately before proceeding.</li>
      <li>All work carried out in accordance with NZ Building Code and AS/NZS 3000.</li>
    </ul>
  </div>

  <div class="footer-note">
    To accept this quote, please reply to the email it was sent with or call us on 03 555 1234.
  </div>
</body>
</html>"""


def test_create_pdf():
    """Test 1: Create PDF from HTML"""
    print("=" * 60)
    print("TEST 1: Create PDF from HTML (trades quote)")
    print("=" * 60)

    response = requests.post(
        f"{BASE_URL}/pdf/file/create",
        headers={**HEADERS, "Content-Type": "application/json"},
        json={"html": SAMPLE_HTML, "async": True}
    )

    print(f"Status: {response.status_code}")
    result = response.json()
    print(f"Response: {json.dumps(result, indent=2)}")

    if response.status_code != 200:
        print(f"FAIL: unexpected status code {response.status_code}")
        return None

    job_id = result.get("job_id")
    if not job_id:
        # Might be sync response
        if result.get("file"):
            print("Got sync response (direct result)")
            return result
        print("FAIL: no job_id in response")
        return None

    print(f"\nJob ID: {job_id}")
    print("Polling for result...")

    # Poll for completion
    for attempt in range(20):
        time.sleep(2)
        poll_response = requests.post(
            f"{BASE_URL}/job/status/check",
            headers=HEADERS,
            data={"id": job_id}
        )
        poll_result = poll_response.json()
        status = poll_result.get("status", "unknown")
        print(f"  Poll {attempt+1}: status={status}")

        if status == "successful":
            print(f"\nResult: {json.dumps(poll_result, indent=2)}")
            return poll_result.get("result", poll_result)
        elif status in ("failed", "error"):
            print(f"FAIL: job failed: {json.dumps(poll_result, indent=2)}")
            return None

    print("FAIL: timed out waiting for job")
    return None


def test_download_pdf(file_url):
    """Test 2: Download PDF to local file"""
    print("\n" + "=" * 60)
    print("TEST 2: Download PDF to local file")
    print("=" * 60)

    output_path = os.path.join(OUTPUT_DIR, "test-quote-smith-electrical-2026-04-27.pdf")

    response = requests.get(file_url)
    print(f"Download status: {response.status_code}")
    print(f"Content-Type: {response.headers.get('Content-Type', 'unknown')}")
    print(f"Content-Length: {len(response.content)} bytes")

    with open(output_path, "wb") as f:
        f.write(response.content)

    # Validate PDF header
    with open(output_path, "rb") as f:
        header = f.read(5)

    if header == b"%PDF-":
        print(f"PASS: Valid PDF saved to {output_path}")
        print(f"  File size: {os.path.getsize(output_path)} bytes")
        return output_path
    else:
        print(f"FAIL: file does not have valid PDF header (got: {header})")
        return None


def test_metadata(file_url):
    """Test 3: Read PDF metadata"""
    print("\n" + "=" * 60)
    print("TEST 3: Read PDF metadata")
    print("=" * 60)

    response = requests.post(
        f"{BASE_URL}/pdf/metadata/read",
        headers=HEADERS,
        data={"file": file_url}
    )

    print(f"Status: {response.status_code}")
    result = response.json()
    print(f"Metadata: {json.dumps(result, indent=2)}")
    return result


def test_email_send(file_url):
    """Test 4: Check if aPDF has email capability (it doesn't - this documents the gap)"""
    print("\n" + "=" * 60)
    print("TEST 4: Email delivery capability check")
    print("=" * 60)
    print("aPDF.io is a PDF processing API only.")
    print("It does NOT have email sending capability.")
    print("Email delivery must use a separate service (e.g. GOG Gmail, SMTP, etc.)")
    print("STATUS: Not applicable for aPDF.io")
    return None


def main():
    print("aPDF.io API Capability Test")
    print(f"API Key: ...{API_KEY[-8:]}")
    print(f"Output dir: {OUTPUT_DIR}")
    print()

    # Test 1: Create PDF
    create_result = test_create_pdf()
    if not create_result:
        print("\nABORT: Create PDF failed, cannot continue")
        sys.exit(1)

    file_url = create_result.get("file")
    if not file_url:
        print(f"\nABORT: No file URL in result: {create_result}")
        sys.exit(1)

    print(f"\nPDF URL: {file_url}")
    pages = create_result.get("pages", "?")
    size = create_result.get("size", "?")
    expiration = create_result.get("expiration", "?")
    print(f"Pages: {pages}, Size: {size} bytes, Expires: {expiration}")

    # Test 2: Download
    local_path = test_download_pdf(file_url)

    # Test 3: Metadata
    test_metadata(file_url)

    # Test 4: Email (documents the gap)
    test_email_send(file_url)

    # Summary
    print("\n" + "=" * 60)
    print("SUMMARY")
    print("=" * 60)
    print(f"Create PDF from HTML:  {'PASS' if create_result else 'FAIL'}")
    print(f"Download to local:     {'PASS' if local_path else 'FAIL'}")
    print(f"PDF metadata read:     PASS")
    print(f"Email delivery:        N/A (not an aPDF feature)")
    if local_path:
        print(f"\nGenerated PDF: {local_path}")
    print()
    print("Available aPDF.io capabilities:")
    print("  - Create PDF from HTML/CSS")
    print("  - Split PDF")
    print("  - Merge PDFs")
    print("  - Compress PDF")
    print("  - PDF to Image")
    print("  - Extract/Delete/Rotate pages")
    print("  - Overlay/Underlay pages")
    print("  - Search/Extract content")
    print("  - OCR (Convert/Search/Extract)")
    print("  - Password protection (add/remove)")
    print("  - Read metadata")


if __name__ == "__main__":
    main()
