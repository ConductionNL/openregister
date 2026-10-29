---
status: done
---

# text-extraction-word Specification

## Purpose
Extracts complete text from Word-family documents (DOCX, legacy DOC, ODT), including table cells and nested tables, section headers and footers, and footnotes and endnotes. DOCX and ODT are read by `WordTextReader` straight from the package XML with ZipArchive and DOM. A legacy binary DOC is converted by the office server through Nextcloud's conversion API (to DOCX, read the same way, or else to plain text); without an office server that converts it, it gives null. The format is selected from the input MIME or extension. Nesting depth is bounded by the XML parser, reader or load failures return null gracefully rather than aborting, and failure logs never contain document content.

@e2e exclude Backend PHP text-extraction service (package reader for DOCX/ODT tables, headers, footers, foot/endnotes, tracked changes, reader selection, failure logging). Pure server-side document processing with no user-facing OpenRegister UI surface — exercised via PHPUnit fixture-document units. Covered by PHPUnit.

## Requirements
### Requirement: Word extraction MUST capture table cell text including nested tables

The reader MUST descend tables row by row and cell by cell, for DOCX (`w:tbl`) and ODT (`table:table`) alike. Because a cell may itself hold a table, **nested tables** MUST be captured by the same path. Table cell text MUST appear in the flat output, with a line break after every row.

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

For DOCX the reader MUST read every header and footer part the main document references; for ODT it MUST read the master-page headers and footers in `styles.xml`. Header and footer text, including text inside tables in headers and footers, MUST appear in the flat output.

#### Scenario: Header text is extracted

- **GIVEN** a DOCX document with a section header containing "Gemeente Voorbeeld — Concept"
- **WHEN** `extractWord` runs
- **THEN** the flat output contains "Gemeente Voorbeeld — Concept"

#### Scenario: Footer text is extracted

- **GIVEN** a DOCX document with a section footer containing "Pagina 1 van 3"
- **WHEN** `extractWord` runs
- **THEN** the flat output contains "Pagina 1 van 3"

### Requirement: Word extraction MUST capture footnote and endnote text

The reader MUST capture footnote and endnote text: for DOCX from the footnotes and endnotes parts (without the separator notes), for ODT from the inline `text:note` bodies. Each note's text MUST appear in the flat output once.

#### Scenario: Footnote text is extracted

- **GIVEN** a DOCX document with a footnote whose text is "Conform artikel 5.1 Woo"
- **WHEN** `extractWord` runs
- **THEN** the flat output contains "Conform artikel 5.1 Woo"

#### Scenario: Endnote text is extracted

- **GIVEN** a DOCX document with an endnote whose text is "Zie bijlage II"
- **WHEN** `extractWord` runs
- **THEN** the flat output contains "Zie bijlage II"

### Requirement: Nesting depth MUST be bounded

The reader walks the parsed XML tree recursively, so nesting depth MUST be bounded before the walk. Package parts MUST be parsed without `LIBXML_PARSEHUGE`, so libxml refuses a part nested deeper than its limit (256 levels); such a part is treated as unreadable.

#### Scenario: Pathologically deep nesting does not crash extraction

- **GIVEN** a DOCX document whose elements are nested beyond the parser's depth limit
- **WHEN** `extractWord` runs
- **THEN** extraction completes without an unhandled error and returns null

### Requirement: Deleted tracked changes and comments MUST NOT be read as document text

Text a tracked change deleted (`w:delText` in DOCX, the `text:tracked-changes` records in ODT) and comment or annotation text MUST NOT appear in the flat output; inserted tracked-change text MUST. A word split over several runs MUST come out as one word.

#### Scenario: Only the current text of a tracked change is read

- **GIVEN** a DOCX document where a tracked change replaced "12.000" by "15.000" in "Het bedrag is 15.000 euro."
- **WHEN** `extractWord` runs
- **THEN** the flat output contains "Het bedrag is 15.000 euro."
- **AND** it does not contain "12.000"

### Requirement: The reader MUST be selected from the input MIME / extension

`extractWord()` MUST select the format explicitly. The mapping MUST be:

- `application/vnd.openxmlformats-officedocument.wordprocessingml.document` (`.docx`) → `Word2007`
- `application/msword` (`.doc`) → `MsDoc`
- `application/vnd.oasis.opendocument.text` (`.odt`) → `ODText`

When the MIME is generic or ambiguous, the reader MAY be selected from the file extension; the final fallback MUST be `Word2007`. `Word2007` and `ODText` are read by `WordTextReader`; `MsDoc` is converted by the office server when one can, and is otherwise not read.

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

`isWordDocument()` MUST recognise `application/vnd.oasis.opendocument.text` in addition to the existing `application/vnd.openxmlformats-officedocument.wordprocessingml.document` and `application/msword`, so that ODT inputs dispatch to `extractWord()` in `performTextExtraction()`. `application/msword` MUST continue to route to `extractWord()`, which converts it through the office server, or returns null and logs that legacy DOC is not supported when no provider converts it.

#### Scenario: ODT MIME is recognised as a Word document

- **WHEN** `isWordDocument('application/vnd.oasis.opendocument.text')` is evaluated
- **THEN** it returns true

#### Scenario: ODT input is dispatched to Word extraction and produces text

- **GIVEN** an ODT file (mime `application/vnd.oasis.opendocument.text`) with a paragraph "Hallo wereld"
- **WHEN** `TextExtractionService::extractFile($fileId)` is called
- **THEN** the persisted extracted-text contains "Hallo wereld"

#### Scenario: DOC input is converted by the office server

- **GIVEN** a legacy `.doc` file (mime `application/msword`) containing "Oud document", and an office server that converts DOC to DOCX
- **WHEN** `extractWord` runs
- **THEN** the DOC is converted to DOCX and the returned text contains "Oud document"

#### Scenario: DOC input without an office server

- **GIVEN** a legacy `.doc` file and no provider that converts DOC
- **WHEN** `extractWord` runs
- **THEN** it returns null and logs, without document content, that legacy DOC is not supported

### Requirement: Reader / load failure MUST return null gracefully, not throw fatally

When a document cannot be read (a corrupt package, a failed DOC conversion, or a DOC without an office server) or the read produces no text, `extractWord()` MUST log the failure and return null. It MUST NOT propagate a fatal error for an unsupported per-document input; the surrounding pipeline treats null as "unsupported/empty" and continues.

#### Scenario: Unreadable package returns null without aborting

- **GIVEN** a `.docx` file that is not a valid package
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

### Requirement: The change MUST NOT reduce DOCX output content nor affect non-Word files

DOCX extraction MUST keep all body, table, header, footer and note text the prior traversal produced; only deleted tracked-change text is dropped (see above). All other MIME branches in `TextExtractionService` (PDF, spreadsheet, plain text, EML) MUST behave identically before and after this change; the Word changes are additive and orthogonal.

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

