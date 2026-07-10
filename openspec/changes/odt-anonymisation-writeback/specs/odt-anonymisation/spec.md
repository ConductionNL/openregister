## ADDED Requirements

### Requirement: `.odt` inputs MUST NOT be redacted via the raw-text fallback or the lossy ODText reader

When `DocumentProcessingHandler::replaceWords()` processes a file whose extension is `odt` (`application/vnd.oasis.opendocument.text`), it MUST route to a dedicated in-place XML path (`replaceWordsInOdtDocument()`) and MUST NOT fall through to `replaceWordsInTextDocument()` (raw-byte `str_ireplace`) nor to `replaceWordsInWordDocument()` (PhpWord object-model roundtrip). Redaction MUST operate on the ODF XML parts, never on the ODT container's transport bytes. Rationale: PhpWord's ODText reader silently drops tables, headers, and footers on load, so an object-model roundtrip would destroy that content.

#### Scenario: ODT input is dispatched to the in-place XML path

- **WHEN** an `.odt` file is anonymised via `replaceWords()`
- **THEN** it is handled by `replaceWordsInOdtDocument()`
- **AND** neither the raw-byte `str_ireplace` fallback nor the PhpWord object-model path is used

#### Scenario: A compressed ODT is not returned byte-identical

- **GIVEN** an `.odt` whose `content.xml` is deflated and contains the entity text `Jan de Vries`
- **WHEN** the file is anonymised
- **THEN** the output `.odt` is NOT byte-identical to the input
- **AND** the entity text `Jan de Vries` does not survive in the redacted document text

### Requirement: Redaction MUST rewrite the ODF XML parts in place and preserve structure

The ODT path MUST redact by rewriting the ODF parts that carry visible text — `content.xml` (body, tables, lists) and `styles.xml` (page headers/footers) — leaving every other ZIP entry (mimetype, images, settings) untouched, so tables, headers, footers, and formatting are preserved. It MUST process each paragraph container (`text:p`, `text:h`) independently, and MUST replace an entity even when its text is split across multiple `<text:span>` runs within a paragraph. No new document-processing dependency may be introduced (PHP built-in `ZipArchive` + `DOMDocument`).

#### Scenario: Entity text is removed across paragraphs, tables, and headers/footers while structure survives

- **GIVEN** an `.odt` whose body paragraph, a table cell, a header, and a footer each contain entity values
- **WHEN** the file is anonymised
- **THEN** no entity value survives in the paragraph, table cell, header, or footer of the redacted output
- **AND** each occurrence is replaced by its `[<TYPE>: <id>]` placeholder
- **AND** the table structure and the header/footer content are still present in the output (not dropped)

#### Scenario: An entity split across text:span runs is redacted

- **GIVEN** a paragraph whose entity value is split across two `<text:span>` runs
- **WHEN** the file is anonymised
- **THEN** the concatenated paragraph text no longer contains the entity value
- **AND** the placeholder appears once and the paragraph's span structure is preserved

#### Scenario: DOCX and text paths are unchanged

- **WHEN** a `.docx` or plain-text file is anonymised
- **THEN** it is handled by its existing path (`replaceWordsInWordDocument()` / `replaceWordsInTextDocument()`), unchanged by this change

### Requirement: A post-write validation gate MUST fail loud when entity text survives or cannot be verified

After writing the redacted `.odt`, the path MUST re-extract the concatenated paragraph text of the written `content.xml` + `styles.xml` and MUST assert that each entity's original text is absent. If any entity's original text survives, or the output cannot be re-opened / is missing `content.xml` (redaction cannot be proven), the path MUST NOT report a clean success: it MUST record the affected entities via the existing best-effort residuals policy (`getLastResidualEntities()`, `{text,type,id}` record shape shared with the PDF path) so the caller can warn the operator. Under no circumstance may an unredacted, corrupt, or unverifiable ODT be reported as successfully anonymised.

#### Scenario: Clean redaction passes the gate

- **GIVEN** an `.odt` in which every detected entity was successfully replaced
- **WHEN** the validation gate re-extracts the written parts
- **THEN** no entity's original text is present
- **AND** `getLastResidualEntities()` is empty and the anonymised `.odt` is returned as a success

#### Scenario: A surviving entity is reported as a residual, not a silent success

- **GIVEN** a written `.odt` where an entity value still survives
- **WHEN** the validation gate re-extracts the output and finds the entity value present
- **THEN** the surviving entity is recorded in `getLastResidualEntities()` with its `{text,type,id}` record
- **AND** the result is NOT reported as a clean successful anonymisation

#### Scenario: An unreadable output fails loud

- **GIVEN** a written output that cannot be re-opened as an ODT container
- **WHEN** the validation gate attempts re-extraction
- **THEN** every entity is recorded as a residual (redaction cannot be proven)
- **AND** the result is NOT reported as a clean successful anonymisation
