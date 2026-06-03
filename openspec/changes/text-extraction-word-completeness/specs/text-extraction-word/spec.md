---
status: draft
---

# Text Extraction — Word

## Purpose

Defines Word-family text extraction completeness in OpenRegister's `TextExtractionService`. Covers two concerns: (1) extracting ALL body text from Word documents — table cells (including nested tables), text runs, list items, section headers and footers, and footnotes/endnotes — via a recursive element walker, replacing the prior 2-level traversal that silently dropped table and header/footer content; and (2) supporting legacy `.doc` (PhpWord `MsDoc` reader) and OpenDocument `.odt` (`ODText` reader) alongside `.docx` (`Word2007`) by selecting the reader from the input MIME / extension, with graceful null fallback when a reader cannot parse the input. Scope is the Word family only; the broader `TextExtractionService` surface (PDF, spreadsheet, EML) is not covered by this capability.

## ADDED Requirements

### Requirement: Word extraction MUST capture table cell text including nested tables

The recursive element walker in `extractWord()` MUST descend PhpWord `Table` elements via `getRows()` → each `Row::getCells()` → each `Cell::getElements()`, and MUST recurse each cell element. Because a cell element may itself be a `Table`, **nested tables** MUST be captured by the same path. Table cell text MUST appear in the flat output. (Prior behaviour dropped all table content because a `Table` element does not expose `getElements()`.)

#### Scenario: Single-level table cell text is extracted

- **GIVEN** a DOCX document whose body contains a table with cells "Aanvrager", "BSN", "J. de Vries", "123456782"
- **WHEN** `extractWord` runs
- **THEN** the flat output contains the text of every cell ("Aanvrager", "BSN", "J. de Vries", "123456782")

#### Scenario: Nested table cell text is extracted

- **GIVEN** a DOCX document containing a table whose cell contains another (nested) table with cell text "Detail-A"
- **WHEN** `extractWord` runs
- **THEN** the flat output contains "Detail-A"

#### Scenario: TextRun and list-item text inside cells is extracted

- **GIVEN** a DOCX document with a table cell containing a `TextRun` ("samengesteld blok") and a list item ("punt 1")
- **WHEN** `extractWord` runs
- **THEN** the flat output contains both "samengesteld blok" and "punt 1"

### Requirement: Word extraction MUST capture section header and footer text

For each section, `extractWord()` MUST walk `$section->getHeaders()` and `$section->getFooters()` (each header/footer exposes `getElements()`) using the same recursive walker as the body, so header and footer text — including text inside tables in headers/footers — appears in the flat output.

#### Scenario: Header text is extracted

- **GIVEN** a DOCX document with a section header containing "Gemeente Voorbeeld — Concept"
- **WHEN** `extractWord` runs
- **THEN** the flat output contains "Gemeente Voorbeeld — Concept"

#### Scenario: Footer text is extracted

- **GIVEN** a DOCX document with a section footer containing "Pagina 1 van 3"
- **WHEN** `extractWord` runs
- **THEN** the flat output contains "Pagina 1 van 3"

### Requirement: Word extraction MUST capture footnote and endnote text

`extractWord()` MUST capture footnote and endnote text via BOTH paths: inline `Footnote`/`Endnote` elements walked within text runs, AND an unconditional iteration of the document-level notes collection. The text content of footnotes and endnotes MUST appear in the flat output.

#### Scenario: Footnote text is extracted

- **GIVEN** a DOCX document with a footnote whose text is "Conform artikel 5.1 Woo"
- **WHEN** `extractWord` runs
- **THEN** the flat output contains "Conform artikel 5.1 Woo"

#### Scenario: Endnote text is extracted

- **GIVEN** a DOCX document with an endnote whose text is "Zie bijlage II"
- **WHEN** `extractWord` runs
- **THEN** the flat output contains "Zie bijlage II"

### Requirement: The recursive walker MUST be depth-guarded

The recursive element walker MUST accept a depth parameter and MUST stop descending once a fixed maximum depth (`MAX_WORD_DEPTH`, far above realistic document nesting) is exceeded, to defend against pathological or malicious nesting. When the cap is hit, the walker MUST stop descending the over-deep branch and MUST emit a debug-level log noting the cap; it MUST NOT raise an error or abort the overall extraction.

#### Scenario: Pathologically deep nesting does not crash extraction

- **GIVEN** a DOCX document whose containers are nested far beyond the depth cap
- **WHEN** `extractWord` runs
- **THEN** extraction completes without an unhandled error
- **AND** a debug-level log entry notes the depth-cap activation
- **AND** text above the cap is still present in the output

### Requirement: The reader MUST be selected from the input MIME / extension

`extractWord()` MUST select the PhpWord reader explicitly rather than relying on the `Word2007` default. The mapping MUST be:

- `application/vnd.openxmlformats-officedocument.wordprocessingml.document` (`.docx`) → `Word2007`
- `application/msword` (`.doc`) → `MsDoc`
- `application/vnd.oasis.opendocument.text` (`.odt`) → `ODText`

