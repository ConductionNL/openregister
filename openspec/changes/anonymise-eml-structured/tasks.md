# Tasks — anonymise-eml-structured

> No seed-data task: this change adds no OpenRegister register/schema/object and no DB migration.

## 1. Result value objects (cross-app contract)

- [x] 1.1 Add `lib/Service/File/AnonymisedEmlBody.php` (`?string $plain`, `?string $html`; `JsonSerializable`) with EUPL-1.2 SPDX header.
- [x] 1.2 Add `lib/Service/File/AnonymisedEmlAttachment.php` (`filename`, `mimeType`, `?string $redactedContent`, `bool $unsupported`; base64-encode bytes in `jsonSerialize()`) with SPDX header.
- [x] 1.3 Add `lib/Service/File/AnonymisedEmlStructure.php` (`headers`, `AnonymisedEmlBody $body`, `AnonymisedEmlAttachment[] $attachments`, `array $inlineImages`; base64 byte fields in `jsonSerialize()`) matching `contract.md`.

## 2. EML dispatch + orchestration in DocumentProcessingHandler

- [x] 2.1 Detect `message/rfc822` in the `anonymizeDocument` / `replaceWords` dispatch and route to the new EML path (never to `replaceWordsInTextDocument`).
- [x] 2.2 Build the entity→placeholder replacement map ONCE for the message (reuse the existing builder + `PlaceholderIdTranslator`; honour `scope` / `dossierKey`), then parse via `TextExtractionService::parseEmlStructured()`.
- [x] 2.3 Redact the decoded body parts (`text/plain` and `text/html`, preserving HTML markup) and the display headers (From / To / Cc / Reply-To / Subject / Date / other display headers) with the shared map.
- [x] 2.4 Add the attachment loop: materialise each `EmlAttachment`'s decoded bytes and dispatch to the matching existing redactor (docx/odt/rtf/html → Word path, pdf → PDF path, plain-text → text path); collect redacted bytes; clean up temp resources in `finally`.
- [x] 2.5 Recurse the EML path for `message/rfc822` attachments using `EmlParser::MAX_DEPTH` accounting; treat a nested EML beyond the cap as unsupported (flag, drop content).
- [x] 2.6 Apply the unsupported-attachment policy for any MIME with no redactor (xlsx, ods, archives, calendar, images, octet-stream): set `unsupported: true`, emit no content.
- [x] 2.7 Build the inline-image map (`contentId → redacted bytes`) for `cid:` resolution; assemble and return the `AnonymisedEmlStructure`.
- [x] 2.8 Ensure all logging on this path is PII-free (file id, MIME, attachment index, redactor class, depth only) per ADR-005.

## 3. Public service entry

- [x] 3.1 Add `FileService::anonymizeEmlStructured(Node, entities, scope, dossierKey): AnonymisedEmlStructure` delegating to the handler, and route EML inputs of `anonymizeDocument()` into the EML path.

## 4. Tests (every new path — repo requires coverage)

- [x] 4.1 Body redaction: base64 and quoted-printable bodies redact on DECODED content (entity gone from output, raw-text fallback NOT used); both plain + html redacted; HTML markup preserved.
- [x] 4.2 Display-header redaction: From / Subject with PII are redacted.
- [x] 4.3 Attachment redaction routing: PDF via PDF path, DOCX via Word path, plain-text via text path each produce redacted bytes.
- [x] 4.4 Nested EML recursion redacts at depth 1; chain beyond depth 3 is flagged unsupported with no raw bytes.
- [x] 4.5 Unsupported attachment (xlsx, image) is flagged `unsupported: true` with no content; inline-image map populated for `cid:` images.
- [x] 4.6 Placeholder-numbering consistency: same entity yields the same `[<TYPE>: <number>]` in body and attachment; PII-free logging asserted.

## 5. Docs / changelog

- [x] 5.1 Add a CHANGELOG "Behavior changes" entry (EML inputs were silently leaking; now produce a redacted `AnonymisedEmlStructure`) and reference `contract.md` for the DocuDesk consumer.

## Acceptance criteria

- Anonymising a `message/rfc822` file never routes through `replaceWordsInTextDocument()`; redaction operates on decoded content.
- A base64/quoted-printable body and every supported attachment have their entity text removed in the output.
- Unsupported attachments (and nested EML beyond depth 3) are flagged and their content is dropped — never emitted unredacted.
- The result is an `AnonymisedEmlStructure` matching `contract.md`; OpenRegister produces no PDF.
- The same entity carries the same placeholder across body, headers, and attachments; `scope` / `dossierKey` semantics are unchanged.

## Quality checklist

- `openspec validate "anonymise-eml-structured"` passes.
- New PHP files carry the EUPL-1.2 SPDX `@license` + `@copyright` headers (Conduction convention).
- No PII in logs, error responses, or debug output on the EML path (ADR-005).
- No new HTTP endpoint, DB migration, register/schema/object, or external dependency introduced.
- Non-EML dispatch branches (Word / PDF / text) are byte-for-byte unchanged; no regression for opencatalogi / softwarecatalog.
- Every new code path is covered by a unit test.