---
status: draft
---

# Text Extraction — Office Document Completeness

## Purpose

Defines a deeper traversal for `.docx` and `.odt` documents on both extraction (`TextExtractionService`) and anonymisation (`DocumentProcessingHandler`) paths in OpenRegister. The current walker reaches two levels deep and misses table cells (beyond direct section children), lists, headers, footers, footnotes, endnotes, and text frames. ODT is unsupported entirely. This capability introduces a shared `OfficeDocumentWalker` driving both paths over the same content surface, and adds ODT as a first-class MIME.

## ADDED Requirements

### Requirement: `OfficeDocumentWalker` MUST traverse all text-bearing structures of a PhpWord document

The walker class `OCA\OpenRegister\Service\File\OfficeDocumentWalker` MUST expose a `walk(\PhpOffice\PhpWord\PhpWord $phpWord, callable $visitor): void` method that visits every text-bearing element in:

- Each section's body elements (in document order).
- Each section's headers — all variants returned by `Section::getHeaders()` (default, first-page, even-page).
- Each section's footers — all variants returned by `Section::getFooters()`.
- Inside the above, recursively into: `Table` (every row, every cell, every cell-level element — which may itself be a `Table`, `ListItemRun`, `TextBox`, or text run); `ListItemRun` (every contained element); `TextBox` (every contained element); `TextRun` (every contained element).
- Document-level `PhpWord::getFootnotes()->getItems()` — each footnote's `getElements()` recursed identically.
- Document-level `PhpWord::getEndnotes()->getItems()` — each endnote's `getElements()` recursed identically.

The visitor callback receives each text-leaf element (an element exposing `getText()`). Non-text elements (`Image`, `Drawing`, `OLEObject`, `Chart`) MUST be skipped silently.

#### Scenario: Walker visits text inside a nested table cell

- **GIVEN** a DOCX containing an outer table with a cell that contains an inner table whose cell contains a `Text` element with the content "Jan Jansen"
- **WHEN** `OfficeDocumentWalker::walk($phpWord, $visitor)` runs
- **THEN** the visitor is invoked with the `Text` element containing "Jan Jansen"

#### Scenario: Walker visits list item contents

- **GIVEN** a DOCX with a bulleted list containing 5 `ListItemRun` items, each holding a `Text` element with the item's content
- **WHEN** the walker runs
- **THEN** the visitor is invoked once for each of the 5 list-item `Text` elements

#### Scenario: Walker visits header and footer content

- **GIVEN** a DOCX whose section has a default header with text "Dossier 2026-017" and a default footer with text "Behandelaar: A. de Boer"
- **WHEN** the walker runs
- **THEN** the visitor is invoked with a text element containing "Dossier 2026-017"
- **AND** the visitor is invoked with a text element containing "Behandelaar: A. de Boer"

#### Scenario: Walker visits footnote and endnote content

- **GIVEN** a DOCX with 2 footnotes (`PhpWord::getFootnotes()->getItems()` returns 2 items) and 1 endnote
- **WHEN** the walker runs
- **THEN** every text element inside every footnote is visited
- **AND** every text element inside the endnote is visited

#### Scenario: Walker visits text frame content

- **GIVEN** a DOCX with a text frame (`TextBox`) containing a paragraph with text "Vertrouwelijk"
- **WHEN** the walker runs
- **THEN** the visitor is invoked with the text element containing "Vertrouwelijk"

#### Scenario: Walker skips non-text elements

- **GIVEN** a DOCX with an embedded image (`PhpOffice\PhpWord\Element\Image`)
- **WHEN** the walker runs
- **THEN** the visitor is NOT invoked for the Image element
- **AND** no exception is thrown

### Requirement: `OfficeDocumentWalker::extractText` MUST return a flat string with predictable section markers

The method `OfficeDocumentWalker::extractText(\PhpOffice\PhpWord\PhpWord $phpWord): string` MUST return a single string composed of:

- For each section in document order, identified by 1-indexed position:
  - For each non-empty header variant on the section (default, then firstPage if distinct from default, then even if distinct from default), preceded by a marker line `[Section <N> — Header <variant>]` and followed by the variant's text content with paragraphs separated by newlines.
  - A marker line `[Section <N> — Body]` followed by the body content (paragraphs, tables, lists, text frames in document order).
  - For each non-empty footer variant (default / firstPage / even, same dedup rule), preceded by `[Section <N> — Footer <variant>]` and the variant's text content.
- After all sections: for each footnote in `getFootnotes()->getItems()` order, preceded by a marker `[Footnote <N>]` (1-indexed within the document) and the footnote's text content.
- For each endnote: preceded by `[Endnote <N>]` and the endnote's text content.