When the MIME is generic or ambiguous, the reader MAY be selected from the file extension; the final fallback MUST be `Word2007`. The selected reader name MUST be passed to `IOFactory::load()`.

#### Scenario: DOCX selects the Word2007 reader

- **GIVEN** an input with MIME `application/vnd.openxmlformats-officedocument.wordprocessingml.document`
- **WHEN** `extractWord` selects the reader
- **THEN** the `Word2007` reader is used

#### Scenario: DOC selects the MsDoc reader

- **GIVEN** an input with MIME `application/msword`
- **WHEN** `extractWord` selects the reader
- **THEN** the `MsDoc` reader is used

#### Scenario: ODT selects the ODText reader

- **GIVEN** an input with MIME `application/vnd.oasis.opendocument.text`
- **WHEN** `extractWord` selects the reader
- **THEN** the `ODText` reader is used

### Requirement: ODT and DOC MUST be routed through the Word branch

`isWordDocument()` MUST recognise `application/vnd.oasis.opendocument.text` in addition to the existing `application/vnd.openxmlformats-officedocument.wordprocessingml.document` and `application/msword`, so that ODT inputs dispatch to `extractWord()` in `performTextExtraction()`. `application/msword` MUST continue to route to `extractWord()` and MUST now actually load (via the `MsDoc` reader).

#### Scenario: ODT MIME is recognised as a Word document

- **WHEN** `isWordDocument('application/vnd.oasis.opendocument.text')` is evaluated
- **THEN** it returns true

#### Scenario: ODT input is dispatched to Word extraction and produces text

- **GIVEN** an ODT file (mime `application/vnd.oasis.opendocument.text`) with a paragraph "Hallo wereld"
- **WHEN** `TextExtractionService::extractFile($fileId)` is called
- **THEN** the persisted extracted-text contains "Hallo wereld"

#### Scenario: DOC input loads via MsDoc instead of failing on the Word2007 default

- **GIVEN** a legacy `.doc` file (mime `application/msword`) that the `MsDoc` reader can parse, containing the text "Oud document"
- **WHEN** `TextExtractionService::extractFile($fileId)` is called
- **THEN** the persisted extracted-text contains "Oud document"

### Requirement: Reader / load failure MUST return null gracefully, not throw fatally

When `IOFactory::load()` raises an exception (notably the binary `MsDoc` reader's known limitations on documents it cannot parse) or the walk produces no text, `extractWord()` MUST log the failure and return null. It MUST NOT propagate a fatal error for an unsupported/limited per-document input; the surrounding pipeline treats null as "unsupported/empty" and continues. The existing "PhpWord library not installed" guard (class missing) MAY still throw, because that is a deployment error rather than a per-document failure.

#### Scenario: Unparseable DOC returns null without aborting

- **GIVEN** a `.doc` file the `MsDoc` reader cannot parse
- **WHEN** `extractWord` runs
- **THEN** the method returns null
- **AND** the failure is logged
- **AND** no exception propagates to abort the extraction run

#### Scenario: Empty document returns null

- **GIVEN** a valid but text-empty Word document
- **WHEN** `extractWord` runs
- **THEN** the method returns null

### Requirement: Failure logs MUST NOT contain document content (ADR-005)

Per ADR-005 ("NO PII in logs, error responses, or debug output"), log lines and exception messages emitted by `extractWord()` and its helpers MUST NOT include extracted document text, table cell values, header/footer content, or note content. Permitted log content is restricted to structural information: the Nextcloud file ID, the MIME type, the selected reader name, the exception class name, and depth-cap structural detail.

#### Scenario: Reader-failure log contains no document content

- **GIVEN** a Word document with body text "Vertrouwelijk: J. de Vries, BSN 123456782" that fails to parse
- **WHEN** `extractWord` logs the failure
- **THEN** the log entry MUST NOT contain "Vertrouwelijk", "J. de Vries", or "123456782"
- **AND** the log entry MAY contain the file ID, the MIME type, the reader name, and the exception class name

### Requirement: The change MUST NOT reduce or alter DOCX output content nor affect non-Word files

DOCX extraction MUST return a strict superset of its prior content — all text the prior 2-level traversal produced MUST still be present, with table, header/footer, and note text added. All other MIME branches in `TextExtractionService` (PDF, spreadsheet, plain text, EML) MUST behave identically before and after this change; the Word changes are additive and orthogonal.

#### Scenario: DOCX body paragraph text is still extracted

- **GIVEN** a DOCX document with a plain body paragraph "Onderwerp: Besluit"
- **WHEN** `extractWord` runs after this change is applied
- **THEN** the flat output still contains "Onderwerp: Besluit"

#### Scenario: PDF extraction unchanged

- **GIVEN** a PDF file
- **WHEN** `extractFile` runs after this change is applied
- **THEN** the extracted text is identical to what the pre-change code produced for the same file

#### Scenario: Spreadsheet extraction unchanged

- **GIVEN** an XLSX file
- **WHEN** `extractFile` runs after this change is applied
- **THEN** the extracted text is identical to pre-change behaviour
