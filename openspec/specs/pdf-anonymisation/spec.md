---
status: done
---

# pdf-anonymisation Specification

## Purpose
Anonymises PDF documents by rewriting their content streams in place rather than corrupting bytes via naive string replacement. Decodes the text-relevant PDF 1.7 filter set, resolves font encodings (including Identity-H composite fonts via ToUnicode CMaps), flattens kerning arrays, and replaces every variant of a detected entity with an identifiable `[<TYPE>: <id>]` placeholder while preserving table and layout structure and scrubbing metadata to a sentinel. A post-replacement validation gate re-extracts the output and discards it if any entity text survives; image-only PDFs defer to OCR and encrypted PDFs are rejected. The capability extends the existing anonymise endpoint with no new routes and no changes to the DOCX/ODT/text branches.
## Requirements
### Requirement: `DocumentProcessingHandler::anonymizeDocument` MUST handle PDF inputs without corrupting the output

The dispatch in `anonymizeDocument` MUST route inputs with `application/pdf` MIME (or `.pdf` extension fallback) to a dedicated PDF replacement path. The output PDF MUST open cleanly in standard PDF readers (Adobe Acrobat, Firefox / Chromium built-in viewers, evince) without warnings or errors. The output MUST NOT be the result of `str_ireplace` on raw PDF bytes — that operation MUST be replaced for the PDF branch.

#### Scenario: PDF anonymisation produces a valid PDF file

- **GIVEN** a PDF file (mime `application/pdf`) with a text layer, registered detected entities, and a non-empty substitution map
- **WHEN** `DocumentProcessingHandler::anonymizeDocument` is invoked
- **THEN** the output is a valid PDF (passes `pdfinfo` / opens in a standard reader without errors)
- **AND** the output's text layer no longer contains any of the entity values from the substitution map (validated via re-extraction — see the validation-gate Requirement)

#### Scenario: Pre-change PDF behaviour (str_ireplace corruption) is eliminated

- **GIVEN** a PDF input that, on the pre-change code path, would have been processed by `replaceWordsInTextDocument` and produced an unopenable file
- **WHEN** the same input is processed on the post-change code path
- **THEN** the output is a valid, openable PDF
- **AND** the substitution map's entries are absent from the output text layer

### Requirement: Image-only PDFs MUST defer to the `ocr-document-scanning` capability

Before invoking the byte-replace pipeline, the implementation MUST probe the input for a text layer via `smalot/pdfparser`. PDFs that contain no extractable text (image-only scans without an OCR text layer) MUST defer to the `ocr-document-scanning` capability rather than producing an empty / no-op output via the byte-replace path.

#### Scenario: Image-only PDF defers to OCR

- **GIVEN** a PDF file with no extractable text (image pages, no OCR text layer)
- **WHEN** `anonymizeDocument` dispatches the PDF branch
- **THEN** the implementation defers to `ocr-document-scanning` and does not attempt byte-replace
- **AND** the response surface to the caller is the OCR capability's response (not a pdf-anonymisation success or failure)

### Requirement: The output MUST NOT contain any original entity text in any PDF layer

"No original PII in the output" is hard constraint #1. The implementation MUST ensure that for every entity value in the substitution map (including all variants — full name, surname-only, first-initial-plus-surname, etc.), the value is absent from EVERY layer of the output PDF:

- Visible text layer (content streams via Tj/TJ operators).
- Hidden text layer (text rendered with zero opacity or behind a covering rectangle — same content-stream operators, different render mode).
- Document metadata (`/Info` dictionary fields, `/Metadata` XMP stream).
- Bookmark / outline entries (`/Outlines`).
- Annotation text contents (`/Annots → /Contents`).

Visual-overlay-only approaches (paint a black rectangle over the text) are explicitly ruled out.

#### Scenario: Re-extraction of the output finds no entity text

- **GIVEN** a substitution map containing `"Jan Jansen"`, `"Jansen"`, `"Jan"` (all variants of one entity, all mapped to `[PERSON: 7]`)
- **AND** an input PDF where each variant appears at least once in the text layer
- **WHEN** `anonymizeDocument` completes successfully
- **THEN** re-extracting the output PDF via `smalot/pdfparser` returns text that contains NONE of the three variants
- **AND** the extracted text DOES contain `[PERSON: 7]`

### Requirement: Replacement output MUST use identifiable placeholders, not pure redaction

