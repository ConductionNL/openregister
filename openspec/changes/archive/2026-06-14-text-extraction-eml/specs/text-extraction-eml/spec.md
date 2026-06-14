---
status: draft
---

# Text Extraction — EML

## Purpose

Defines EML (`message/rfc822`) text extraction in OpenRegister's `TextExtractionService`. Two output paths share an underlying parser based on `zbateson/mail-mime-parser`: a flat plain-text path (the existing `TextExtractionService` pattern, extended to EML), and a new structured-parse path that exposes headers, both `text/plain` and `text/html` body parts, and attachment bytes for downstream rich-rendering consumers (DocuDesk's `eml-pdf-assembly` first).

## ADDED Requirements

### Requirement: `TextExtractionService` MUST handle `message/rfc822` MIME inputs

The MIME branch in `extractSourceText` (or equivalent cascade method) MUST recognise `message/rfc822` and delegate to a private `extractEml(File $file): ?string` method. The method MUST return populated plain text (not null) for valid EML inputs.

#### Scenario: EML input produces populated extracted text

- **GIVEN** an EML file (mime `message/rfc822`) with headers and a `text/plain` body
- **WHEN** `TextExtractionService::extractFile($fileId)` is called
- **THEN** the persisted extracted-text for the file is non-empty
- **AND** the text contains at minimum the email's body content

#### Scenario: Pre-change EML inputs that previously returned null now return populated text

- **GIVEN** an EML file that had been processed before this change landed (resulting in null / empty extracted text)
- **WHEN** the file is re-extracted via `forceReExtract: true`
- **THEN** the persisted extracted-text is now populated per the v1 EML extraction shape

### Requirement: The flat plain-text output MUST include headers, body, and recursively-extracted attachment text

The flat output (returned by `extractEml`) MUST be ordered:

1. Header block: `From:`, `To:`, `Cc:` (when present), `Subject:`, `Date:` (when present). One header per line. After the block, a blank line.
2. Body content: `text/plain` if present; otherwise `text/html` stripped to text.
3. For each attachment, in multipart-document order:
   - If the attachment's MIME is recursively extractable (`application/pdf`, the Word DOCX/DOC MIMEs, text MIMEs, or `message/rfc822` for nested EML), the method MUST call `TextExtractionService` for that attachment and inline the result under a marker line `--- Attachment: <filename> ---`.
   - Otherwise, the marker line MUST be `--- Attachment: <filename> (<mimeType>, not extractable) ---` with no body following.

#### Scenario: Header block precedes body

- **GIVEN** an EML with `From: alice@example`, `To: bob@example`, `Subject: Hi`, `Date: 2026-04-12T11:00:00Z`, and a body "Hello Bob"
- **WHEN** `extractEml` runs
- **THEN** the output begins with `From: alice@example` (and the other headers in order)
- **AND** a blank line separates the header block from the body
- **AND** the body content "Hello Bob" follows

#### Scenario: HTML-only body falls back to stripped text

- **GIVEN** an EML whose body has only a `text/html` part (no `text/plain`)
- **WHEN** `extractEml` runs
- **THEN** the body section contains the HTML stripped to text (no `<p>`, `<a>` tags; entities decoded; whitespace collapsed)

#### Scenario: multipart/alternative — `text/plain` is preferred when both parts are present

- **GIVEN** an EML with a `multipart/alternative` body containing both a `text/plain` part ("Hello Bob") and a `text/html` part (`<p>Hello <b>Bob</b></p>`)
- **WHEN** `extractEml` runs
- **THEN** the body section MUST contain the `text/plain` content "Hello Bob"
- **AND** the body section MUST NOT contain raw HTML tags (`<p>`, `<b>`)
- **AND** the HTML part MUST NOT be concatenated to the plain part — only one is emitted

#### Scenario: Recursive attachment extraction for PDF attachment

- **GIVEN** an EML with a PDF attachment named `report.pdf`
- **WHEN** `extractEml` runs
- **THEN** the output contains a marker line `--- Attachment: report.pdf ---`
- **AND** below the marker, the extracted text from the PDF is inlined

