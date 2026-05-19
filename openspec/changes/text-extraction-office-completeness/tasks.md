## 1. OfficeDocumentWalker class

- [ ] 1.1 Create `lib/Service/File/OfficeDocumentWalker.php`. Constructor injects `LoggerInterface $logger`.
- [ ] 1.2 Implement private `walk(\PhpOffice\PhpWord\PhpWord $phpWord, callable $visitor): void`:
    - For each section (1-indexed): iterate `Section::getHeaders()` array — each variant has `getType()` returning `Header::AUTO` / `FIRST` / `EVEN`; pass each header container's `getElements()` through `walkElements()`. Same for `Section::getFooters()`.
    - Iterate the section's body `Section::getElements()` through `walkElements()`.
    - After sections, iterate `PhpWord::getFootnotes()->getItems()` — each footnote container has `getElements()`; pass through `walkElements()`. Same for `getEndnotes()->getItems()`.
- [ ] 1.3 Implement private `walkElements(iterable $elements, callable $visitor): void`. For each element:
    - If `instanceof Table`: for each row (`getRows()`), for each cell (`getCells()`), call `walkElements($cell->getElements(), $visitor)`. Tables can be nested via this recursion.
    - Else if `instanceof ListItemRun`: call `walkElements($element->getElements(), $visitor)`.
    - Else if `instanceof TextBox`: call `walkElements($element->getElements(), $visitor)`.
    - Else if `instanceof TextRun`: call `walkElements($element->getElements(), $visitor)`.
    - Else if `method_exists($element, 'getText')`: invoke `$visitor($element)`.
    - Else if `method_exists($element, 'getElements')`: call `walkElements($element->getElements(), $visitor)` (defensive — handles unexpected wrappers).
    - Else: skip silently. Optionally log a debug entry with the class name only (no content per ADR-005).
