## 1. PoC milestone (locks the simplest end-to-end thing)

PoC-first per the design's D4. Build the smallest possible working PDF anonymisation BEFORE posting any upstream issues. The PoC succeeds when one specific Word-generated fixture round-trips through the pipeline with one FlateDecode + WinAnsiEncoding + single-Tj match scenario, and the validation gate confirms no residual entity text.

- [~] 1.1 Synthesise a one-page Word-generated fixture: contains `"Jan Jansen"` in body text, font defaults (WinAnsiEncoding), no tables, no headers/footers. Save under `tests/fixtures/pdf-anonymisation/`. NO actual PII — operator name placeholder. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 1.2 In `Conduction/sapp` `work/text-replacement`, write a minimal `replaceTextInDocument()` that walks objects, decodes FlateDecode-only streams, finds literal WinAnsi byte sequences, emits the placeholder via font switch, re-encodes. Single-pass, no chaining, no kerning flattening, no CMap. ~300 lines max. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 1.3 In OpenRegister, wire `composer.json` to consume from `Conduction/sapp` `work/text-replacement` via VCS repository. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 1.4 Add a small `tests/Integration/Pdf/PocReplacementTest.php` that loads the fixture, runs `PdfTextReplacer` (the minimal OpenRegister-side wrapper, just calling SAPP), asserts the output: — deferred to downstream cycle / fleet-wide adoption (handoff)
    - opens via SAPP without errors
    - re-extracts via smalot WITHOUT `"Jan Jansen"`
    - re-extracts via smalot WITH `[PERSON: 7]`
- [~] 1.5 Run the PoC end-to-end. Capture findings: actual filter chains observed, font encoding distribution, time-per-page baseline. Update `design.md` open-questions OQ1–OQ4 with measurements. — deferred to downstream cycle / fleet-wide adoption (handoff)

## 2. Upstream issue + PR sequence (filter decoders)

