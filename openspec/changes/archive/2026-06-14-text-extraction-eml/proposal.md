## Why

OpenRegister's `TextExtractionService` covers PDFs (smalot/pdfparser), Word documents (PhpWord), spreadsheets, and plain-text MIMEs — but not EML. Email files are a regular input for government anonymisation workflows (Wob/Woo correspondence, complaint dossiers, internal email archives), and the current pipeline rejects them: detection skips the file, downstream apps that depend on extraction (DocuDesk's anonymisation, anything else doing entity recognition over email archives) see nothing.

The DocuDesk change `anonymise-output-as-pdf-by-default` has a soft dependency on this work — its EmlBackend in the conversion cascade can't activate until `TextExtractionService` returns something for `message/rfc822`. Until then, EML inputs in DocuDesk's default PDF mode 422 with a known fallback hint.

This change adds EML support to `TextExtractionService` via `zbateson/mail-mime-parser`. Two outputs:

1. **Plain-text extraction** (existing pattern) — headers (From/To/Cc/Subject/Date) followed by the email body (preferring `text/plain`, falling back to HTML stripped to text), followed by recursively-extracted text from each attachment of a known extractable type. One flat string suitable for entity detection. Replaces the current "EML returns null/empty" gap.
2. **Structured parse** (new public API) — a richer return shape carrying the headers, both `text/plain` and `text/html` body parts (when available), and the list of attachments with their raw bytes + MIME + filename. This exists specifically to enable downstream apps (DocuDesk first, via the paired `eml-pdf-assembly` change) to build a PDF that combines email content with attachment rendering or embedding.

## What Changes