Hard constraint #2: replacements MUST take the form `[<TYPE>: <id>]` (the established convention from `entity-relation-grondslagen`). Pure black-bar redaction is ruled out. All variants of one logical entity MUST resolve to the same placeholder text (same id) — the substitution map already enforces this; this Requirement locks the invariant at the spec level so future maintainers don't break it for layout reasons.

#### Scenario: Placeholder format follows `[<TYPE>: <id>]`

- **GIVEN** an entity with type `PERSON`, id `7`, and value `"Jan Jansen"`
- **WHEN** `anonymizeDocument` replaces this entity in a PDF
- **THEN** every replacement instance in the output text reads `[PERSON: 7]` (case-sensitive, with a space after the colon)

#### Scenario: Variants of one entity share one placeholder

- **GIVEN** entity id `7` with value `"Jan Jansen"`, variants `["Jansen", "Jan"]`
- **WHEN** `anonymizeDocument` replaces these in a PDF containing all three
- **THEN** every replacement (regardless of which variant matched) reads `[PERSON: 7]`
- **AND** adjacent identical placeholders separated only by whitespace / dashes / underscores ARE collapsed to a single placeholder

### Requirement: Replacement injection MUST preserve original layout

Hard constraint #3: tables, paragraph wrapping at the visual-block level, and visual position of surrounding text MUST be preserved. The implementation MUST:

- Modify only the content-stream bytes of operators that contain matches.
- Switch font on replacement insertion (Helvetica, one of the 14 PDF standard base fonts) so the placeholder renders even when the original font is a subset that excludes the placeholder's glyphs.
- Restore the original font after the replacement, so following text in the same content stream renders unchanged.

Overflow within a cell, paragraph wrapping changes at the operator level (placeholder shorter than original word), and minor visual layout shifts within a cell ARE acceptable. Loss of table structure is NOT acceptable.

#### Scenario: Replacement uses Helvetica via font switch

- **GIVEN** a PDF where `"Jan Jansen"` appears in a font (F3) that does not include glyphs for `[`, `]`, or `:`
- **WHEN** `anonymizeDocument` replaces this entity
- **THEN** the output content stream emits `/F-Replacement <size> Tf (<placeholder>) Tj /F3 <size> Tf` (or equivalent — font switched to Helvetica for the placeholder, restored for following text)
- **AND** the placeholder renders correctly in standard PDF readers

#### Scenario: Tables retain their structural integrity

- **GIVEN** a PDF containing a 3×3 table with the entity in one cell
- **WHEN** the entity is replaced
- **THEN** the output PDF still renders as a 3×3 table (cells remain visually distinct, borders intact, row/column structure preserved)

### Requirement: Stream filter decoding MUST cover the text-relevant PDF 1.7 filter set

