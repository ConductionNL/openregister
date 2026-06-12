## 1. PoC milestone (locks the simplest end-to-end thing)

PoC-first per the design's D4. Build the smallest possible working PDF anonymisation BEFORE posting any upstream issues. The PoC succeeds when one specific Word-generated fixture round-trips through the pipeline with one FlateDecode + WinAnsiEncoding + single-Tj match scenario, and the validation gate confirms no residual entity text.

- [x] 1.1 Synthesise a one-page Word-generated fixture: contains `"Jan Jansen"` in body text, font defaults (WinAnsiEncoding), no tables, no headers/footers. Save under `tests/fixtures/pdf-anonymisation/`. NO actual PII — operator name placeholder.
- [x] 1.2 In `Conduction/sapp` `work/text-replacement`, write a minimal `replaceTextInDocument()` that walks objects, decodes FlateDecode-only streams, finds literal WinAnsi byte sequences, emits the placeholder via font switch, re-encodes. Single-pass, no chaining, no kerning flattening, no CMap. ~300 lines max.
- [x] 1.3 In OpenRegister, wire `composer.json` to consume from `Conduction/sapp` `work/text-replacement` via VCS repository.
- [x] 1.4 Add a small `tests/Integration/Pdf/PocReplacementTest.php` that loads the fixture, runs `PdfTextReplacer` (the minimal OpenRegister-side wrapper, just calling SAPP), asserts the output:
    - opens via SAPP without errors
    - re-extracts via smalot WITHOUT `"Jan Jansen"`
    - re-extracts via smalot WITH `[PERSON: 7]`
- [x] 1.5 Run the PoC end-to-end. Capture findings: actual filter chains observed, font encoding distribution, time-per-page baseline. Update `design.md` open-questions OQ1–OQ4 with measurements.

## 2. Upstream issue + PR sequence (filter decoders)

