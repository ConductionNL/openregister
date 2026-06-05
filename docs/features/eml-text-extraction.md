# EML Text Extraction

<<<<<<< HEAD
> **Developer-facing reference.** This page documents the EML
> (`message/rfc822`) support that consumer apps integrate against
> (`TextExtractionService::extractFile`, `parseEmlStructured`). For
> end-user behaviour, see the operator-facing release notes — there is no
> UI surface dedicated to EML extraction; the extracted text feeds the
> existing entity-detection pipeline transparently.

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
- **Address-list parsing** is RFC 2822-aware: the `splitAddressList` walker preserves commas inside double-quoted display names and inside angle-bracketed addresses. Backslash-escaped quotes are honoured. Structured-address iteration via zbateson's per-address objects remains available for richer use cases.

## Spec references

- Capability: [`openspec/changes/text-extraction-eml/specs/text-extraction-eml/spec.md`](../../openspec/changes/text-extraction-eml/specs/text-extraction-eml/spec.md)
- Design (decisions on the two paths, recursion, encoding, PII): [`openspec/changes/text-extraction-eml/design.md`](../../openspec/changes/text-extraction-eml/design.md)
- ADR-005 (no PII in logs)
- Dependency: `zbateson/mail-mime-parser:^3.0`
=======
OpenRegister's `TextExtractionService` supports EML files (`message/rfc822`) alongside PDF, Word, and spreadsheet formats. Two extraction paths share one underlying parser.

## Flat plain-text extraction

The existing `extractFile($fileId)` workflow now handles EML inputs. The output is a single UTF-8 string in the following order:

```
From: Alice Bakker <alice@example.com>
To: Bob de Vries <bob@example.com>; Carol Jansen <carol@example.com>
Cc: Dave Mulder <dave@example.com>
Subject: Beantwoording Woo-verzoek 2025-017
Date: 2026-04-12T11:00:00+0000

Geachte raadslid,

(... body text ...)

--- Attachment: bijlage-1.pdf ---
(... extracted text from the PDF attachment ...)

--- Attachment: image.png (image/png, not extractable) ---
```

Headers are decoded from RFC 2047 encoded-word form. The body prefers `text/plain`; if only `text/html` is present, OpenRegister strips tags and decodes entities. Non-extractable attachments (images, binary blobs, calendar invites) appear as a single marker line with no body.

## Structured parse

The `parseEmlStructured(\OCP\Files\File $file): EmlStructure` method returns a rich value object:

```php
use OCA\OpenRegister\Service\TextExtractionService;
use OCA\OpenRegister\Exception\EmlParseException;

$textExtraction = $container->get(TextExtractionService::class);

try {
    $structure = $textExtraction->parseEmlStructured($ncFile);

    // Headers
    $from    = $structure->headers['from'];        // "Alice Bakker <alice@example.com>"
    $to      = $structure->headers['to'];          // string[] — always an array
    $subject = $structure->headers['subject'];
    $date    = $structure->headers['date'];        // DateTimeImmutable|null

    // Body — consumer picks the part they need
    $plainText = $structure->body->plainText;      // string|null
    $html      = $structure->body->html;           // string|null

    // Attachments — in multipart order
    foreach ($structure->attachments as $attachment) {
        $attachment->filename;    // never empty
        $attachment->mimeType;
        $attachment->content;     // decoded bytes — ready to embed in PDF or data: URI
        $attachment->isInline;
        $attachment->contentId;   // for cid: HTML references
        $attachment->nestedEml;   // EmlStructure|null — populated for forwarded EMLs
    }
} catch (EmlParseException $e) {
    // Log and handle — $e->getMessage() is PII-safe
}
```

## Implementation details

| | |
|---|---|
| **Library** | `zbateson/mail-mime-parser` — pure PHP, no PECL extension required |
| **Recursion limit** | Nested `message/rfc822` attachments are parsed up to depth 3 (root = 0). Beyond that, the attachment is listed without a `nestedEml`. |
| **PII in logs** | Per ADR-005, logs never contain email addresses, display names, Subject content, body content, or attachment filenames. Only file ID, MIME type, exception class name, and structural details are logged. |
| **Encoding** | Non-UTF-8 body parts are transcoded on a best-effort basis via `mb_detect_encoding` + `mb_convert_encoding`. |
| **HTML stripping** | `<style>` and `<script>` blocks are removed; block-level elements are converted to newlines; tags are stripped; entities are decoded. |

## DocuDesk integration

DocuDesk's `eml-pdf-assembly` change uses `parseEmlStructured()` to render EML files as PDF/A-3b documents. It prefers the `html` body for visual fidelity, falling back to `plainText` wrapped in `<pre>`. Attachment bytes are embedded directly as PDF/A-3 file attachments via the decoded `content` field (no further base64 decoding needed).

## Changelog

- **Added in v0.3** — EML support via `zbateson/mail-mime-parser` ([#32](https://codeberg.org/Conduction/openregister/issues/32)).
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