The implementation MUST decode content streams encoded with any of `/FlateDecode`, `/LZWDecode`, `/ASCII85Decode`, `/ASCIIHexDecode`, `/RunLengthDecode`, including chained filters (e.g. `/Filter [/ASCII85Decode /FlateDecode]` applied in order). Encrypted streams (`/Filter /Crypt`) MUST raise `PdfAnonymisationException(reason: 'encrypted_pdf')` — anonymising a password-protected PDF requires the password and is out of scope for v1. Image-only filters (`/DCTDecode`, `/CCITTFaxDecode`, `/JBIG2Decode`, `/JPXDecode`) MUST be left untouched (text-replacement doesn't apply to image streams).

#### Scenario: ASCII85-wrapped Flate stream decodes correctly

- **GIVEN** a content stream with `/Filter [/ASCII85Decode /FlateDecode]`
- **WHEN** the decoder pipeline processes the stream
- **THEN** ASCII85 decoding runs first, FlateDecode runs on the ASCII85 output, and the final result is the readable content-stream bytes

#### Scenario: Encrypted PDF is rejected explicitly

- **GIVEN** a PDF whose content streams are encrypted (`/Filter /Crypt`)
- **WHEN** `anonymizeDocument` is invoked
- **THEN** `PdfAnonymisationException` is raised with `reason: 'encrypted_pdf'`
- **AND** the controller MUST surface a structured 422 with `{"error": "pdf_anonymisation_failed", "reason": "encrypted_pdf"}`

### Requirement: Font encoding resolution MUST cover WinAnsi / MacRoman / Standard / Differences / Identity-H/V (with ToUnicode CMap)

For each font referenced in any page's `/Resources → /Font`, the implementation MUST resolve its encoding to produce a forward table (character code → Unicode) used for substitution-map → per-font-byte-sequence translation. The implementation MUST handle:

- Single-byte encodings: `WinAnsiEncoding`, `MacRomanEncoding`, `StandardEncoding` — ASCII characters (0x20–0x7F) map literally.
- `/Encoding` dictionaries with `/Differences` arrays — apply the per-character overrides against the base encoding.
- Composite fonts (`Identity-H`, `Identity-V`) — parse the font's `/ToUnicode` CMap (typically itself FlateDecoded) including `beginbfchar`, `beginbfrange`, and `begincodespacerange` operators. Build a reverse Unicode → 2-byte glyph ID table.

#### Scenario: WinAnsi-encoded text is matched literally

- **GIVEN** an input PDF where `"Jan Jansen"` is in a font using `WinAnsiEncoding`
- **WHEN** the substitution-map encoding resolver runs
- **THEN** the per-font byte sequence for `"Jan Jansen"` is `0x4A 0x61 0x6E 0x20 0x4A 0x61 0x6E 0x73 0x65 0x6E` (literal ASCII)

#### Scenario: Identity-H composite font is decoded via ToUnicode

- **GIVEN** an input PDF where `"Jan Jansen"` is in an `Identity-H` font subset with a `/ToUnicode` CMap
- **WHEN** the resolver builds the reverse table
- **THEN** each Unicode character maps to its 2-byte glyph ID per the CMap's `beginbfchar` / `beginbfrange` entries
- **AND** the substitution map's per-font byte sequence concatenates the 2-byte IDs in order

### Requirement: Per-character TJ kerning arrays MUST be flattened before matching

Word-generated PDFs encode kerned text as `[(J) 5 (a) -3 (n) ...] TJ` where the bracketed entries are per-character glyphs and the numbers are kerning adjustments. The implementation MUST flatten these into single-string `Tj` operators (concatenating glyphs, discarding kerning numbers) BEFORE the substitution-map match runs. This is a layout-detail loss (typographic kerning) but functionally lossless for body text in Word-generated Woo PDFs.

Optional refinement: kerning numbers with absolute value > 200 (intentional word-break spacing in justified text) MAY emit a single space in the flattened string. v1 may discard all numbers; the refinement is added if real-world degradation surfaces.

#### Scenario: TJ kerning array is flattened before match

- **GIVEN** a content stream with `[(J) 5 (a) -3 (n) 10 ( ) (J) -2 (a) -3 (n) 5 (s) -1 (e) -5 (n)] TJ`
- **WHEN** the flattening pre-pass runs
- **THEN** the result is `(Jan Jansen) Tj`
- **AND** subsequent substitution-map matching against `"Jan Jansen"` succeeds

### Requirement: PDF metadata MUST be sanitised in parity with `office-document-sanitization`

The implementation MUST strip PII from PDF metadata in both `/Info` and `/Metadata` (XMP):

- `/Info` fields `/Title`, `/Author`, `/Subject`, `/Keywords`, `/Creator` → set to the sentinel `"DocuDesk Anonymisation"`.
- `/Info` field `/Producer` → preserved (PDF library identifier, not PII).
- `/Info` fields `/CreationDate`, `/ModDate` → preserved (timestamps).
- `/Metadata` XMP stream → parse the RDF/XML; replace `dc:*`, `xmp:*`, `pdf:*` namespace fields with the sentinel; preserve custom namespaces (downstream-workflow metadata).

#### Scenario: PDF Author metadata is sanitised

- **GIVEN** an input PDF with `/Info << /Author (Jan Jansen) ... >>`
- **WHEN** anonymisation completes
- **THEN** the output's `/Info → /Author` field reads `DocuDesk Anonymisation`

### Requirement: A post-replacement validation pass MUST report residual entity text (best-effort, not fail-closed)

After SAPP rewrites the PDF, the implementation MUST re-extract the output's text via `smalot/pdfparser` and detect any substitution-map entry (including variants) that survives. **The policy is best-effort, not fail-closed** (changed 2026-06-16, product-owner-approved): a partially-redacted file MUST still be produced and persisted, and the residual entities surfaced as a warning, so the operator can iterate (add manual entities, skip unselected occurrences) and re-run. When residual entity text is found, the implementation MUST:

1. Still produce/persist the output (do NOT discard it).
2. Log a PII-free diagnostic (counts + structural counters only, per ADR-005 — never the residual text in logs).
3. Return the residual needle list to the caller. The anonymise response is HTTP 200 with `{"success": true, "complete": false, "residual_count": N, "residual_entities": [...]}`. The authenticated response MAY include the residual entity *text* for the review UI (a deliberate, product-owner-approved deviation from the ADR-005 no-PII-in-responses rule; logs remain PII-free).

The validation pass remains the key safety surface — it catches every silent-failure mode of byte-replace (encoding mismatches, missed splits, font edge cases) — but it now informs rather than blocks, because some residuals (e.g. NER spans that cross table cells and are not contiguous in the PDF) cannot be redacted as a single needle and would otherwise make every such document fail.

#### Scenario: Validation surfaces residual entity text without discarding the file

- **GIVEN** an input PDF that produces an output containing `"Jan Jansen"` (the byte-replace missed some occurrence)
- **WHEN** the validation pass runs
- **THEN** the output is still produced and persisted
- **AND** the residual needle is returned to the caller (no exception)
- **AND** the controller surface is HTTP 200 with `success: true`, `complete: false`, and the residual list
- **AND** the log line is PII-free (count + counters only)

#### Scenario: Validation passes when the output is clean

- **GIVEN** an input PDF where all substitution-map entries were correctly replaced
- **WHEN** the validation pass runs
- **THEN** the output is returned as the anonymisation result with `complete: true` and an empty residual list
- **AND** a sanitisation report is persisted (counter of bytes replaced, fonts touched, filters decoded)

### Requirement: NO new HTTP endpoints

This capability extends `anonymizeDocument`. It MUST be invoked via the existing `POST /api/files/{fileId}/anonymize` endpoint. No new public endpoints. PDF inputs that today produce a corrupt file now produce a clean anonymised PDF.

#### Scenario: PDF anonymisation is invoked via the existing endpoint

- **GIVEN** a tenant whose DocuDesk integration calls `POST /api/files/{fileId}/anonymize` for documents of all types
- **WHEN** this capability ships
- **THEN** the tenant's call path is unchanged — same URL, same auth, same request shape
- **AND** no new endpoint appears under `appinfo/routes.php` for the PDF flow
- **AND** PDF inputs that previously returned a corrupt file via this endpoint now return a clean anonymised PDF via this same endpoint

### Requirement: NO breaking changes for DOCX / ODT / text branches

The dispatch in `anonymizeDocument` for DOCX, ODT, and plain-text inputs MUST be preserved unchanged. This capability targets the PDF branch exclusively. The `anonymizeDocument` public contract (return shape, side effects, audit-trail entries) MUST be preserved for callers.

#### Scenario: DOCX anonymisation is unaffected

- **GIVEN** a DOCX file that has gone through `anonymizeDocument` successfully on the pre-change code path
- **WHEN** the same DOCX file is processed on the post-change code path with identical inputs
- **THEN** the output is byte-identical (or at minimum, semantically identical — same replacements at same positions, same audit-trail entries)
- **AND** no DOCX-branch code paths in `DocumentProcessingHandler` are modified by this change

#### Scenario: ODT anonymisation is unaffected

- **GIVEN** an ODT file processed via `anonymizeDocument` on the pre-change code path
- **WHEN** the same ODT is processed on the post-change code path
- **THEN** the output is semantically identical to the pre-change output

### Requirement: Composer dependency on `Conduction/sapp` MUST be explicit and tracked

The `composer.json` repository entry pointing at `Conduction/sapp` MUST be visible in the dependency manifest and called out in `CHANGELOG.md` under `### Dependencies` (or equivalent). When upstream `dealfonso/sapp` merges the work, the repositories entry is removed and the version constraint switches to a normal range; the spec describes this transition explicitly so future maintainers know the fork dependency is provisional, not permanent.

#### Scenario: Composer manifest carries the fork repository

- **GIVEN** the `pdf-anonymisation` change has shipped
- **WHEN** a maintainer reads `composer.json`
- **THEN** they see a `repositories` entry for `https://codeberg.org/Conduction/sapp`
- **AND** the `ddn/sapp` constraint resolves to a `work/text-replacement`-branch dev-version
- **AND** the CHANGELOG entry documents the fork dependency + the upstream PR series tracking it