#### Scenario: Non-extractable attachment listed by name + MIME

- **GIVEN** an EML with a PNG attachment named `image.png`
- **WHEN** `extractEml` runs
- **THEN** the output contains exactly one marker line `--- Attachment: image.png (image/png, not extractable) ---`
- **AND** no body content follows the marker

### Requirement: A new public method `parseEmlStructured()` MUST return an `EmlStructure` value object

The method MUST be public and accept an `\OCP\Files\File`. It MUST return an immutable `EmlStructure` value object with:

- `headers` — associative array including `from` (string), `to` (string array), `cc` (string array), `subject` (string), `date` (DateTimeImmutable or null), `messageId` (string or null), and any other parsed headers the implementation chooses to expose.
- `body` — value object with `plainText` (string or null) AND `html` (string or null). Both populated when each part exists; either or both can be null.
- `attachments` — array of `EmlAttachment` value objects in multipart order.

#### Scenario: Structured parse returns populated headers

- **GIVEN** an EML with full standard headers
- **WHEN** `parseEmlStructured($file)` is called
- **THEN** `result.headers['from']` is the parsed From line (RFC 2047-decoded if encoded)
- **AND** `result.headers['to']` is an array of recipients
- **AND** `result.headers['date']` is a `DateTimeImmutable` matching the Date header

#### Scenario: Both plainText and html bodies are exposed when present

- **GIVEN** a multipart EML with both `text/plain` and `text/html` parts
- **WHEN** `parseEmlStructured` runs
- **THEN** `result.body->plainText` contains the plain part
- **AND** `result.body->html` contains the HTML part
- **AND** both are populated (consumer chooses)

#### Scenario: Single-body EMLs populate only the present part

- **GIVEN** an EML with only a `text/html` part
- **WHEN** `parseEmlStructured` runs
- **THEN** `result.body->plainText` is null
- **AND** `result.body->html` contains the HTML

#### Scenario: Date header malformed — date field is null

- **GIVEN** an EML whose Date header is unparseable
- **WHEN** `parseEmlStructured` runs
- **THEN** `result.headers['date']` is null
- **AND** the rest of the structure is populated normally
- **AND** no exception is thrown

### Requirement: Each `EmlAttachment` MUST carry filename, MIME type, raw bytes, and inline / contentId metadata

Each entry in `EmlStructure.attachments[]` MUST be an `EmlAttachment` with:

