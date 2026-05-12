# EML Text Extraction

OpenRegister's `TextExtractionService` supports `.eml` files (`message/rfc822`) via two output paths that share an underlying `zbateson/mail-mime-parser` invocation:

1. **Flat plain-text** — used by the existing entity-detection pipeline (`TextExtractionService::extractFile`).
2. **Structured parse** — `TextExtractionService::parseEmlStructured(File): EmlStructure`, used by cross-app consumers (notably DocuDesk's `eml-pdf-assembly`) that need headers + body + attachments as typed objects.

## The flat path (`extractFile` → `extractEml`)

For a file with `mimetype: message/rfc822`, `extractFile` returns a flat plain-text string formatted as:

```
From: <decoded value>
To: <decoded value>
Cc: <decoded value>            (omitted when empty)
Subject: <decoded value>
Date: <ISO-8601 datetime>      (omitted when missing or unparseable)

<body — text/plain preferred; text/html stripped to text otherwise>

--- Attachment: <filename> ---
<attachment text, when extractable>

--- Attachment: <other> (mime, not extractable inline in v1) ---
```

Headers are RFC 2047 decoded by zbateson. The body uses `text/plain` when present, otherwise the HTML body stripped to text. Nested `message/rfc822` attachments are inlined via recursive flattening (subject to the depth cap below); other attachment MIME types (PDF / DOCX / text) are listed by filename + MIME type in v1, with inline text extraction tracked as a follow-up.

If parsing fails irrecoverably, `extractEml` logs the error (PII-sanitised — see below) and returns `null`, matching the existing failure pattern of other extractors.

## The structured path (`parseEmlStructured`)

```php
$structure = $textExtractionService->parseEmlStructured($file);
// $structure->headers      array<string, mixed>      — from, to, cc, subject, date (DateTimeImmutable|null), messageId
// $structure->body          EmlBody                   — plainText: ?string, html: ?string
// $structure->attachments   array<int, EmlAttachment> — in multipart-document order
```

Each `EmlAttachment` carries:

| Field | Type | Notes |
|---|---|---|
| `filename` | `string` | Always non-empty; resolved Content-Disposition `filename` → Content-Type `name` → `attachment-<n>` |
| `mimeType` | `string` | From `Content-Type` |
| `content` | `string` | **Decoded binary bytes** — not the base64 transport string. Consumers can embed directly into PDF/A-3 file attachments or `data:` URIs |
| `isInline` | `bool` | `true` when `Content-Disposition: inline` |
| `contentId` | `string\|null` | `Content-ID` value with angle brackets stripped |
| `nestedEml` | `EmlStructure\|null` | Recursively parsed nested EML (depth-capped) |

`parseEmlStructured` **MUST throw** `EmlParseException` on irrecoverable malformed input — it does not return null or a partial structure. Consumers rely on exception propagation to drive their fallback paths.

## Recursion cap

Nested `message/rfc822` attachments are followed up to depth 3. Depth is measured as the number of recursive `parse` calls from the root: root = depth 0, the first nested EML is depth 1, etc. At the boundary, the deeper attachment is exposed via its `EmlAttachment` shell with `nestedEml = null`. A debug log line records each cap-hit.

## Encoding fallback

Non-UTF-8 body parts (e.g. ISO-8859-1, Windows-1252 — common in legacy Dutch government archives) are transcoded to UTF-8 via `mb_detect_encoding` + `mb_convert_encoding`. When detection fails, the raw bytes are passed through unchanged so consumers see at-least-something rather than an exception.

## PII-sanitised logging (ADR-005)

Parse-failure log lines from both `extractEml` and `parseEmlStructured` are sanitised before write:

- email addresses → `<redacted>`
- quoted strings (likely display names / subjects) → `<redacted>`
- angle-bracketed values (likely addresses / Message-IDs) → `<redacted>`

Permitted log payload: file ID, MIME type, exception class name, structural detail. Body content and header values never reach log output.

## Limitations / follow-ups

- **Inline text extraction for non-EML attachments (PDF, DOCX, text)** is deferred in v1. The flat path lists them by name + MIME type only. The DocuDesk-side `eml-pdf-assembly` consumer renders attachments richly for its PDF/A-3 output and does not depend on flat-path inlining; entity detection on attachment content is the v1 gap if/when it becomes load-bearing.
- **Address-list parsing** in the flat headers uses a simple comma-split; structured-address iteration (zbateson exposes per-address objects) is a possible refinement if quoted-comma display names emerge as a real pain point.

## Spec references

- Capability: [`openspec/changes/text-extraction-eml/specs/text-extraction-eml/spec.md`](../../openspec/changes/text-extraction-eml/specs/text-extraction-eml/spec.md)
- Design (decisions on the two paths, recursion, encoding, PII): [`openspec/changes/text-extraction-eml/design.md`](../../openspec/changes/text-extraction-eml/design.md)
- ADR-005 (no PII in logs)
- Dependency: `zbateson/mail-mime-parser:^3.0`