Within each block, tables are rendered as text with row content separated by newlines and cell content separated by ` | `. Lists are rendered as one item per line. Empty sections / blocks are omitted (no marker line emitted for an empty block).

#### Scenario: Section markers appear in output

- **GIVEN** a DOCX with one section containing a body paragraph "Hello"
- **WHEN** `extractText($phpWord)` is called
- **THEN** the returned string contains the marker `[Section 1 — Body]`
- **AND** the line "Hello" appears after that marker

#### Scenario: Header content precedes body in output

- **GIVEN** a DOCX with a section whose default header contains "Dossier 2026-017" and whose body contains "Hierbij bevestig ik..."
- **WHEN** `extractText` runs
- **THEN** "Dossier 2026-017" appears in the output before "Hierbij bevestig ik..."
- **AND** the marker `[Section 1 — Header default]` appears before "Dossier 2026-017"

#### Scenario: Footnotes appended at end of output

- **GIVEN** a DOCX with body content "Zie ¹" and a single footnote containing "Toelichting bij keuze"
- **WHEN** `extractText` runs
- **THEN** "Toelichting bij keuze" appears at the END of the output (after all section content)
- **AND** the marker `[Footnote 1]` precedes the footnote content

#### Scenario: Table rendered as text with cell delimiters

- **GIVEN** a DOCX with a 2-row, 3-column table; row 1 cells contain "A", "B", "C"; row 2 cells contain "D", "E", "F"
- **WHEN** `extractText` runs
- **THEN** the output contains a line "A | B | C"
- **AND** a line "D | E | F"

#### Scenario: Empty headers do not emit markers

- **GIVEN** a DOCX with a section that has no headers configured (Section::getHeaders() returns empty array)
- **WHEN** `extractText` runs
- **THEN** the output does NOT contain the marker `[Section 1 — Header default]`
- **AND** the section's body content still appears under `[Section 1 — Body]`

### Requirement: `OfficeDocumentWalker::replace` MUST mutate text-leaf elements using a substitution map

The method `OfficeDocumentWalker::replace(\PhpOffice\PhpWord\PhpWord $phpWord, array $substitutions): void` MUST:

- Walk every text-leaf element (per the walk-coverage Requirement above) — including elements inside tables, lists, headers, footers, footnotes, endnotes, and text frames.
- For each text-leaf element where `method_exists($element, 'setText')` is true: apply `strtr($element->getText(), $substitutions)` and if the result differs, call `$element->setText($newText)`.
- For elements that don't expose `setText()` (e.g. certain PhpWord versions' `Link` elements): skip and log a debug-level entry with the element's class name (no text content per ADR-005).

`$substitutions` is a map from detected entity text → replacement text. `strtr` performs longest-match-first replacement, which handles overlapping patterns correctly (e.g. `"Jan Jansen" => "[PERSON: a]"` and `"Jan" => "[PERSON: b]"` — `strtr` picks the longer match for "Jan Jansen").

#### Scenario: Entity in body is substituted

- **GIVEN** a PhpWord-loaded DOCX whose body paragraph contains the text "Aanvraag van Jan Jansen"
- **AND** a substitution map `['Jan Jansen' => '[PERSON: 7]']`
- **WHEN** `replace($phpWord, $substitutions)` runs
- **THEN** the body paragraph's text is now "Aanvraag van [PERSON: 7]"

#### Scenario: Entity in footer is substituted

- **GIVEN** a PhpWord-loaded DOCX whose section default footer contains "Behandelaar: A. de Boer"
- **AND** a substitution map `['A. de Boer' => '[PERSON: 3]']`
- **WHEN** `replace` runs
- **THEN** the footer text is now "Behandelaar: [PERSON: 3]"

#### Scenario: Entity in table cell is substituted

- **GIVEN** a DOCX with a table cell containing "Verzoeker: Saskia Bakker"
- **AND** a substitution map `['Saskia Bakker' => '[PERSON: 11]']`
- **WHEN** `replace` runs
- **THEN** the cell text is now "Verzoeker: [PERSON: 11]"

#### Scenario: Entity in footnote is substituted

- **GIVEN** a DOCX with a footnote containing "Geadviseerd door Burgemeester De Vries"
- **AND** a substitution map `['Burgemeester De Vries' => '[PERSON: 2]']`
- **WHEN** `replace` runs
- **THEN** the footnote text is now "Geadviseerd door [PERSON: 2]"

#### Scenario: Overlapping substitutions resolved longest-first

