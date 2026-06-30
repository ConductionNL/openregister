## ADDED Requirements

### Requirement: `message/rfc822` inputs MUST NOT be redacted via the raw-text fallback

When `DocumentProcessingHandler::anonymizeDocument` (and the `replaceWords` dispatch) processes a file whose MIME type is `message/rfc822` (an `.eml`), it MUST route to the EML-aware anonymisation path and MUST NOT fall through to `replaceWordsInTextDocument()` (raw-byte `str_ireplace`). The EML path MUST operate on DECODED content obtained via `TextExtractionService::parseEmlStructured()`, never on the message's transfer-encoded transport bytes.

#### Scenario: EML with a base64-encoded body is redacted on decoded content

- **WHEN** an `.eml` whose `text/plain` body is `Content-Transfer-Encoding: base64` and contains the entity text `Jan de Vries` is anonymised
- **THEN** the redaction is applied to the DECODED body text
- **AND** the entity text `Jan de Vries` does NOT appear in the redacted body (it is replaced by its `[<TYPE>: <number>]` placeholder)
- **AND** the raw-text `str_ireplace` fallback is NOT used

#### Scenario: quoted-printable body part is redacted

- **WHEN** an `.eml` whose body part is `Content-Transfer-Encoding: quoted-printable` and contains an entity value is anonymised
- **THEN** the entity value does NOT survive in the redacted decoded body

### Requirement: Both body parts and the display headers MUST be redacted with the shared replacement map

The EML path MUST apply the SAME entity→`[<TYPE>: <number>]` replacement map the existing redactors use to: the `text/plain` body part, the `text/html` body part, and the display header values (From, To, Cc, Reply-To, Subject, Date, and any other display headers the result exposes). HTML body redaction MUST redact text content while preserving the markup structure (tags and attributes are not corrupted).

#### Scenario: Both plain and HTML bodies are redacted

- **GIVEN** an EML with both a `text/plain` and a `text/html` body part each containing the entity value `06-12345678`
- **WHEN** the EML is anonymised
- **THEN** the redacted `plain` body does NOT contain `06-12345678`
- **AND** the redacted `html` body does NOT contain `06-12345678`
- **AND** the redacted `html` body still contains its original tag structure (e.g. `<p>` elements survive)

#### Scenario: PII in display headers is redacted

- **GIVEN** an EML with `From: Jan de Vries <jan@example.org>`, `Reply-To: Jan de Vries <jan@example.org>`, and `Subject: Case for Jan de Vries`
- **WHEN** the EML is anonymised
- **THEN** the redacted `from` header value does NOT contain `Jan de Vries`
- **AND** the redacted `replyTo` header value does NOT contain `Jan de Vries`
- **AND** the redacted `subject` header value does NOT contain `Jan de Vries`

### Requirement: Each attachment MUST be redacted by the matching existing per-format redactor on its decoded bytes

For each `EmlAttachment` of a supported MIME type, the EML path MUST materialise the attachment's DECODED `content` bytes and run the EXISTING per-format redactor: docx / odt / rtf / html via the Word path, `application/pdf` via the PDF path, plain-text MIMEs via the text path. The redacted bytes MUST be returned in the result entry for that attachment.

#### Scenario: PDF attachment is redacted via the PDF path

- **GIVEN** an EML carrying a `application/pdf` attachment that contains an entity value
- **WHEN** the EML is anonymised
- **THEN** the result attachment entry carries redacted PDF bytes produced by the existing PDF redactor
- **AND** the entity value is not present in the redacted PDF bytes

#### Scenario: DOCX attachment is redacted via the Word path

- **GIVEN** an EML carrying a `.docx` attachment that contains an entity value
- **WHEN** the EML is anonymised
- **THEN** the result attachment entry carries redacted bytes produced by the existing Word redactor

#### Scenario: plain-text attachment is redacted via the text path

- **GIVEN** an EML carrying a `text/plain` attachment that contains an entity value
- **WHEN** the EML is anonymised
- **THEN** the result attachment entry carries redacted text bytes with the entity value replaced by its placeholder

### Requirement: Nested `message/rfc822` attachments MUST be redacted recursively within the depth-3 cap

A `message/rfc822` attachment MUST be redacted by recursing the EML anonymisation path on its bytes, using the same depth accounting as the parse layer (`EmlParser::MAX_DEPTH` = 3; root = depth 0). A nested EML that cannot be descended into because the depth cap is reached MUST be treated as an unsupported attachment (flagged, content dropped) and MUST NOT be emitted with raw bytes.

#### Scenario: Nested EML attachment is redacted

- **GIVEN** an EML (depth 0) carrying a forwarded `message/rfc822` attachment (depth 1) whose body contains an entity value
- **WHEN** the outer EML is anonymised
- **THEN** the nested EML's body is redacted and the entity value does not survive

#### Scenario: EML nested beyond the depth cap is flagged and dropped