- **NEW dep:** `zbateson/mail-mime-parser` added to OpenRegister's `composer.json`. Pure-PHP RFC 822 parser; no PHP extension requirement (avoids the deprecated `imap` extension and the optional `mailparse` PECL extension).
- **NEW:** MIME detection for `message/rfc822` in `TextExtractionService::extractSourceText` — the existing if/else cascade gains an EML branch alongside PDF, Word, spreadsheet, and text branches.
- **NEW:** Private method `extractEml(\OCP\Files\File $file): ?string` returning the flat plain-text representation. Includes a header block, body content, and recursively-extracted attachment text.
- **NEW:** Public method `parseEmlStructured(\OCP\Files\File $file): EmlStructure` (new value-object class `lib/Service/TextExtraction/EmlStructure.php`). Returns a richer object with headers, body (both `plainText` and `html` populated when each part exists), and an attachments list with bytes + MIME + filename. Recursive: an EML attachment carries a nested `EmlStructure`.
- **NEW:** HTML body fallback for the flat plain-text path. When `text/plain` is missing or empty, strip `text/html` to text (drop `<style>` / `<script>` content, strip tags, collapse whitespace). The structured-parse path keeps both bodies separate; the consumer chooses.
- **NEW:** Recursive attachment text extraction in the flat plain-text path. For each attachment of a known extractable MIME (`application/pdf`, Word, plain-text), the method calls back into `TextExtractionService` and inlines the extracted text under a marker (`--- Attachment: <filename> ---`). Non-extractable attachments are listed by name + MIME at their position in the multipart structure.
- **NEW capability:** `text-extraction-eml`. Tightly scoped — covers ONLY the EML extension. The broader `TextExtractionService` surface (existing PDF/Word/spreadsheet support) remains uncovered by an OpenSpec capability spec; retrofitting that is out of scope here, same way `entity-relation-grondslagen` didn't try to retrofit the entity-recognition surface.
- **NO new endpoints.** Extraction is invoked through the existing `extractFile($fileId)` path. The structured-parse method is a service-level addition consumed via DI from cooperating services (DocuDesk's EmlBackend, future consumers).
- **NO breaking change.** Callers that today see "EML returns null" continue to work — they now see populated text. No existing extracted-text contract is modified for non-EML files.

### Output shape (flat plain-text extraction)

```
From: Burgemeester De Vries <burg@gemeente.nl>
To: Raadslid Bakker <s.bakker@gemeente.nl>; ...
Cc: ...
Subject: Beantwoording Woo-verzoek 2025-017
Date: 2026-04-12T11:00:00Z

Geachte raadslid,

(... body text ...)

--- Attachment: bijlage-1.pdf ---
(... extracted text from the PDF attachment ...)

--- Attachment: image.png (image/png, not extractable) ---
```

### Output shape (structured parse)

```php
EmlStructure {
    headers: [
        'from' => 'Burgemeester De Vries <burg@gemeente.nl>',
        'to' => ['Raadslid Bakker <s.bakker@gemeente.nl>', ...],
        'cc' => [...],
        'subject' => 'Beantwoording Woo-verzoek 2025-017',
        'date' => DateTimeImmutable('2026-04-12T11:00:00Z'),
        'messageId' => '...@gemeente.nl',
    ],
    body: [
        'plainText' => 'Geachte raadslid, ...',
        'html' => '<p>Geachte raadslid, ...</p>',
    ],
    attachments: [
        EmlAttachment {
            filename: 'bijlage-1.pdf',
            mimeType: 'application/pdf',
            content: <bytes>,
            isInline: false,
            contentId: null,
            nestedEml: null,
        },
        EmlAttachment {
            filename: 'forwarded.eml',
            mimeType: 'message/rfc822',
            content: <bytes>,
            isInline: false,
            contentId: null,
            nestedEml: EmlStructure { ... },  // recursive
        },
    ],
}
```

### Out of scope

- **Calendar invites** (`text/calendar`, `.ics`) embedded in EML — text extraction of calendar bodies is a separate concern; v1 lists them as attachments.
- **Encrypted EML** (S/MIME, PGP) — extraction returns the encrypted blob's headers only; body/attachments are not decrypted. Decryption integration is a separate change.
- **Mail-archive containers** (`mbox`, `pst`, `mst`) — these aren't EMLs; if support is wanted, a separate change handles the container format and recurses per-message into this EML parser.
- **A retrofit OpenSpec capability for the broader TextExtractionService surface** (PDF, Word, spreadsheet) — out of scope here. This change covers EML only.
- **Asynchronous extraction** for very large EMLs with many attachments — synchronous in v1.
- **Configurable header inclusion** for the flat plain-text path — always-on in v1; if a tenant wants body-only, a follow-up adds the toggle.
- **Recursive attachment depth limit** — the parser may be vulnerable to malicious nesting (EML containing EML containing EML ...). v1 caps at depth 3 with a hard limit; deeper nesting is not extracted and the outermost attachment is listed by name only.

## Capabilities

### New Capabilities

- `text-extraction-eml`: EML support in `TextExtractionService` — flat plain-text extraction (headers + body + recursively-extracted attachment text) and a structured-parse method returning headers, body (plain + html), and attachments-with-bytes.

### Modified Capabilities

(none — the broader `TextExtractionService` surface is currently uncovered by an OpenSpec capability; this change does not retrofit it. See `Out of scope`.)

## Impact

- **Code (openregister):**
  - `composer.json` — add `zbateson/mail-mime-parser`.
  - `lib/Service/TextExtractionService.php` — extend the MIME branch with `message/rfc822`. Add private `extractEml()` method.
  - `lib/Service/TextExtraction/EmlStructure.php` — NEW value object class for the structured parse result.
  - `lib/Service/TextExtraction/EmlAttachment.php` — NEW value object class for individual attachments.
  - `lib/Service/TextExtraction/EmlParser.php` — NEW. Wraps `zbateson/mail-mime-parser`; provides both the flat-text and structured-parse paths. `TextExtractionService` delegates to it.
- **API contract:** No changes to existing API. The flat-text path now returns populated content for EML inputs (where it previously returned null/empty). The new `parseEmlStructured()` method is a service-level addition; callers acquire `TextExtractionService` via DI and call the new method. No HTTP surface changes.
- **Cross-app:**
  - Unblocks DocuDesk's `anonymise-output-as-pdf-by-default` EML branch.
  - Paired with DocuDesk's `eml-pdf-assembly` change which uses the structured parse to render headers + body (HTML when available) + attachments into a PDF/A-3b.
  - opencatalogi and softwarecatalog don't currently extract EML; unaffected.
- **Performance:** Per-EML extraction adds a parse step. For typical emails (<1 MB, <5 attachments), well under a second. Large attachments contribute their own extraction cost (recursive PDF parse, etc.) — bounded by the existing per-type extractors. Depth-3 recursion limit caps worst case.
- **Privacy / compliance:** EML headers and bodies often carry PII; making them visible to entity detection improves anonymisation completeness. No new data leaves the OR boundary.
- **Tests:** Unit tests for the EML parser covering: header extraction, plain-text body, HTML body fallback, multipart structures, attachments (extractable + non-extractable), nested EML, depth limit, malformed EML graceful-fail.
- **Migration:** None. No DB changes. EML inputs that today produce no extracted text will start producing content after the change ships. If a tenant relied on EML files producing empty extraction (unlikely), they get a behaviour change — documented in CHANGELOG under "Behavior changes".