- **GIVEN** a body paragraph containing "Jan Jansen kwam met Jan voor het loket"
- **AND** a substitution map `['Jan Jansen' => '[PERSON: a]', 'Jan' => '[PERSON: b]']`
- **WHEN** `replace` runs
- **THEN** the paragraph text is now "[PERSON: a] kwam met [PERSON: b] voor het loket"

### Requirement: `TextExtractionService` MUST support ODT inputs via the same walker

The MIME branch in `TextExtractionService::extractFile` (or its cascade method) MUST recognise `application/vnd.oasis.opendocument.text` and dispatch to a method that:

1. Loads the file via PhpWord's `IOFactory::load($tempPath, 'ODText')` (or auto-detection).
2. Delegates to `OfficeDocumentWalker::extractText($phpWord)`.
3. Returns the result.

The helper `TextExtractionService::isOpenDocumentText(string $mimeType): bool` MUST exist and return true ONLY for `application/vnd.oasis.opendocument.text`.

#### Scenario: ODT input produces populated extracted text

- **GIVEN** an ODT file with a body paragraph "Onderwerp: Woo-verzoek"
- **WHEN** `TextExtractionService::extractFile($fileId)` is called
- **THEN** the persisted extracted-text is non-empty
- **AND** the text contains "Onderwerp: Woo-verzoek"

#### Scenario: ODT extraction uses the deeper walker

- **GIVEN** an ODT with a 2-section structure including header / body / footer / footnote content
- **WHEN** `extractFile` runs
- **THEN** the extracted text contains content from each section's header, body, and footer
- **AND** the extracted text contains footnote content with the `[Footnote N]` marker

#### Scenario: Pre-change ODT inputs (which returned null) return populated text on re-extract

- **GIVEN** an ODT file that had been processed before this change landed (extracted text was null)
- **WHEN** `extractFile` is called with `forceReExtract: true`
- **THEN** the persisted extracted-text is populated per the new walker output

### Requirement: `DocumentProcessingHandler::anonymizeDocument` MUST handle ODT inputs through PhpWord's ODText writer

The handler MUST dispatch ODT inputs (MIME `application/vnd.oasis.opendocument.text`) through PhpWord, with the following steps:

- Load via PhpWord (`IOFactory::load`).
- Invoke `OfficeDocumentWalker::replace($phpWord, $substitutions)` with the detection-derived substitution map.
- Save via `IOFactory::createWriter($phpWord, 'ODText')->save($outputPath)`.

The anonymisation pipeline MUST NOT dispatch ODT inputs to `replaceWordsInTextDocument` (the raw-string-replace path), which corrupts the ZIP container. The raw-string-replace path MUST be restricted to plain-text MIMEs (`text/plain`, `text/markdown`, and similar — explicitly NOT ODT, DOCX, or any ZIP-container format).

#### Scenario: ODT anonymisation produces a valid `.odt` file

- **GIVEN** an ODT input containing the text "Burgemeester De Vries"
- **AND** the entity detection identifies "Burgemeester De Vries" as a PERSON entity to substitute with "[PERSON: 1]"
- **WHEN** `anonymizeDocument` is called
- **THEN** the output file is a valid `.odt` (ZIP container with `mimetype`, `content.xml`, etc. — opens in LibreOffice without recovery prompt)
- **AND** the output file contains the text "[PERSON: 1]"
- **AND** the output file does NOT contain the text "Burgemeester De Vries"

#### Scenario: ODT is NOT dispatched to raw-string replacement

- **GIVEN** an ODT input
- **WHEN** `anonymizeDocument` runs
- **THEN** the method `replaceWordsInTextDocument` (the raw-string-replace handler) is NOT invoked for this input
- **AND** the method invoking PhpWord's ODText writer IS invoked

#### Scenario: Plain-text input is dispatched to raw-string replacement

- **GIVEN** a `.txt` file with MIME `text/plain` containing "Saskia Bakker"
- **AND** a substitution map including "Saskia Bakker"
- **WHEN** `anonymizeDocument` runs
- **THEN** `replaceWordsInTextDocument` IS invoked (the plain-text path is unchanged by this change)
- **AND** the output contains the substituted text

### Requirement: DOCX anonymisation MUST substitute entities in all walker-covered structures

When `anonymizeDocument` processes a `.docx`, entity substitution MUST be applied to every text-bearing structure the walker covers. Specifically:

- Entities found in body paragraphs are substituted (existing behaviour, preserved).
- Entities found in headers (any variant) are substituted (new behaviour).
- Entities found in footers (any variant) are substituted (new behaviour).
- Entities found in table cells (recursive — including nested tables) are substituted (new behaviour or extended behaviour, depending on prior limited reach).
- Entities found in list items are substituted (new behaviour).
- Entities found in text frames are substituted (new behaviour).
- Entities found in footnotes and endnotes are substituted (new behaviour).