- **GIVEN** an EML chain deeper than depth 3
- **WHEN** the EML is anonymised
- **THEN** the `message/rfc822` attachment at the depth boundary is marked `unsupported: true`
- **AND** its raw bytes are NOT included in the result

### Requirement: Unsupported attachment formats MUST be flagged and their content dropped

For any attachment whose MIME type has no matching redactor (e.g. xlsx, ods, archives, `text/calendar`, images, `application/octet-stream`), the EML path MUST NOT include the attachment's content in the result. The attachment MUST be represented with `filename`, `mimeType`, and a flag `unsupported: true`, and MUST carry no redacted content, so the consumer can render a placeholder noting the attachment was omitted because no anonymiser was available.

#### Scenario: xlsx attachment is flagged, content dropped

- **GIVEN** an EML carrying an `.xlsx` attachment
- **WHEN** the EML is anonymised
- **THEN** the result attachment entry has `unsupported: true`
- **AND** the result attachment entry carries NO content bytes (redacted or raw)

#### Scenario: image attachment is flagged, content dropped

- **GIVEN** an EML carrying a `image/png` attachment that is not an inline body image
- **WHEN** the EML is anonymised
- **THEN** the result attachment entry has `unsupported: true` and carries no content

### Requirement: The redacted result MUST be exposed as an `AnonymisedEmlStructure` cross-app contract

The EML path MUST return an immutable `AnonymisedEmlStructure` value object via a public service entry (e.g. `FileService::anonymizeEmlStructured()` delegating to the handler). The object MUST carry: the redacted display `headers` (subset), the redacted `body` (`plain` and/or `html`, each of which MAY be null), an `attachments` list where each entry is either a supported entry `{filename, mimeType, redactedContent}` or an unsupported entry `{filename, mimeType, unsupported: true}`, and an inline-image map (`contentId → redacted bytes`) for `cid:` resolution in the HTML body. Byte fields MUST be base64-encoded in `jsonSerialize()` for safe transport.

#### Scenario: Result exposes redacted body, attachments, and inline-image map

- **GIVEN** an EML with a redactable body, one supported attachment, one unsupported attachment, and one inline image referenced via `cid:`
- **WHEN** `anonymizeEmlStructured()` is called
- **THEN** the returned `AnonymisedEmlStructure` has a redacted `body`
- **AND** one `attachments` entry with `redactedContent` and one with `unsupported: true`
- **AND** the `inlineImages` map contains the inline image keyed by its `contentId`

#### Scenario: JSON serialisation base64-encodes byte fields

- **WHEN** an `AnonymisedEmlStructure` carrying redacted attachment bytes is JSON-serialised
- **THEN** the byte fields are emitted as base64 strings (safe to print)

### Requirement: Placeholder numbering MUST be consistent across the body, headers, and all attachments of a message

The entity→placeholder replacement map (and its scope-local `PlaceholderIdTranslator`) MUST be built once for the whole message and applied to the body, the display headers, and every attachment redaction (including nested EML). The same entity MUST receive the same `[<TYPE>: <number>]` placeholder wherever it appears in the message, and the existing `scope` (`document` / `dossier`) and `dossierKey` numbering semantics MUST carry through unchanged.

#### Scenario: Same person yields the same placeholder in body and attachment

- **GIVEN** an EML whose body and a PDF attachment both contain the same person entity
- **WHEN** the EML is anonymised
- **THEN** the placeholder emitted for that person in the redacted body equals the placeholder emitted for that person in the redacted attachment

### Requirement: OpenRegister MUST NOT render the EML to PDF

The EML anonymisation path's output MUST be the redacted structured result (and/or a redacted intermediate). OpenRegister MUST NOT produce a PDF from the EML; PDF assembly and the "EML always outputs PDF" rule are owned by the downstream DocuDesk consumer.

#### Scenario: Output is the structured result, not a PDF

- **WHEN** an EML is anonymised via the EML path
- **THEN** the OpenRegister output is an `AnonymisedEmlStructure`
- **AND** no PDF file is produced by OpenRegister for the EML

### Requirement: The EML anonymisation path MUST NOT log PII (ADR-005)

Header values, body content, and attachment filenames are PII. The EML anonymisation path MUST NOT emit any of those into log lines, error responses, or debug output. Permitted log content is restricted to structural detail: the Nextcloud file id, the MIME type, the attachment index, the redactor class invoked, and the recursion depth.

#### Scenario: Attachment-redaction log contains no PII

- **GIVEN** an EML with `Subject: Confidential — Jan de Vries` and an attachment `paspoort-de_vries.pdf`
- **WHEN** the EML is anonymised and the attachment redaction is logged
- **THEN** the log entry does NOT contain `Jan de Vries`, `Confidential`, or `paspoort-de_vries.pdf`
- **AND** the log entry contains at most the file id, MIME type, attachment index, redactor class, and depth