- [ ] 1.4 Implement public `extractText(\PhpOffice\PhpWord\PhpWord $phpWord): string`:
    - Build the output in a buffer (string concat or StringBuilder).
    - For each section: emit header marker + content (per non-empty variant); body marker + content (in order); footer markers + content.
    - For tables in body: render rows as ` | `-delimited lines. For lists: one item text per line.
    - After sections, emit footnote / endnote blocks with `[Footnote N]` / `[Endnote N]` markers.
    - Use `walk()` internally with a visitor that accumulates `$element->getText()` into the per-block buffer.
    - Suppress empty blocks (don't emit a `[Section N — Header default]` marker if the variant has no text).
- [ ] 1.5 Implement public `replace(\PhpOffice\PhpWord\PhpWord $phpWord, array $substitutions): void`:
    - Build a visitor: `function($element) use ($substitutions) { $orig = $element->getText(); $new = strtr($orig, $substitutions); if ($new !== $orig && method_exists($element, 'setText')) { $element->setText($new); } }`.
    - Call `walk($phpWord, $visitor)`.
    - Log a single info-level summary at end: counts of paragraphs visited, substitutions applied (per ADR-005 — counts only, no content).

## 2. TextExtractionService refactor

- [ ] 2.1 Rename private `extractWord` to `extractOfficeDocument`. Keep `extractWord` as a thin alias forwarding to `extractOfficeDocument`, marked `@deprecated`. (Forwarder removed in the next release cycle per design D9.)
- [ ] 2.2 Refactor `extractOfficeDocument` body to delegate to `OfficeDocumentWalker::extractText($phpWord)`. Remove the old two-level loop. PhpWord load + tempfile setup stay; the in-memory traversal moves to the walker.
- [ ] 2.3 Add `isOpenDocumentText(string $mimeType): bool` mirroring `isWordDocument`. Match `application/vnd.oasis.opendocument.text` (exactly).
- [ ] 2.4 In the `extractFile` cascade (or `extractSourceText` — wherever the if-else cascade for MIME types lives), add a branch:
    ```
    else if ($this->isOpenDocumentText($mimeType)) {
        return $this->extractOfficeDocument($file); // walker handles both formats
    }
    ```
    Place the branch alongside the existing `isWordDocument` branch. Order: existing branches first (text MIMEs, PDF, Word/DOC, spreadsheet, EML); ODT is added before the fallthrough.
- [ ] 2.5 Inject `OfficeDocumentWalker` into `TextExtractionService` constructor. Wire via DI (Application.php register).
- [ ] 2.6 Update existing test snapshots for DOCX extraction output where pre-change output is asserted verbatim. New output includes section markers and previously-missing structures; snapshots updated accordingly.

## 3. DocumentProcessingHandler refactor

- [ ] 3.1 Locate the existing `replaceWords` method (or equivalent — the in-memory PhpWord-based entity-replacement helper) in `lib/Service/File/DocumentProcessingHandler.php`. Refactor its body to delegate to `OfficeDocumentWalker::replace($phpWord, $substitutions)`. Remove the old two-level loop.
- [ ] 3.2 In `anonymizeDocument` (or the MIME-dispatching entry point), restrict the ODT path to PhpWord-based handling:
    - If `mimeType === 'application/vnd.oasis.opendocument.text'`: load via PhpWord, build substitutions, call walker, save via `IOFactory::createWriter($phpWord, 'ODText')->save($outputPath)`.
    - If `mimeType` is in the Word set (DOCX, DOC): existing PhpWord path. DOCX saves via `Word2007` writer; DOC is extraction-only per scope (raise an explicit error if anonymisation is attempted on a `.doc`).
    - If `mimeType` is plain-text (`text/plain`, `text/markdown`, `text/csv`, etc.): existing `replaceWordsInTextDocument` raw-string-replace path.
    - Explicitly: ODT MUST NOT fall through to `replaceWordsInTextDocument`. Add a guard at the top of `replaceWordsInTextDocument` that throws if the MIME is a known ZIP-container format.
- [ ] 3.3 Inject `OfficeDocumentWalker` into `DocumentProcessingHandler` constructor.

## 4. Test fixtures

- [ ] 4.1 Create `tests/Fixtures/office-completeness/build-fixtures.php` — PHPUnit-runnable script that uses PhpWord programmatically to construct:
    - `complete.docx` — 2 sections, each with default + first-page + even-page headers and footers (each distinct text); body containing paragraphs, a 2x3 table, a nested table (table inside a cell), a 5-item bulleted list, a text frame with inner paragraph; 3 footnotes; 2 endnotes.
    - `complete.odt` — same in-memory model saved via `ODText` writer.
    - `edge-cases.docx` — empty section; merged-cell table; nested list (list with sub-list); text frame containing a table; a placeholder hyperlink (representing pre-sanitiser state).
    - Document the regeneration step in a comment header so future contributors know to re-run after model changes.
- [ ] 4.2 Commit the generated fixtures to the repo under `tests/Fixtures/office-completeness/`.

## 5. Unit tests

- [ ] 5.1 `tests/unit/Service/File/OfficeDocumentWalkerTest.php`:
    - Walker visits text inside nested table (depth ≥ 2).
    - Walker visits list item contents (`ListItemRun` traversal).
    - Walker visits text frame contents (`TextBox` traversal).
    - Walker visits headers (per variant — default, first-page, even).
    - Walker visits footers (per variant).
    - Walker visits footnotes from `getFootnotes()->getItems()`.
    - Walker visits endnotes.
    - Walker skips Image / Drawing elements without crashing.
    - `extractText` output contains section markers in expected order.
    - `extractText` output renders tables with ` | ` cell delimiter.
    - `extractText` suppresses markers for empty header/footer variants.
    - `extractText` appends footnotes / endnotes at the end with `[Footnote N]` markers.
    - `replace` mutates text inside nested tables.
    - `replace` mutates text inside footers.
    - `replace` mutates text inside footnotes.
    - `replace` preserves elements with no matching substitution (no-op on those).
    - `replace` handles `strtr` longest-match-first correctly (overlapping patterns).
    - PII-redacted logging: walker log lines never contain visited element text content.
- [ ] 5.2 `tests/unit/Service/TextExtractionServiceTest.php` — extend existing tests:
    - DOCX extraction output is a strict text-content superset of pre-change output (the previously-extracted body content still appears; new content appears in addition).
    - `isOpenDocumentText` matches the ODT MIME only.
    - ODT extraction via `extractFile` returns populated text (was null pre-change).
    - PDF / EML / spreadsheet extraction is unchanged (regression).
- [ ] 5.3 `tests/unit/Service/File/DocumentProcessingHandlerTest.php` — extend existing tests:
    - DOCX anonymisation substitutes entities in headers / footers / nested tables / footnotes (previously missed).
    - ODT anonymisation produces a valid `.odt` output (loadable via PhpWord round-trip).
    - ODT inputs are NOT dispatched to `replaceWordsInTextDocument` (guard test — mock the raw-text path and assert it's not called).
    - Plain-text inputs DO dispatch to the raw-text path (regression).

## 6. Integration tests

- [ ] 6.1 `tests/integration/.../OdtExtractionIntegrationTest.php` — upload `complete.odt`; call `extractFile`; assert the persisted extracted-text contains content from every section, every header/footer variant, every footnote, and the table cells.
- [ ] 6.2 `tests/integration/.../OdtAnonymizationIntegrationTest.php` — upload `complete.odt`; call `anonymizeDocument` with substitutions including entities present in headers, body, footers, and footnotes; load the output `.odt` via PhpWord and assert each location has the substituted text and not the original.
- [ ] 6.3 `tests/integration/.../DocxAnonymizationCompletnessTest.php` — upload `complete.docx`; anonymise; assert entities in headers / footers / nested tables / footnotes / text frames are substituted.

## 7. Manual validation gate (BLOCKING)

- [ ] 7.1 Take the fixture `complete.odt`. Run it through `anonymizeDocument` via CLI or PHPUnit. Save the output.
- [ ] 7.2 Open the output in **LibreOffice** (24.x or newer). Required: no recovery prompt; content matches input minus the substitutions.
- [ ] 7.3 Open the same output in **Microsoft Word** (Office 365 current channel or LTSC current). Required: no recovery prompt.
- [ ] 7.4 Take the fixture `complete.docx`. Anonymise. Open the output in Word and LibreOffice. Required: clean open in both.
- [ ] 7.5 Take `edge-cases.docx`. Anonymise. Open in both. Required: clean open OR a documented graceful failure mode for the edge case (e.g. merged-cell tables may produce a warning — if so, decide whether to fix or document).
- [ ] 7.6 Record validation pass in PR description (versions, screenshots if practical). Do NOT mark tasks 3.2 / 3.3 done until this gate passes.

## 8. Documentation

- [ ] 8.1 Extend `docs/features.md` (or the equivalent file) with an "Office document extraction" subsection covering: which formats are supported (DOCX, DOC read-only, ODT); the structure traversal (sections, headers, footers, tables, lists, footnotes, endnotes); the section-marker format in extracted text.
- [ ] 8.2 Add CHANGELOG entry under "Added": ODT support in `TextExtractionService` and `DocumentProcessingHandler`. Deeper traversal for DOCX covering headers / footers / nested tables / lists / footnotes / endnotes / text frames.
- [ ] 8.3 Add CHANGELOG entry under "Behavior changes": DOCX extracted text now includes header / footer / footnote content with section markers — re-extract via `forceReExtract: true` to refresh existing records. ODT files which previously failed extraction now succeed. ODT anonymisation which previously produced corrupted output now produces valid output.

## 9. Quality and verification

- [ ] 9.1 `composer check:strict` clean.
- [ ] 9.2 `openspec validate text-extraction-office-completeness` clean.
- [ ] 9.3 Manual smoke against the dev stack: upload a `.docx` with comments + tables + footnotes via NC Files; trigger anonymisation (with sister change `office-document-sanitization` also applied — these compose); verify the resulting file in Word/LibreOffice; verify the extracted text in OR metadata.
- [ ] 9.4 Same smoke for a `.odt` file.
- [ ] 9.5 PHPCS / Conduction custom rules — named parameters where required. All new code passes without suppressions.

## 10. Cross-app spec maintenance

- [ ] 10.1 Confirm the new spec file `openspec/specs/text-extraction-office-completeness/spec.md` (created on apply) lists this change as `in-progress`.
- [ ] 10.2 Cross-reference in design notes: this change depends on the sister `office-document-sanitization` for the cleaning step BUT can run standalone (walker tolerates sanitiser-untouched documents — comments, tracked changes, hyperlinks are simply visited as text where the walker reaches them).