- `filename` (string) — from the Content-Disposition `filename` parameter; if absent, from the Content-Type `name` parameter; if both are absent, generated as `attachment-<n>` where `<n>` is the 1-indexed position of the attachment in the multipart-document order. The generated form MUST always be a non-empty string so consumers can render it as a label without special-casing empty names.
- `mimeType` (string) — from Content-Type.
- `content` (string) — MUST be the decoded binary content of the attachment (NOT re-encoded or kept as the MIME-transport base64 string). `zbateson/mail-mime-parser` returns decoded bytes from `$part->getContent()` by default; implementations MUST use that path, not `$part->getRawContent()`. Consumers (e.g. DocuDesk's `eml-pdf-assembly` building PDF/A-3 file attachments or `data:` URIs) can rely on `content` being raw bytes ready to embed, and MUST NOT base64-decode it again.
- `isInline` (bool) — true if the attachment has Content-Disposition: inline.
- `contentId` (string or null) — for HTML body references via `cid:` URLs.
- `nestedEml` (`EmlStructure` or null) — populated if `mimeType === 'message/rfc822'` and the depth limit is not exceeded.

#### Scenario: Standard PDF attachment

- **GIVEN** an EML with a single PDF attachment
- **WHEN** structured-parse runs
- **THEN** `result.attachments[0]` has `filename: "<original.pdf>"`, `mimeType: "application/pdf"`, populated `content` bytes, `isInline: false`, `contentId: null`, `nestedEml: null`

#### Scenario: Inline image attachment

- **GIVEN** an EML where the HTML body references an inline image via `<img src="cid:image1@example">`
- **AND** the EML carries the inline image as an attachment with Content-ID `<image1@example>`
- **WHEN** structured-parse runs
- **THEN** the matching attachment has `isInline: true`, `contentId: "image1@example"`

#### Scenario: Nested EML attachment

- **GIVEN** an EML that contains another EML as an attachment (forwarded message)
- **WHEN** structured-parse runs
- **THEN** the nested attachment has `mimeType: "message/rfc822"`, `nestedEml` populated with its own `EmlStructure`

#### Scenario: Attachment with neither Content-Disposition filename nor Content-Type name

- **GIVEN** an EML whose second attachment has no `filename` parameter on either Content-Disposition or Content-Type
- **WHEN** structured-parse runs
- **THEN** `result.attachments[1].filename` MUST be `attachment-2` (1-indexed position fallback)
- **AND** the value MUST NOT be empty or null

### Requirement: Recursive nesting of `message/rfc822` MUST be capped at depth 3

The structured parse MUST follow `message/rfc822` attachments recursively, but only up to depth 3. **Depth is measured as the number of recursive `parseEmlStructured` calls made from the root**: the root EML is depth 0, the first nested EML is depth 1, etc. The limit of 3 therefore permits three nested calls — once a parse at depth 3 has completed, any further `message/rfc822` attachments inside it MUST have `nestedEml: null`. The flat path MUST follow the same limit — at the same depth boundary, EML attachments are listed by name only without inline extraction.

#### Scenario: Depth-3 EML chain is fully recursed

- **GIVEN** EML A (depth 0) containing EML B (depth 1) containing EML C (depth 2) containing EML D (depth 3)
- **WHEN** `parseEmlStructured(A)` runs
- **THEN** A, B, C, and D are all parsed (`nestedEml` populated on A.attachments[B], B.attachments[C], and C.attachments[D])
- **AND** any `message/rfc822` attachment inside D (which would be at depth 4) has `nestedEml: null`

#### Scenario: Depth-4+ chain stops at the limit

- **GIVEN** an EML chain longer than depth 3
- **WHEN** the parse runs
- **THEN** at the depth-3 level, the next-level EML attachment carries `nestedEml: null` even though `mimeType: "message/rfc822"`
- **AND** no exception is thrown
- **AND** a debug-level log entry notes the depth-cap activation

### Requirement: Header block in flat output MUST decode RFC 2047 encoded-words

When a header value uses RFC 2047 encoded-word form (`=?utf-8?B?<base64>?=` or `=?utf-8?Q?<quoted-printable>?=`), the flat output MUST contain the decoded plain UTF-8 string. Encoded-word artefacts MUST NOT leak into the output.

#### Scenario: Encoded-word From header is decoded

- **GIVEN** an EML whose From header is `From: =?utf-8?B?QnVyZ2VtZWVzdGVyIERlIFZyaWVz?= <burg@gemeente.nl>`
- **WHEN** `extractEml` runs
- **THEN** the From line in the output is `From: Burgemeester De Vries <burg@gemeente.nl>` (decoded)

### Requirement: Malformed input MUST NOT throw from `extractEml`; `parseEmlStructured` MUST throw a typed exception

`extractEml` MUST follow the existing extraction-failure pattern — return null on irrecoverable parse error and log the error. `parseEmlStructured` MUST throw a typed `EmlParseException` on irrecoverable malformed input (it MUST NOT return null, an empty `EmlStructure`, or a partially-populated one in this case); consumers rely on exception propagation to drive their fallback paths (e.g. DocuDesk's `eml-pdf-assembly` falls back to flat `extractEml` text when `parseEmlStructured` throws — silently returning a partial result would skip that fallback and produce a corrupt PDF). Both paths MUST handle minor malformations (missing optional headers, unusual encoding, extra whitespace) gracefully without raising errors.

#### Scenario: Catastrophically broken EML returns null from extractEml

- **GIVEN** a file that's labelled `message/rfc822` but contains binary garbage
- **WHEN** `TextExtractionService::extractFile` runs
- **THEN** the extracted-text is null (or the existing failure marker)
- **AND** an error is logged with details
- **AND** no exception propagates to the caller

#### Scenario: Catastrophically broken EML throws from parseEmlStructured

- **GIVEN** the same broken file
- **WHEN** `parseEmlStructured($file)` is called directly
- **THEN** `EmlParseException` is thrown
- **AND** the exception's message identifies the parse failure point

### Requirement: `extractEml` and `parseEmlStructured` MUST NOT log PII (ADR-005)

EML headers and bodies contain PII by definition: email addresses, display names, subject lines, and body text. Per ADR-005 ("NO PII in logs, error responses, or debug output"), both methods MUST NOT include any of the following in log lines, exception messages that are subsequently logged, error responses, or debug output:

- email addresses (From, To, Cc, Bcc, Reply-To headers)
- display names
- Subject header content
- body content (plain-text or HTML)
- attachment filenames (which can themselves carry PII, e.g. `paspoort-de_vries.pdf`)

Permitted log content is restricted to **structural** failure information: the Nextcloud file ID, the MIME type, the parser exception class name, and the depth at which a recursion-limit was hit. When the underlying `zbateson/mail-mime-parser` raises an exception whose message embeds PII (e.g. a header excerpt), implementations MUST sanitize the message — replace addresses / names / subjects with `<redacted>` — before logging or rethrowing.

#### Scenario: Parser failure log contains no PII

- **GIVEN** an EML with `From: alice@example.com` and `Subject: Confidential — case 123`
- **AND** the parser fails (zbateson exception including the From and Subject in its message)
- **WHEN** `extractEml` runs and the failure is logged
- **THEN** the log entry MUST NOT contain `alice@example.com`, "Confidential", or "case 123"
- **AND** the log entry MUST contain at most: the file ID, the MIME type `message/rfc822`, the exception class name, and any sanitised structural detail

#### Scenario: `EmlParseException` message is sanitised before logging

- **GIVEN** `parseEmlStructured` is called on a malformed EML and the underlying parser raises an exception whose `getMessage()` embeds a header value
- **WHEN** the consumer catches the exception and the implementation logs it
- **THEN** the logged message MUST have addresses, names, and subject content replaced with `<redacted>`
- **AND** the exception class name and the file ID MUST remain in the log line

### Requirement: Non-UTF-8 body parts MUST be transcoded to UTF-8 on a best-effort basis

When a body part declares a character set other than UTF-8 (e.g. `Content-Type: text/plain; charset=ISO-8859-1` or `charset=Windows-1252` — common in legacy Dutch government archives), both `extractEml` and `parseEmlStructured` MUST attempt to transcode the bytes to UTF-8 before exposing them in the flat output or the `EmlStructure.body` fields. The recommended implementation uses `mb_detect_encoding` (with a candidate list including the declared charset) followed by `mb_convert_encoding` to UTF-8.

If transcoding fails (an undetectable or unsupported source charset), the raw bytes MAY be included as-is and an implementation-internal log entry MUST note the transcode failure (subject to the no-PII Requirement above — only the failure reason and charset name, not the body content).

#### Scenario: ISO-8859-1 body is transcoded to UTF-8

- **GIVEN** an EML with `Content-Type: text/plain; charset=ISO-8859-1` and a body containing the bytes for "Café" in ISO-8859-1 encoding (`C` `a` `f` `0xE9`)
- **WHEN** `extractEml` runs
- **THEN** the body section in the flat output contains the UTF-8 string `Café` (bytes `C` `a` `f` `0xC3 0xA9`)

### Requirement: The change MUST NOT modify behaviour for non-EML files

All existing MIME branches in `TextExtractionService` (PDF, Word, spreadsheet, plain text) MUST behave identically before and after this change. The new EML branch is additive and orthogonal.

#### Scenario: PDF extraction unchanged

- **GIVEN** a PDF file
- **WHEN** `extractFile` runs after this change is applied
- **THEN** the extracted text is identical to what the pre-change code produced for the same file

#### Scenario: Word document extraction unchanged

- **GIVEN** a DOCX file
- **WHEN** `extractFile` runs after this change is applied
- **THEN** the extracted text is identical to pre-change behaviour
