## 1. Composer dependency

- [ ] 1.1 Add `zbateson/mail-mime-parser` (latest stable, expected `^3.x`) to OpenRegister's `composer.json` under `require`. Run `composer update zbateson/mail-mime-parser` and commit `composer.lock`.
- [ ] 1.2 Run `composer install` in CI / build pipeline; confirm no conflicts with existing PhpWord, smalot/pdfparser, or other deps.

## 2. Value-object classes

- [ ] 2.1 Create `lib/Service/TextExtraction/EmlBody.php` — immutable value object with `plainText: ?string` and `html: ?string`. Constructor + readonly properties.
- [ ] 2.2 Create `lib/Service/TextExtraction/EmlAttachment.php` — immutable value object with `filename`, `mimeType`, `content` (raw bytes string), `isInline`, `contentId`, `nestedEml: ?EmlStructure`. Constructor + readonly properties.
- [ ] 2.3 Create `lib/Service/TextExtraction/EmlStructure.php` — immutable value object with `headers: array`, `body: EmlBody`, `attachments: EmlAttachment[]`. Constructor + readonly properties + JsonSerializable for any future REST surface.
- [ ] 2.4 Create `lib/Exception/EmlParseException.php` extending the existing OpenRegister exception base (or `\Exception` if none). Carries an optional cause string identifying the parse-failure point.

## 3. EmlParser service

- [ ] 3.1 Create `lib/Service/TextExtraction/EmlParser.php`. Constructor takes the logger and (lazily) `TextExtractionService` (avoid circular DI — inject via the container with a method).
- [ ] 3.2 Implement `parse(File $file): EmlStructure` using `\ZBateson\MailMimeParser\MailMimeParser`. Loop through headers — extract From, To, Cc, Subject, Date, Message-ID. Decode RFC 2047 encoded-words via the parser's built-in handling. Date parsing: try `DateTimeImmutable::createFromFormat` with RFC 2822 / 5322 patterns; fall back to null on failure.
- [ ] 3.3 Implement body extraction: get `text/plain` and `text/html` parts independently; populate `EmlBody` with both (or null per part).
- [ ] 3.4 Implement attachment extraction: walk `getAllAttachmentParts()`. For each: filename (resolution order: Content-Disposition `filename` → Content-Type `name` → generated `attachment-<1-indexed-position>`; the generated form MUST always be non-empty), mimeType, content (MUST be the **decoded** bytes via `$part->getContent()` — NOT `getRawContent()` — so consumers can embed them as PDF/A-3 file attachments or `data:` URIs without further decoding), isInline (Content-Disposition: inline), contentId (Content-ID header, stripping angle brackets).
- [ ] 3.5 Implement recursive `nestedEml` for `message/rfc822` attachments. Pass a depth parameter (default 0). Recursive call increments depth. At depth 3, set `nestedEml: null` and emit a debug log "EML nesting depth limit reached".
- [ ] 3.6 Implement `flatten(EmlStructure $structure, int $depth = 0): string` — builds the flat plain-text per spec D2: header block, blank line, body (plainText preferred; HTML stripped to text fallback), attachments. For extractable attachments (PDF / Word / text / nested EML), call `TextExtractionService` to get attachment text and inline. For non-extractable, marker line only.
- [ ] 3.7 Implement HTML→text helper for the flat-path body fallback. Drop `<style>`, `<script>` content (block-level removal). Convert `<br>`, `<p>`, block-level tags to newlines. Strip remaining tags via `strip_tags()`. Decode entities via `html_entity_decode()`. Collapse whitespace runs.
- [ ] 3.8 Implement encoding fallback: if `mb_check_encoding($value, 'UTF-8')` is false, run `mb_detect_encoding` then `mb_convert_encoding` to UTF-8.
- [ ] 3.9 Wrap parse failures: `parse()` MUST throw `EmlParseException` on irrecoverable error — NOT return null, NOT return a partially-populated `EmlStructure`. `flatten()` does not throw (works on a successfully-parsed structure).
- [ ] 3.10 Sanitise log lines and exception messages per ADR-005: when logging a parser failure or rethrowing an exception that will be logged, replace email addresses, display names, Subject content, body content, and attachment filenames with `<redacted>`. Permitted log payload: file ID, MIME type, exception class name, structural detail (depth-limit hit, etc.). Apply this in both `extractEml`'s catch block and `EmlParser::parse`'s sanitised re-throw.
- [ ] 3.11 Implement the flat-path multipart/alternative preference explicitly: when a body has both a `text/plain` and a `text/html` part in a `multipart/alternative`, `flatten()` MUST use the `text/plain` content and MUST NOT concatenate the HTML.

## 4. TextExtractionService integration