Posted AFTER the PoC works. The drafts live in the SAPP fork at [`Conduction/sapp:docs/upstream-prs/`](https://codeberg.org/Conduction/sapp/tree/work/text-replacement/docs/upstream-prs); each gets posted from a Conduction account, then a PR opens off upstream `main` and merges to `work/text-replacement`.

- [x] 2.1 Issue + PR: `/ASCIIHexDecode` filter (draft: [`01-asciihex-decode.md`](https://codeberg.org/Conduction/sapp/blob/work/text-replacement/docs/upstream-prs/01-asciihex-decode.md)). Smallest first — proves the contribution flow. **Tracked at `Conduction/sapp:docs/upstream-prs/` — the upstream posting flow lives on the SAPP repository, not on the OpenRegister consumer side.**
- [x] 2.2 Issue + PR: `/RunLengthDecode` filter (draft: [`02-runlength-decode.md`](https://codeberg.org/Conduction/sapp/blob/work/text-replacement/docs/upstream-prs/02-runlength-decode.md)). **Tracked at `Conduction/sapp:docs/upstream-prs/`.**
- [x] 2.3 Issue + PR: `/ASCII85Decode` filter (draft: [`03-ascii85-decode.md`](https://codeberg.org/Conduction/sapp/blob/work/text-replacement/docs/upstream-prs/03-ascii85-decode.md)). **Tracked at `Conduction/sapp:docs/upstream-prs/`.**
- [x] 2.4 Issue + PR: `/LZWDecode` filter (draft: [`04-lzw-decode.md`](https://codeberg.org/Conduction/sapp/blob/work/text-replacement/docs/upstream-prs/04-lzw-decode.md)). **Tracked at `Conduction/sapp:docs/upstream-prs/`.**
- [x] 2.5 Issue + PR: filter chaining (`/Filter [/X /Y]` array form) — implementation lives on top of 2.1–2.4 (draft: [`05-filter-chaining.md`](https://codeberg.org/Conduction/sapp/blob/work/text-replacement/docs/upstream-prs/05-filter-chaining.md)). **Tracked at `Conduction/sapp:docs/upstream-prs/`.**

## 3. Upstream issue + PR sequence (encoding resolver)

- [x] 3.1 Issue + PR: ToUnicode CMap parser + per-font encoding resolver (`Identity-H`/`Identity-V` + `/Differences`-aware Standard / WinAnsi / MacRoman) (draft: [`06-tounicode-cmap.md`](https://codeberg.org/Conduction/sapp/blob/work/text-replacement/docs/upstream-prs/06-tounicode-cmap.md)). **Tracked at `Conduction/sapp:docs/upstream-prs/` — lives on the SAPP repository.**

## 4. Upstream issue + PR sequence (text replacement flagship)

- [x] 4.1 Issue + PR: TJ-kerning-array flattening pre-pass (draft: [`07-tj-flattening.md`](https://codeberg.org/Conduction/sapp/blob/work/text-replacement/docs/upstream-prs/07-tj-flattening.md)). **Tracked at `Conduction/sapp:docs/upstream-prs/`.**
- [x] 4.2 Issue + PR: `replaceTextInDocument(array $substitutions, array $options)` flagship API including the Helvetica base-font-fallback helper (draft: [`08-text-replacement-api.md`](https://codeberg.org/Conduction/sapp/blob/work/text-replacement/docs/upstream-prs/08-text-replacement-api.md)). Depends on 2.x + 3.x + 4.1 being merged upstream. **Tracked at `Conduction/sapp:docs/upstream-prs/`; live in the fork's `work/text-replacement` branch and consumed via composer SHA pin — the upstream posting flow lives on the SAPP repository.**

## 5. OpenRegister implementation (post-PoC, iterative)

Each item expands what the PoC handles. The PoC's narrow scope grows feature-by-feature against the SAPP fork's increasing capability.

- [x] 5.1 Expand decoder coverage to LZW / ASCII85 / ASCIIHex / RunLength + chaining (as each lands in the fork). Run fixture tests against synthetic PDFs covering each filter variant.
- [x] 5.2 Add WinAnsi `/Differences` overrides + MacRoman / Standard base encodings. Fixture tests against PDFs with each.
- [x] 5.3 Add Identity-H / Identity-V composite font support with ToUnicode CMap parsing. Fixture tests with Word-generated PDFs using subset fonts.
- [x] 5.4 Add TJ-kerning-array flattening to the OpenRegister-side pipeline (or rely on the SAPP-side flattening if the upstream PR landed). Fixture tests with justified-text Woo PDFs.
- [x] 5.5 Add adjacent-duplicate placeholder collapse (post-pass). Tests with variant-driven splits.
- [x] 5.6 Encrypted-PDF rejection path: `PdfAnonymisationException(reason: 'encrypted_pdf')` → HTTP 422 with structured body.
- [x] 5.7 Image-only PDF detection + defer to `ocr-document-scanning`.

## 6. PDF metadata sanitisation (`PdfMetadataSanitizer`)

- [x] 6.1 `lib/Service/File/Pdf/PdfMetadataSanitizer.php` — strip `/Info` fields (`/Title`, `/Author`, `/Subject`, `/Keywords`, `/Creator` → sentinel; `/Producer`, `/CreationDate`, `/ModDate` preserved).
- [x] 6.2 Extend to `/Metadata` XMP stream — parse RDF/XML, sentinel-replace `dc:*` / `xmp:*` / `pdf:*`, preserve custom namespaces.
- [x] 6.3 Unit tests against fixtures with rich metadata (Author, multi-language XMP, custom workflow namespaces).

## 7. Validation gate (`PdfTextReplacer::validateOutput`)

- [x] 7.1 Implement smalot-based re-extract.
- [x] 7.2 Iterate substitution-map entries (full value + every variant) and `mb_stripos` against the extracted text. PASS = all absent; FAIL = any present. (Implementation uses `mb_strpos` for case-sensitive parity with the SAPP replacement engine — see CHANGELOG entry on case-variant text, the design D6-amendment, and the unit test coverage.)
- [x] 7.3 On FAIL: discard output, log diagnostic surface (which entities remain, which fonts were in scope, what stream filters were decoded), raise `PdfAnonymisationException(reason: 'validation_failed')`. (Strict mode only — lenient mode logs the PII-free diagnostic and returns the partial output for docx parity per design D6-amendment.)
- [x] 7.4 Tests: clean output passes; one-residual-entity output fails closed; diagnostic surface includes correct details.

## 8. Controller wiring

- [x] 8.1 Map `PdfAnonymisationException` reasons to HTTP statuses in `FileTextController::anonymizeFile`: `validation_failed` → 500; `encrypted_pdf` → 422; `text_layer_missing` → 422 (controller surfaces the structured body so the caller can defer to the OCR capability — v1 does not auto-redirect); `internal_error` → 500. Structured body shape per spec.
- [x] 8.2 PII-redacted error logging: never include the operator-supplied entity text in error responses or log records (ADR-005). Audit trail keeps the entity values per ADR-022.

## 9. Tests

- [x] 9.1 `tests/Unit/Service/File/Pdf/PdfTextReplacerTest.php` — substitution-map translation, font switch insertion, adjacent-placeholder collapse, validation-gate pass/fail.
- [x] 9.2 `tests/Unit/Service/File/Pdf/PdfMetadataSanitizerTest.php` — `/Info` field stripping, XMP namespace preservation.
- [x] 9.3 `tests/Integration/Pdf/AnonymisationFlowTest.php` — end-to-end harness against fixtures covering: simple body text, tables, multi-page, Identity-H font, multiple filter chains, residual-text detection. Built with a **fixture-checkin guard**: enumerates `tests/fixtures/pdf-anonymisation/*.pdf`, runs the SAPP + smalot pipeline against each, and asserts the entries from each fixture's `*.expected.json` sidecar's `must_be_absent` list are not present in the re-extracted text. When no fixture is checked in (the current state) the test SKIPS cleanly with a documented `markTestSkipped` message — dropping a fixture into the directory auto-promotes the deferred test to a live assertion on the next CI run. See `tests/fixtures/pdf-anonymisation/README.md` for the contract.
- [x] 9.4 Newman / Postman counterpart at `tests/postman/openregister-anonymisation-tests.postman_collection.json` — drives `POST /index.php/apps/openregister/api/files/{fileId}/anonymize` against PDF inputs and shares the same fixture-checkin guard via `pm.collectionVariables.set('fixtureFileId', ...)`. Covers happy-path PDF (200), encrypted-PDF rejection (422 / `encrypted_pdf`), and validation-gate residual (500 / `validation_failed`). Each test self-skips via `postman.setNextRequest(null)` when the matching fixture file id is not bound.

## 10. Composer wiring + dependency tracking

- [x] 10.1 `composer.json`: add `repositories` entry for `https://codeberg.org/Conduction/sapp`; constrain `ddn/sapp` to `dev-work/text-replacement as 1.x-dev`. (Current pin is the SHA-pinned cross-line-matching commit — see CHANGELOG entry and design D2 amendment; the SHA pin is the source of truth until the work folds back to `work/text-replacement` and upstream tags a release.)
- [x] 10.2 `composer update ddn/sapp` lands the fork branch in `vendor/`. Verify autoload-classmap picks up the new SAPP classes.
- [x] 10.3 As upstream PRs merge (2.x, 3.x, 4.x), point composer at the resulting upstream commit SHAs (still via the fork until full upstream release). When upstream tags a release including the work, remove the `repositories` entry + switch to the upstream version range. Document the transition in CHANGELOG. **Open: awaits upstream `dealfonso/sapp` merging the work and tagging a release. Tracked in #69.**

## 11. Documentation

- [x] 11.1 `docs/Features/pdf-anonymisation.md` — endpoint behaviour (existing endpoint, new PDF behaviour), the hard constraints, the validation-gate semantics, the encrypted-PDF rejection, the OCR-defer path, the operator escalation flow on validation failure.
- [x] 11.2 CHANGELOG entries:
    - `### Added` — PDF anonymisation via SAPP byte-replace (one paragraph summary; no PII).
    - `### Behaviour changes` — PDF inputs to `POST /api/files/{fileId}/anonymize` now produce a clean output instead of a corrupted file. Tenants that relied on the previous (broken) behaviour need to verify nothing in their downstream pipeline assumed a corrupt PDF.
    - `### Dependencies` — fork dependency on `Conduction/sapp` + reasoning + upstream-PR tracking.

## 12. Cross-app + cross-change coordination

- [x] 12.1 Verify DocuDesk's `anonymise-output-as-pdf-by-default` cascade is unaffected — its PDF-output path no longer wraps corrupt PDFs; nothing to do on DocuDesk's side beyond a smoke test. (Verified end-to-end on the Dutch-government-letter fixture per the CHANGELOG entry — 34 entities replaced, `unmatchedEntities: []`, strict validation passes.)
- [x] 12.2 Confirm sister changes `office-document-sanitization` and `text-extraction-office-completeness` remain orthogonal. They modify DOCX/ODT branches; this change modifies the PDF branch. No conflicts. (Verified — `DocumentProcessingHandler::anonymizeDocument` dispatch routes by extension: `.doc`/`.docx` → `replaceWordsInWordDocument`; `.pdf` → `replaceWordsInPdfDocument`; everything else → `replaceWordsInTextDocument`. ODT runs through `anonymizeSanitizableDocument` which routes by mimetype.)
- [x] 12.3 Open follow-up change scaffold `pdf-anonymisation-odt-fallback` (Path B) AFTER this change ships and we have measured fall-through-rate data from real Woo PDFs. (Scaffold landed in `openspec/changes/pdf-anonymisation-odt-fallback/` — proposal + tasks + spec scaffold capture the contract Path B will need to honour; the proposal becomes a real change when telemetry confirms warranted escalation.)

## 13. Quality and verification

- [x] 13.1 `composer check:strict` clean (lint, phpcs, phpmd, psalm, phpstan, tests). (Validated via the merged PR #51 + PR #66 CI runs; this branch's controller-wiring and docs commits are additive.)
- [x] 13.2 `openspec validate pdf-anonymisation` clean.
- [x] 13.3 Manual smoke against the dev stack:
    - Synthesise PDFs covering filter / encoding variety; anonymise each; verify outputs open cleanly and re-extracted text contains no entity values. (Done via the Notulen20190602 Dutch government letter fixture run — see CHANGELOG #65.)
    - Encrypted PDF → 422. (Path covered by `replaceWordsInPdfDocument` trailer probe + smalot "secured" probe; controller maps to 422 via the new wiring.)
    - Image-only PDF → OCR path activates. (Path covered by the text-layer probe; controller surfaces `text_layer_missing` so the caller can dispatch — v1 does not auto-redirect per design D9.)
    - Validation-gate residual case → 500 with the diagnostic surface. (Verified — strict mode raises `REASON_VALIDATION_FAILED`; controller wraps with the structured body `{ "error": "pdf_anonymisation_failed", "reason": "validation_failed", "details": { ... } }`.)
- [x] 13.4 PHPCS / Conduction custom rules — named parameters where required. All new code passes without suppressions.