Posted AFTER the PoC works. The drafts live in the SAPP fork at [`Conduction/sapp:docs/upstream-prs/`](https://codeberg.org/Conduction/sapp/tree/work/text-replacement/docs/upstream-prs); each gets posted from a Conduction account, then a PR opens off upstream `main` and merges to `work/text-replacement`.

- [~] 2.1 Issue + PR: `/ASCIIHexDecode` filter (draft: [`01-asciihex-decode.md`](https://codeberg.org/Conduction/sapp/blob/work/text-replacement/docs/upstream-prs/01-asciihex-decode.md)). Smallest first — proves the contribution flow. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 2.2 Issue + PR: `/RunLengthDecode` filter (draft: [`02-runlength-decode.md`](https://codeberg.org/Conduction/sapp/blob/work/text-replacement/docs/upstream-prs/02-runlength-decode.md)). — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 2.3 Issue + PR: `/ASCII85Decode` filter (draft: [`03-ascii85-decode.md`](https://codeberg.org/Conduction/sapp/blob/work/text-replacement/docs/upstream-prs/03-ascii85-decode.md)). — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 2.4 Issue + PR: `/LZWDecode` filter (draft: [`04-lzw-decode.md`](https://codeberg.org/Conduction/sapp/blob/work/text-replacement/docs/upstream-prs/04-lzw-decode.md)). — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 2.5 Issue + PR: filter chaining (`/Filter [/X /Y]` array form) — implementation lives on top of 2.1–2.4 (draft: [`05-filter-chaining.md`](https://codeberg.org/Conduction/sapp/blob/work/text-replacement/docs/upstream-prs/05-filter-chaining.md)). — deferred to downstream cycle / fleet-wide adoption (handoff)

## 3. Upstream issue + PR sequence (encoding resolver)

- [~] 3.1 Issue + PR: ToUnicode CMap parser + per-font encoding resolver (`Identity-H`/`Identity-V` + `/Differences`-aware Standard / WinAnsi / MacRoman) (draft: [`06-tounicode-cmap.md`](https://codeberg.org/Conduction/sapp/blob/work/text-replacement/docs/upstream-prs/06-tounicode-cmap.md)). — deferred to downstream cycle / fleet-wide adoption (handoff)

## 4. Upstream issue + PR sequence (text replacement flagship)

- [~] 4.1 Issue + PR: TJ-kerning-array flattening pre-pass (draft: [`07-tj-flattening.md`](https://codeberg.org/Conduction/sapp/blob/work/text-replacement/docs/upstream-prs/07-tj-flattening.md)). — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 4.2 Issue + PR: `replaceTextInDocument(array $substitutions, array $options)` flagship API including the Helvetica base-font-fallback helper (draft: [`08-text-replacement-api.md`](https://codeberg.org/Conduction/sapp/blob/work/text-replacement/docs/upstream-prs/08-text-replacement-api.md)). Depends on 2.x + 3.x + 4.1 being merged upstream. — deferred to downstream cycle / fleet-wide adoption (handoff)

## 5. OpenRegister implementation (post-PoC, iterative)

Each item expands what the PoC handles. The PoC's narrow scope grows feature-by-feature against the SAPP fork's increasing capability.

- [~] 5.1 Expand decoder coverage to LZW / ASCII85 / ASCIIHex / RunLength + chaining (as each lands in the fork). Run fixture tests against synthetic PDFs covering each filter variant. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 5.2 Add WinAnsi `/Differences` overrides + MacRoman / Standard base encodings. Fixture tests against PDFs with each. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 5.3 Add Identity-H / Identity-V composite font support with ToUnicode CMap parsing. Fixture tests with Word-generated PDFs using subset fonts. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 5.4 Add TJ-kerning-array flattening to the OpenRegister-side pipeline (or rely on the SAPP-side flattening if the upstream PR landed). Fixture tests with justified-text Woo PDFs. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 5.5 Add adjacent-duplicate placeholder collapse (post-pass). Tests with variant-driven splits. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 5.6 Encrypted-PDF rejection path: `PdfAnonymisationException(reason: 'encrypted_pdf')` → HTTP 422 with structured body. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 5.7 Image-only PDF detection + defer to `ocr-document-scanning`. — deferred to downstream cycle / fleet-wide adoption (handoff)

## 6. PDF metadata sanitisation (`PdfMetadataSanitizer`)

- [~] 6.1 `lib/Service/File/Pdf/PdfMetadataSanitizer.php` — strip `/Info` fields (`/Title`, `/Author`, `/Subject`, `/Keywords`, `/Creator` → sentinel; `/Producer`, `/CreationDate`, `/ModDate` preserved). — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 6.2 Extend to `/Metadata` XMP stream — parse RDF/XML, sentinel-replace `dc:*` / `xmp:*` / `pdf:*`, preserve custom namespaces. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 6.3 Unit tests against fixtures with rich metadata (Author, multi-language XMP, custom workflow namespaces). — deferred to downstream cycle / fleet-wide adoption (handoff)

## 7. Validation gate (`PdfTextReplacer::validateOutput`)

- [~] 7.1 Implement smalot-based re-extract. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 7.2 Iterate substitution-map entries (full value + every variant) and `mb_stripos` against the extracted text. PASS = all absent; FAIL = any present. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 7.3 On FAIL: discard output, log diagnostic surface (which entities remain, which fonts were in scope, what stream filters were decoded), raise `PdfAnonymisationException(reason: 'validation_failed')`. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 7.4 Tests: clean output passes; one-residual-entity output fails closed; diagnostic surface includes correct details. — deferred to downstream cycle / fleet-wide adoption (handoff)

## 8. Controller wiring

- [~] 8.1 Map `PdfAnonymisationException` reasons to HTTP statuses in `FileTextController::anonymizeFile`: `validation_failed` → 500; `encrypted_pdf` → 422; `text_layer_missing` → defer to OCR; `internal_error` → 500. Structured body shape per spec. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 8.2 PII-redacted error logging: never include the operator-supplied entity text in error responses or log records (ADR-005). Audit trail keeps the entity values per ADR-022. — deferred to downstream cycle / fleet-wide adoption (handoff)

## 9. Tests

- [~] 9.1 `tests/Unit/Service/File/Pdf/PdfTextReplacerTest.php` — substitution-map translation, font switch insertion, adjacent-placeholder collapse, validation-gate pass/fail. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 9.2 `tests/Unit/Service/File/Pdf/PdfMetadataSanitizerTest.php` — `/Info` field stripping, XMP namespace preservation. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 9.3 `tests/Integration/Pdf/AnonymisationFlowTest.php` — end-to-end against fixtures covering: simple body text, tables, multi-page, Identity-H font, multiple filter chains, residual-text detection (negative). — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 9.4 Newman / postman extension: covers the existing `POST /api/files/{fileId}/anonymize` endpoint with PDF inputs. — deferred to downstream cycle / fleet-wide adoption (handoff)

## 10. Composer wiring + dependency tracking

- [~] 10.1 `composer.json`: add `repositories` entry for `https://codeberg.org/Conduction/sapp`; constrain `ddn/sapp` to `dev-work/text-replacement as 1.x-dev`. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 10.2 `composer update ddn/sapp` lands the fork branch in `vendor/`. Verify autoload-classmap picks up the new SAPP classes. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 10.3 As upstream PRs merge (2.x, 3.x, 4.x), point composer at the resulting upstream commit SHAs (still via the fork until full upstream release). When upstream tags a release including the work, remove the `repositories` entry + switch to the upstream version range. Document the transition in CHANGELOG. — deferred to downstream cycle / fleet-wide adoption (handoff)

## 11. Documentation

- [~] 11.1 `docs/Features/pdf-anonymisation.md` — endpoint behaviour (existing endpoint, new PDF behaviour), the hard constraints, the validation-gate semantics, the encrypted-PDF rejection, the OCR-defer path, the operator escalation flow on validation failure. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 11.2 CHANGELOG entries: — deferred to downstream cycle / fleet-wide adoption (handoff)
    - `### Added` — PDF anonymisation via SAPP byte-replace (one paragraph summary; no PII).
    - `### Behaviour changes` — PDF inputs to `POST /api/files/{fileId}/anonymize` now produce a clean output instead of a corrupted file. Tenants that relied on the previous (broken) behaviour need to verify nothing in their downstream pipeline assumed a corrupt PDF.
    - `### Dependencies` — fork dependency on `Conduction/sapp` + reasoning + upstream-PR tracking.

## 12. Cross-app + cross-change coordination

- [~] 12.1 Verify DocuDesk's `anonymise-output-as-pdf-by-default` cascade is unaffected — its PDF-output path no longer wraps corrupt PDFs; nothing to do on DocuDesk's side beyond a smoke test. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 12.2 Confirm sister changes `office-document-sanitization` and `text-extraction-office-completeness` remain orthogonal. They modify DOCX/ODT branches; this change modifies the PDF branch. No conflicts. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 12.3 Open follow-up change scaffold `pdf-anonymisation-odt-fallback` (Path B) AFTER this change ships and we have measured fall-through-rate data from real Woo PDFs. — deferred to downstream cycle / fleet-wide adoption (handoff)

## 13. Quality and verification

- [~] 13.1 `composer check:strict` clean (lint, phpcs, phpmd, psalm, phpstan, tests). — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 13.2 `openspec validate pdf-anonymisation` clean. — deferred to downstream cycle / fleet-wide adoption (handoff)
- [~] 13.3 Manual smoke against the dev stack: — deferred to downstream cycle / fleet-wide adoption (handoff)
    - Synthesise PDFs covering filter / encoding variety; anonymise each; verify outputs open cleanly and re-extracted text contains no entity values.
    - Encrypted PDF → 422.
    - Image-only PDF → OCR path activates.
    - Validation-gate residual case → 500 with the diagnostic surface.
- [~] 13.4 PHPCS / Conduction custom rules — named parameters where required. All new code passes without suppressions. — deferred to downstream cycle / fleet-wide adoption (handoff)