- [ ] 4.1 In `lib/Service/TextExtractionService.php`, locate `extractSourceText` (or the cascade method). Add a new `else if ($mimeType === 'message/rfc822')` branch. Inside, call private `extractEml($file)`.
- [ ] 4.2 Implement `private function extractEml(File $file): ?string`. Try `EmlParser::parse($file)` then `EmlParser::flatten()` and return the result. On `EmlParseException`, log the error and return null (matching the existing failure pattern).
- [ ] 4.3 Add public method `parseEmlStructured(File $file): EmlStructure`. Direct delegate to `EmlParser::parse()`. Throws `EmlParseException` on irrecoverable error (caller handles).
- [ ] 4.4 Confirm DI wiring: `EmlParser` resolves correctly with its dependencies; `TextExtractionService` can call its private `extractEml` and the public `parseEmlStructured`.

## 5. Unit tests

- [ ] 5.1 `tests/unit/Service/TextExtraction/EmlParserTest.php` — fixture EMLs in `tests/Fixtures/eml/`. Cover: simple text-only EML; multipart text+html EML; encoded-word headers; missing/malformed Date; non-ASCII bodies; PDF attachment; image attachment (non-extractable); nested EML at depth 1, 2, 3, 4 (depth limit); inline image with Content-ID; missing required headers (graceful tolerance); broken-binary input throwing EmlParseException.
- [ ] 5.2 `tests/unit/Service/TextExtractionServiceTest.php` — extension covering the new MIME branch: EML input produces populated text via `extractFile`; the public `parseEmlStructured` returns an EmlStructure; non-EML files (PDF, DOCX) extraction is unchanged after this change.
- [ ] 5.3 Test `flatten()` output structure: header block before body; `--- Attachment: ... ---` markers; non-extractable attachment marker shape; recursive attachment text inlined.
- [ ] 5.4 Test HTML→text helper: handles `<style>`, `<script>`, common block elements, entity decoding, whitespace collapse.
- [ ] 5.5 Test encoding fallback: non-UTF-8 input (e.g. ISO-8859-1 body) is converted to UTF-8 in the flat output.
- [ ] 5.6 Test multipart/alternative preference: an EML with both `text/plain` and `text/html` parts emits only the `text/plain` content from `flatten()`; the HTML is NOT concatenated.
- [ ] 5.7 Test missing-filename fallback: an attachment with neither Content-Disposition `filename` nor Content-Type `name` MUST get the generated `attachment-<n>` (1-indexed position) and MUST NOT be empty.
- [ ] 5.8 Test `content` encoding: the attachment's `content` field MUST hold the decoded binary bytes, not the base64 transport string. Assert by re-encoding and comparing against the raw attachment bytes of the source EML.
- [ ] 5.9 Test PII-redacted logging: induce a parser failure on an EML with sensitive From / Subject / body content; capture the log line and assert that none of the PII appears and only the file ID, MIME type, exception class, and sanitised structural detail are present.
- [ ] 5.10 Test that `parseEmlStructured` throws (never returns null or a partial structure) on irrecoverable malformed input. Assert exception class is `EmlParseException`.

## 6. Integration tests

- [ ] 6.1 Newman / Postman or PHPUnit functional: upload a real-world-ish EML; trigger `extractFile`; verify the persisted extracted-text is populated and contains expected content.
- [ ] 6.2 Functional: upload an EML with a PDF attachment; verify the extracted text contains both the email body AND the PDF's text under the attachment marker.
- [ ] 6.3 Functional: call `parseEmlStructured` from a test consumer (e.g. a temporary command); verify the returned structure matches the fixture's headers, body, and attachments.

## 7. Cross-app coordination

- [ ] 7.1 Notify DocuDesk team: structured-parse method available; the paired `eml-pdf-assembly` change can now be implemented.
- [ ] 7.2 No DocuDesk-side change is part of this OR change. The DocuDesk EmlBackend in `pdf-conversion` (Change A) does NOT need updating until the paired `eml-pdf-assembly` change ships — Change A's EmlBackend currently reports `isAvailable: false` until OR's EML support exists. After this change ships, that backend's `isAvailable` returns true; DocuDesk's `eml-pdf-assembly` then upgrades the EmlBackend's `convert()` to use the structured parse.

## 8. Documentation

- [ ] 8.1 Add a section to `docs/` (extend an existing extraction-related doc or create a new one) describing the EML extraction shape — flat output structure, structured-parse API, depth limit, library used.
- [ ] 8.2 CHANGELOG entry under "Added": EML support in `TextExtractionService` (flat plain-text via `extractFile`; structured parse via `parseEmlStructured`); new `zbateson/mail-mime-parser` dependency.
- [ ] 8.3 CHANGELOG entry under "Behavior changes": EML inputs that previously produced null / empty extracted text now produce populated content. Tenants that relied on EMLs being skipped (unlikely) need to revisit their pipelines.

## 9. Quality and verification

- [ ] 9.1 Run the full unit + functional test suite — clean.
- [ ] 9.2 Run static analysis (Psalm / PHPStan at project strictness) — clean.
- [ ] 9.3 Run code style (PHPCS at project config) — clean.
- [ ] 9.4 Manual smoke against a live stack: place a sample EML with attachments via NC Files; trigger extraction; verify the extracted-text in OR's metadata; call `parseEmlStructured` from a test invocation and verify the structure.
- [ ] 9.5 Run `openspec validate text-extraction-eml` — clean.
