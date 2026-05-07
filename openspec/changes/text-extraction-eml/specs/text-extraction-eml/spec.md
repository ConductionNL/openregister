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
- **THEN** `result.body['plainText']` contains the plain part
- **AND** `result.body['html']` contains the HTML part
- **AND** both are populated (consumer chooses)

#### Scenario: Single-body EMLs populate only the present part

- **GIVEN** an EML with only a `text/html` part
- **WHEN** `parseEmlStructured` runs
- **THEN** `result.body['plainText']` is null
- **AND** `result.body['html']` contains the HTML

#### Scenario: Date header malformed — date field is null

- **GIVEN** an EML whose Date header is unparseable
- **WHEN** `parseEmlStructured` runs
- **THEN** `result.headers['date']` is null
- **AND** the rest of the structure is populated normally
- **AND** no exception is thrown

### Requirement: Each `EmlAttachment` MUST carry filename, MIME type, raw bytes, and inline / contentId metadata

Each entry in `EmlStructure.attachments[]` MUST be an `EmlAttachment` with:

- `filename` (string) — from the Content-Disposition header or generated if missing.
- `mimeType` (string) — from Content-Type.
- `content` (string of raw bytes — base64-decoded as needed).
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

### Requirement: Recursive nesting of `message/rfc822` MUST be capped at depth 3

The structured parse MUST follow `message/rfc822` attachments recursively, but only up to depth 3. Beyond that, the attachment's `nestedEml` MUST be null even though the MIME indicates an EML. The flat path MUST follow the same limit — at depth 3+, EML attachments are listed by name only without inline extraction.

#### Scenario: Depth-3 EML chain is fully recursed

- **GIVEN** EML A containing EML B containing EML C containing EML D
- **WHEN** `parseEmlStructured(A)` runs
- **THEN** A → B → C are recursed (`nestedEml` populated through C)
- **AND** the C-level EmlStructure for D's attachment has `nestedEml: null`

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

### Requirement: Malformed input MUST NOT throw from `extractEml`; structured-parse MAY throw a typed exception

`extractEml` MUST follow the existing extraction-failure pattern — return null on irrecoverable parse error, log the error. `parseEmlStructured` MAY throw a typed `EmlParseException` for malformed input; consumers handle the exception as they see fit. Both paths MUST handle minor malformations (missing optional headers, unusual encoding, extra whitespace) gracefully without raising errors.

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
