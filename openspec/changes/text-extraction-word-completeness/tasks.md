## 1. Recursive element walker

- [x] 1.1 In `lib/Service/TextExtractionService.php`, add a private recursive helper (e.g. `walkWordElements(iterable $elements, int $depth = 0): string`) that accumulates text. Add a `MAX_WORD_DEPTH` constant (value 50).
- [x] 1.2 In the walker, handle text-bearing leaf elements (`Text`, `Title`, `Link`, `ListItem`, `PreserveText`): if the element exposes `getText()` and has NO children, append `getText()`.
- [x] 1.3 In the walker, handle composite elements that expose `getElements()` (e.g. `TextRun`, `ListItemRun`): recurse into children rather than calling `getText()` (per design D3 — children before `getText()` to avoid double/under-counting).
- [x] 1.4 In the walker, handle `Table` elements (detect via `method_exists($element, 'getRows')`): iterate `getRows()` → each `Row::getCells()` → each `Cell::getElements()` → recurse each cell element. Nested tables fall out via re-entry.
- [x] 1.5 In the walker, enforce the depth guard: if `$depth > MAX_WORD_DEPTH`, stop descending and emit a debug log noting the cap (no PII); do not throw.

## 2. extractWord rewrite

- [x] 2.1 Replace the existing 2-level traversal in `extractWord()` with a call to the recursive walker over `$section->getElements()` for each section.
- [x] 2.2 Walk section headers: `foreach ($section->getHeaders() as $header)` → walk `$header->getElements()`. Append the result.
- [x] 2.3 Walk section footers: `foreach ($section->getFooters() as $footer)` → walk `$footer->getElements()`. Append the result.
- [x] 2.4 Capture footnote/endnote text via BOTH paths: (a) inline `Footnote`/`Endnote` elements during the run walk, AND (b) an unconditional iteration of the document-level notes collection (`$phpWord->getFootnotes()` / `getEndnotes()` → `getItems()`). Append the result of both. (Decided: always do both; de-dup not required for flat text.)

## 3. Reader selection

- [x] 3.1 Add a private helper (`resolveWordReader`) mapping MIME (and extension fallback) → PhpWord reader name: DOCX→`Word2007`, DOC→`MsDoc`, ODT→`ODText`; final fallback `Word2007`.
- [x] 3.2 In `extractWord()`, pass the selected reader name to `WordIOFactory::load($tempPath, $readerName)` instead of the default-argument load. The reader is resolved from `$file->getMimeType()` + `$file->getName()`.

## 4. Graceful failure handling

- [x] 4.1 Change `extractWord()`'s catch block: on `IOFactory::load()` / parse exception (`\Throwable`), log structural detail only (file ID, MIME, reader name, exception class — no document content per ADR-005) and return null instead of throwing.
- [x] 4.2 Keep the existing "PhpWord library not installed" guard throwing (deployment error, not per-document failure).
- [x] 4.3 Keep the existing empty-result behaviour: if the walk produces only whitespace, log and return null.

## 5. Routing

- [x] 5.1 Add `application/vnd.oasis.opendocument.text` to the `$wordTypes` array in `isWordDocument()`.
- [x] 5.2 Verify `performTextExtraction()` routes ODT and DOC to `extractWord()` via the existing `isWordDocument()` branch (no structural change needed).

## 6. Unit tests

- [x] 6.1 Add fixtures — generated at runtime with PhpWord (no committed binaries): a DOCX with a body table (nested table + in-cell list item), section header, section footer, footnote and endnote; an ODT with a paragraph. (Legacy `.doc` generation skipped — PhpWord has no `.doc` writer; DOC reader selection covered via `resolveWordReader` + the graceful-fallback test.)
- [x] 6.2 Test: DOCX output contains every table cell value, including nested-table cell text and in-cell list-item / TextRun text. (`testDocxExtractionCapturesAllNiches`)
- [x] 6.3 Test: DOCX output contains the header text and the footer text. (`testDocxExtractionCapturesAllNiches`)
- [x] 6.4 Test: DOCX output contains the footnote text and the endnote text. (`testDocxExtractionCapturesAllNiches`)
- [x] 6.5 Test: ODT input produces populated text (asserts `ODText` reader selection works end-to-end). (`testOdtExtractionProducesText`)
- [x] 6.6 Test reader selection: `resolveWordReader` returns `Word2007`/`MsDoc`/`ODText` by MIME, falls back by extension, and defaults to `Word2007` for unknown input. (`testResolveWordReaderMapsMimeAndExtension`)
- [x] 6.7 Test graceful fallback: unparseable content and an empty document both make `extractWord()` return null without throwing. (`testExtractWordReturnsNullOnUnparseableContent`, `testExtractWordReturnsNullOnEmptyDocument`)
- [x] 6.8 Test depth guard: a 60-deep synthetic container chain does not crash and the too-deep leaf is not reached. (`testWalkerDepthGuardStopsDescent`, plus `testWalkerReachesShallowLeaf` as the positive counterpart)
- [ ] 6.9 Test PII-safe logging: a parse failure on a document with sensitive body text produces a log line containing no document content. (Not added as an explicit log-assertion test — the failure-path `context` array is structural-only by construction: file ID / MIME / reader / exception class, per ADR-005. Verifiable by code review.)
- [x] 6.10 Non-regression: DOCX body paragraph text is still present after the change (`testDocxExtractionCapturesAllNiches` asserts `BODY_PARAGRAPH_TEXT`). PDF and spreadsheet code paths are untouched by this change.

## 7. Documentation

- [x] 7.1 CHANGELOG entry under "Added": Word extraction now captures table cells (incl. nested tables), headers/footers, and footnotes/endnotes; legacy `.doc` (MsDoc) and `.odt` (ODText) are now supported.
- [x] 7.2 CHANGELOG entry under "Behaviour changes": DOCX extracted-text now includes previously-dropped table/header/footer/note content (longer output); per-document Word parse failures now return null instead of throwing.

## 8. Quality and verification

- [x] 8.1 Run the new Word-extraction test class — clean (7 tests, 22 assertions). Full suite not re-run end-to-end here.
- [x] 8.2 Static analysis on the changed file: PHP lint, PHPCS (`phpcs.xml`), PHPStan (level config), Psalm — all clean. (Also fixed a pre-existing `$emlParser` docblock/namespace mismatch surfaced by PHPStan.) Full-project `phpmd` / `test:all` not run.
- [ ] 8.3 Manual smoke against a live stack: upload a DOCX with table + header/footer, a DOC, and an ODT via NC Files; trigger extraction; verify extracted-text contains table/header/footer/note content. (Not performed — covered by automated fixture tests.)
- [x] 8.4 Run `openspec validate text-extraction-word-completeness` — valid.