#### Scenario: DOCX with header entity has the header substituted

- **GIVEN** a DOCX whose default header contains "Dossier voor S. de Vries"
- **AND** the entity detection identifies "S. de Vries" as PERSON to substitute with "[PERSON: 1]"
- **WHEN** `anonymizeDocument` runs
- **THEN** the output DOCX's default header contains "Dossier voor [PERSON: 1]"
- **AND** the original "S. de Vries" text does NOT appear in the header

#### Scenario: DOCX with nested-table entity has the cell substituted

- **GIVEN** a DOCX with an outer table whose cell contains an inner table whose cell contains "Jan Jansen"
- **AND** substitution `'Jan Jansen' => '[PERSON: 2]'`
- **WHEN** `anonymizeDocument` runs
- **THEN** the inner table cell in the output contains "[PERSON: 2]"

#### Scenario: DOCX with footnote entity has the footnote substituted

- **GIVEN** a DOCX with a footnote containing "geadviseerd door Saskia Bakker"
- **AND** substitution `'Saskia Bakker' => '[PERSON: 5]'`
- **WHEN** `anonymizeDocument` runs
- **THEN** the footnote in the output contains "geadviseerd door [PERSON: 5]"

### Requirement: Walker MUST NOT log any visited text content (ADR-005)

`OfficeDocumentWalker`'s log lines MUST NOT include any text content from visited elements. Permitted log payloads:

- File ID (if available — passed by caller).
- MIME type.
- Counts: paragraphs visited, tables visited, list items visited, headers visited, footers visited, footnotes visited, endnotes visited, substitutions applied.
- For unrecognised element classes: the class name (without any text content from the element).
- For exceptions: the exception class name and a structural reason string.

#### Scenario: Walker invocation with PII content does not log the content

- **GIVEN** a DOCX containing the text "Jan Jansen, BSN 123456789"
- **WHEN** the walker runs (extractText or replace)
- **THEN** no log entry from `OfficeDocumentWalker` contains "Jan Jansen" or "123456789"
- **AND** log entries MAY contain counts and class names per the permitted-payload list

### Requirement: Pre-change DOCX extraction output MUST remain a subset of post-change output

After this change, every DOCX file's extracted text MUST be a strict superset (in terms of text content) of what `extractWord` produced before the change. No content that the pre-change walker emitted may be missing post-change. The post-change output additionally includes header, footer, footnote, endnote, deeper-table, list, and text-frame content with section markers.

This Requirement makes the extraction change unambiguously additive for DOCX consumers.

#### Scenario: A simple DOCX with body-only content extracts the same body

- **GIVEN** a minimal DOCX with a single section containing only a body paragraph "Hello"
- **WHEN** `extractFile` runs after this change
- **THEN** the extracted text contains "Hello"
- **AND** the text appears (possibly preceded by the `[Section 1 — Body]` marker)

#### Scenario: A DOCX with body-and-table content keeps the body and gains the table

- **GIVEN** a DOCX whose pre-change extracted text was "Voorblad\nNaam | Waarde\nA | 1" (body paragraph + a directly-nested table the old walker partially reached)
- **WHEN** `extractFile` runs after this change
- **THEN** the post-change extracted text contains "Voorblad"
- **AND** the post-change extracted text contains the table content with proper cell delimiters
- **AND** the post-change extracted text MAY contain additional structures (headers, footers, etc.) that the old walker missed

### Requirement: Anonymised ODT output MUST open cleanly in LibreOffice and Microsoft Word

For every ODT processed by `anonymizeDocument`, the output `.odt` MUST be openable in:

- LibreOffice 24.x or newer
- Microsoft Word (Office 365 current channel or LTSC current branch)

without either application showing a "found unreadable content / want to recover?" dialog. Visible content MUST match the input minus the entity substitutions.

Validation MUST be performed against the test fixtures `tests/Fixtures/office-completeness/complete.odt` and `edge-cases.odt`. This is a BLOCKING manual gate — implementation tasks marking ODT support done MUST NOT be marked done until the gate passes.

#### Scenario: Anonymised complete.odt fixture opens in LibreOffice without recovery

- **GIVEN** the fixture `complete.odt` is processed by `anonymizeDocument` with a sample substitution map
- **WHEN** the output is opened in LibreOffice 24.x or newer
- **THEN** LibreOffice displays the document normally
- **AND** no recovery dialog appears

#### Scenario: Anonymised complete.odt fixture opens in Word without recovery

- **GIVEN** the same output
- **WHEN** the file is opened in Microsoft Word (Office 365 current channel)
- **THEN** Word displays the document normally
- **AND** no recovery dialog appears
