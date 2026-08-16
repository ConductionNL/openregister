---
status: done
---

# office-document-sanitization Specification

## Purpose
Sanitises DOCX and ODT documents during anonymisation by removing hidden and identifying content without mutating the original file. Strips comments and tracked changes (accepting inserts, dropping deletions), custom-XML data bindings, person-identity field codes, and hyperlink URLs, and replaces metadata fields with a `DocuDesk Anonymisation` sentinel while preserving timestamps and visible text. Produces a PII-free `SanitizationReport` persisted on the anonymisation log, guarantees the output opens cleanly in Word and LibreOffice, and raises a typed `SanitizationException` for encrypted or unsupported inputs.
## Requirements
### Requirement: `OfficeDocumentSanitizer` MUST sanitise DOCX and ODT inputs without mutating the original file

The orchestrator service `OCA\OpenRegister\Service\File\OfficeDocumentSanitizer` MUST expose a `sanitize(int $fileId): SanitizationResult` method. The method MUST:

- Resolve the file via `IRootFolder` (or equivalent NC file resolution).
- Copy the file's bytes to a temporary path obtained from `ITempManager`.
- Select a format-specific strategy (`DocxSanitizer` for DOCX MIME, `OdtSanitizer` for ODT MIME).
- Invoke the strategy on the temp copy.
- Return a `SanitizationResult` value object carrying the sanitised file's temp path AND the `SanitizationReport`.
- Leave the original file (in NC Files storage) byte-identical to its pre-call state.

#### Scenario: DOCX with comments and tracked changes produces a sanitised temp file

- **GIVEN** a `.docx` file in NC Files containing 5 comments and 3 tracked-change groups
- **WHEN** `OfficeDocumentSanitizer::sanitize($fileId)` is called
- **THEN** the returned `SanitizationResult.path` points to a temp file that opens cleanly in Word and LibreOffice
- **AND** the temp file contains 0 comments and no tracked-change markup
- **AND** the original NC file is byte-identical to its state before the call
- **AND** the returned `SanitizationResult.report.commentsRemoved` equals 5
- **AND** the returned `SanitizationResult.report.trackedChangesAccepted + trackedChangesDropped` equals 3

#### Scenario: ODT input dispatches to OdtSanitizer

- **GIVEN** a `.odt` file in NC Files (MIME `application/vnd.oasis.opendocument.text`)
- **WHEN** `OfficeDocumentSanitizer::sanitize($fileId)` is called
- **THEN** the file is dispatched to `OdtSanitizer` (not `DocxSanitizer`)
- **AND** the returned `SanitizationResult.path` points to a sanitised `.odt`
- **AND** the file opens cleanly in LibreOffice and Microsoft Word

#### Scenario: Unsupported MIME raises SanitizationException

- **GIVEN** a file whose MIME is neither DOCX nor ODT (e.g. `application/pdf`)
- **WHEN** `OfficeDocumentSanitizer::sanitize($fileId)` is called
- **THEN** `SanitizationException` is thrown
- **AND** the exception message identifies the unsupported MIME type
- **AND** no temp file is created (or any created file is cleaned up before the exception propagates)

### Requirement: `DocxSanitizer` MUST remove all comments and inline comment references from a `.docx`

The DOCX strategy MUST remove from the ZIP container:

- The parts `word/comments.xml`, `word/commentsExtended.xml`, `word/commentsIds.xml`, and `word/people.xml` if present.
- Their entries in `[Content_Types].xml` (matching `<Override PartName="/word/comments.xml" ...>` etc.).
- Their entries in `word/_rels/document.xml.rels` (matching the relationship whose target is the removed part).

Additionally, the sanitiser MUST remove inline comment-reference nodes from `word/document.xml`, all `word/header*.xml`, all `word/footer*.xml`, `word/footnotes.xml`, and `word/endnotes.xml`:

- `<w:commentRangeStart>`
- `<w:commentRangeEnd>`
- `<w:commentReference>`

The `SanitizationReport.commentsRemoved` counter MUST equal the number of distinct comments removed (counted once per comment, not once per inline reference).

#### Scenario: Sanitised DOCX has no comments part

- **GIVEN** a DOCX with `word/comments.xml` containing 3 comments
- **WHEN** `DocxSanitizer::sanitize` runs
- **THEN** the sanitised ZIP MUST NOT contain `word/comments.xml`
- **AND** `[Content_Types].xml` MUST NOT contain an `Override` entry for `/word/comments.xml`
- **AND** `word/_rels/document.xml.rels` MUST NOT contain a `Relationship` whose `Target` is `comments.xml`
- **AND** the report's `commentsRemoved` equals 3

#### Scenario: Inline comment references removed from headers and footers

- **GIVEN** a DOCX with a comment anchored to text inside `word/header1.xml`
- **WHEN** sanitisation runs
- **THEN** `word/header1.xml` in the output MUST NOT contain any `<w:commentRangeStart>`, `<w:commentRangeEnd>`, or `<w:commentReference>` elements
- **AND** the visible header text (the runs that were inside the comment range) is preserved

### Requirement: `DocxSanitizer` MUST accept all tracked changes (keep inserts, drop deletions)

The sanitiser MUST process all parts in the ZIP that can contain track-change markup (`word/document.xml`, headers, footers, footnotes, endnotes, comments — though comments are removed entirely per the previous Requirement):

- Every `<w:ins>...</w:ins>` element MUST be unwrapped: its inner content is preserved, the wrapper element is removed.
- Every `<w:del>...</w:del>` element MUST be removed entirely, including its inner content.
- Revision attributes (`w:rsidR`, `w:rsidRPr`, `w:rsidDel`, `w:rsidTr`, `w:rsidP`) on runs, paragraphs, table rows, and cells MUST be stripped.

The `SanitizationReport.trackedChangesAccepted` counter MUST equal the count of `<w:ins>` groups unwrapped; `trackedChangesDropped` MUST equal the count of `<w:del>` groups removed; `revisionAttributesStripped` MUST equal the total count of revision attributes stripped.

#### Scenario: Accepted insert preserves the inserted text

- **GIVEN** a DOCX with a paragraph containing `<w:ins><w:r><w:t>nieuw bewijs</w:t></w:r></w:ins>`
- **WHEN** sanitisation runs
- **THEN** the sanitised document contains the text "nieuw bewijs" in that paragraph
- **AND** the `<w:ins>` wrapper is gone
- **AND** the report's `trackedChangesAccepted` is incremented by 1

#### Scenario: Dropped deletion removes the deleted text

- **GIVEN** a DOCX with `<w:del><w:r><w:delText>oude tekst</w:delText></w:r></w:del>` in a paragraph
- **WHEN** sanitisation runs
- **THEN** the text "oude tekst" MUST NOT appear in the sanitised document
- **AND** the `<w:del>` element MUST NOT appear in the sanitised XML
- **AND** the report's `trackedChangesDropped` is incremented by 1

#### Scenario: Revision attributes stripped from runs and paragraphs

- **GIVEN** a DOCX paragraph `<w:p w:rsidR="0001ABC" w:rsidP="0001DEF"><w:r w:rsidRPr="0002ABC">...</w:r></w:p>`
- **WHEN** sanitisation runs
- **THEN** the sanitised paragraph element MUST NOT have `w:rsidR` or `w:rsidP` attributes
- **AND** the sanitised run element MUST NOT have `w:rsidRPr`
- **AND** the visible content is preserved

### Requirement: `DocxSanitizer` MUST strip all `customXml/*` parts and unwrap their data-bound content controls

The sanitiser MUST remove every part whose path matches `customXml/item*.xml` or `customXml/itemProps*.xml` from the ZIP, along with the corresponding entries in `[Content_Types].xml` and any `_rels/*.rels` file referencing them.

In `word/document.xml` and all body-bearing parts (headers, footers, footnotes, endnotes), every `<w:sdt>` element containing a `<w:sdtPr><w:dataBinding ...>` child MUST be unwrapped: the `<w:sdt>` wrapper is replaced by the children of its `<w:sdtContent>` child (visible content preserved); the `<w:sdtPr>` block is discarded.

The `SanitizationReport.customXmlPartsDropped` counter MUST equal the number of custom-XML item parts removed.

#### Scenario: Custom XML parts and their references are removed

- **GIVEN** a DOCX with `customXml/item1.xml` and `customXml/itemProps1.xml`
- **AND** `[Content_Types].xml` containing `Override` entries for both
- **AND** `word/_rels/document.xml.rels` containing a relationship targeting `customXml/item1.xml`
- **WHEN** sanitisation runs
- **THEN** neither `customXml/item1.xml` nor `customXml/itemProps1.xml` appears in the sanitised ZIP
- **AND** the `Override` entries for both are removed from `[Content_Types].xml`
- **AND** the matching `Relationship` is removed from `word/_rels/document.xml.rels`
- **AND** the report's `customXmlPartsDropped` is 1 (counting `item1.xml` as one logical part with its props)

#### Scenario: Data-bound content control preserves visible text

- **GIVEN** a DOCX with `<w:sdt><w:sdtPr><w:dataBinding w:xpath="/root/name" /></w:sdtPr><w:sdtContent><w:r><w:t>Jan de Vries</w:t></w:r></w:sdtContent></w:sdt>`
- **WHEN** sanitisation runs
- **THEN** the sanitised body contains the text "Jan de Vries" (visible content preserved)
- **AND** the `<w:sdt>`, `<w:sdtPr>`, and `<w:sdtContent>` elements are gone (unwrapped)

### Requirement: `DocxSanitizer` MUST replace document metadata fields with the sanitisation sentinel

In `docProps/core.xml`, the following elements MUST have their text content replaced with the string `DocuDesk Anonymisation`:

- `dc:creator`
- `cp:lastModifiedBy`
- `dc:title`
- `dc:subject`
- `cp:keywords`
- `dc:description`
- `cp:category`
- `cp:contentStatus`

In `docProps/app.xml`, the following elements MUST have their text content replaced with `DocuDesk Anonymisation`:

- `Company`
- `Manager`

In `docProps/custom.xml`, every `<property>` whose value type is `<vt:lpwstr>` or `<vt:lpstr>` MUST have its inner string replaced with `DocuDesk Anonymisation`. Non-string-typed custom properties (`<vt:i4>`, `<vt:bool>`, `<vt:filetime>`, etc.) MUST be preserved unchanged.

The element MUST be preserved structurally — Word and LibreOffice MUST see a well-formed `<dc:creator>DocuDesk Anonymisation</dc:creator>` rather than a missing element. Timestamp fields (`dcterms:created`, `dcterms:modified`) MUST be preserved unchanged (they do not carry PII).

The `SanitizationReport.metadataFieldsScrubbed` counter MUST equal the count of metadata elements whose values were replaced; `SanitizationReport.sentinelApplied` MUST equal the sentinel string used.

#### Scenario: Author and Title scrubbed to sentinel

- **GIVEN** a DOCX with `docProps/core.xml` containing `<dc:creator>Robert Zondervan</dc:creator><dc:title>Confidentieel: Woo-verzoek 2026-017</dc:title>`
- **WHEN** sanitisation runs
- **THEN** `docProps/core.xml` in the sanitised ZIP contains `<dc:creator>DocuDesk Anonymisation</dc:creator>`
- **AND** `<dc:title>DocuDesk Anonymisation</dc:title>`
- **AND** the report's `metadataFieldsScrubbed` is at least 2
- **AND** the report's `sentinelApplied` equals `DocuDesk Anonymisation`

#### Scenario: Timestamps are preserved

- **GIVEN** a DOCX with `<dcterms:created xsi:type="dcterms:W3CDTF">2025-11-20T10:14:00Z</dcterms:created>`
- **WHEN** sanitisation runs
- **THEN** the `<dcterms:created>` element retains its original value `2025-11-20T10:14:00Z`

#### Scenario: Non-string custom property preserved

- **GIVEN** a DOCX with `docProps/custom.xml` containing `<property fmtid="..." pid="2" name="DocumentVersion"><vt:i4>3</vt:i4></property>`
- **WHEN** sanitisation runs
- **THEN** the property's `<vt:i4>3</vt:i4>` is preserved unchanged (not replaced with a string sentinel that would break type expectations)

### Requirement: `DocxSanitizer` MUST strip person-identity field codes (wrapper + cached result)

The sanitiser MUST identify and remove fields whose instruction (`w:instr` attribute on `<w:fldSimple>` or `<w:instrText>` content in the multi-element form) matches any of (case-insensitive, whitespace-tolerant):

- `AUTHOR`
- `USERNAME`
- `USERINITIALS`
- `LASTSAVEDBY`

For the simple form `<w:fldSimple w:instr=" AUTHOR ">...inner runs...</w:fldSimple>`: the entire element including inner runs MUST be removed.

For the complex form (`<w:fldChar w:fldCharType="begin"/>` → `<w:instrText>` → `<w:fldChar w:fldCharType="separate"/>` → cached result runs → `<w:fldChar w:fldCharType="end"/>`): all runs from begin to end inclusive MUST be removed.

Fields whose instruction matches anything OTHER than the strip list (e.g. `DATE`, `TIME`, `PAGE`, `NUMPAGES`, `FILENAME`, `TITLE`, `REF`) MUST be preserved.

The `SanitizationReport.fieldCodesStripped` counter MUST equal the count of fields removed (counted by field, not by element).

#### Scenario: Simple AUTHOR field is removed with its cached value

- **GIVEN** a DOCX with `<w:fldSimple w:instr=" AUTHOR "><w:r><w:t>Robert Zondervan</w:t></w:r></w:fldSimple>`
- **WHEN** sanitisation runs
- **THEN** the text "Robert Zondervan" MUST NOT appear in the sanitised document
- **AND** the `<w:fldSimple>` wrapper is gone
- **AND** the report's `fieldCodesStripped` is incremented by 1

#### Scenario: Complex USERNAME field is removed end-to-end

- **GIVEN** a DOCX with the complex-form field sequence for `USERNAME` (begin → instrText `" USERNAME "` → separate → cached `Jane Doe` → end)
- **WHEN** sanitisation runs
- **THEN** all five runs of the sequence are gone
- **AND** the text "Jane Doe" MUST NOT appear in the sanitised document

#### Scenario: DATE field is preserved

- **GIVEN** a DOCX with `<w:fldSimple w:instr=" DATE \@ &quot;yyyy-MM-dd&quot; "><w:r><w:t>2026-05-18</w:t></w:r></w:fldSimple>`
- **WHEN** sanitisation runs
- **THEN** the `<w:fldSimple>` element is preserved unchanged
- **AND** the report's `fieldCodesStripped` is NOT incremented for this field

### Requirement: `DocxSanitizer` MUST flatten hyperlinks (drop URL + relationship, keep visible text)

The sanitiser MUST process every `<w:hyperlink>` element in `word/document.xml`, all `word/header*.xml`, all `word/footer*.xml`, `word/footnotes.xml`, and `word/endnotes.xml`:

- The `<w:hyperlink>` element MUST be replaced by its inner `<w:r>` (run) children. Inner content is preserved.
- If the hyperlink has an `r:id` attribute, the corresponding `<Relationship>` element MUST be removed from the rels file paired with the part (`word/_rels/document.xml.rels` for document.xml; `word/_rels/header1.xml.rels` for header1.xml; etc.).
- Internal-anchor hyperlinks (those with `w:anchor` but no `r:id`) are still flattened — the wrapper goes, content stays. There is no rels entry to remove for internal anchors.

The `SanitizationReport.hyperlinksFlattened` counter MUST equal the count of `<w:hyperlink>` elements flattened.

#### Scenario: External hyperlink is flattened and its rels entry removed

- **GIVEN** a DOCX with `<w:hyperlink r:id="rId7"><w:r><w:t>contact</w:t></w:r></w:hyperlink>`
- **AND** `word/_rels/document.xml.rels` containing `<Relationship Id="rId7" Type="...hyperlink" Target="mailto:p.jansen@example.com" TargetMode="External"/>`
- **WHEN** sanitisation runs
- **THEN** the sanitised document contains the text "contact" (visible text preserved)
- **AND** no `<w:hyperlink>` element remains in document.xml
- **AND** the `Relationship` element with `Id="rId7"` MUST NOT appear in `word/_rels/document.xml.rels`
- **AND** the report's `hyperlinksFlattened` is incremented by 1

#### Scenario: Internal anchor hyperlink is flattened

- **GIVEN** a DOCX with `<w:hyperlink w:anchor="_Toc12345"><w:r><w:t>Zie hoofdstuk 4</w:t></w:r></w:hyperlink>`
- **WHEN** sanitisation runs
- **THEN** the wrapper is gone; the text "Zie hoofdstuk 4" remains
- **AND** the report's `hyperlinksFlattened` is incremented by 1

### Requirement: `OdtSanitizer` MUST sanitise `.odt` files in parity with `DocxSanitizer`

The ODT strategy MUST remove or scrub the ODT-equivalent structures inside `content.xml`, `styles.xml`, and `meta.xml`:

- **Comments:** every `office:annotation` and `office:annotation-end` element removed entirely (their inline text content goes with them).
- **Tracked changes:** the `text:tracked-changes` container in `content.xml` parsed for accept/reject mapping; then accept-all is applied — `text:change-start` and `text:change-end` markers around inserted ranges are removed but inner content kept; ranges covered by deletion markers are removed entirely. After processing, the `text:tracked-changes` container element itself is removed.
- **Metadata:** in `meta.xml`, the elements `dc:creator`, `meta:initial-creator`, `dc:title`, `dc:subject`, `meta:keyword`, and `dc:description` have their text content replaced with `DocuDesk Anonymisation`. Every `meta:user-defined` element MUST have its text content replaced with `DocuDesk Anonymisation` unless its `meta:value-type` attribute is non-string (e.g. `boolean`, `date`, `float`), in which case the element is preserved unchanged.
- **Hyperlinks:** every `text:a` element flattened — wrapper removed, inner content preserved.
- **Person-identity placeholders:** every `text:author-name`, `text:author-initials`, and `text:initial-creator` element removed entirely.

The same `SanitizationReport` shape MUST be produced.

#### Scenario: ODT comments removed

- **GIVEN** an ODT containing 4 `office:annotation` elements in `content.xml`
- **WHEN** `OdtSanitizer::sanitize` runs
- **THEN** the sanitised `content.xml` contains 0 `office:annotation` elements
- **AND** the report's `commentsRemoved` equals 4

#### Scenario: ODT metadata scrubbed to sentinel

- **GIVEN** an ODT with `<dc:creator>S. de Vries</dc:creator><dc:title>Notitie burgemeester</dc:title>` in `meta.xml`
- **WHEN** `OdtSanitizer::sanitize` runs
- **THEN** the sanitised `meta.xml` contains `<dc:creator>DocuDesk Anonymisation</dc:creator>`
- **AND** `<dc:title>DocuDesk Anonymisation</dc:title>`

#### Scenario: ODT hyperlink flattened

- **GIVEN** an ODT with `<text:a xlink:href="mailto:p.jansen@example.com">contact</text:a>` inside a paragraph
- **WHEN** sanitisation runs
- **THEN** the paragraph contains the text "contact" (preserved)
- **AND** no `text:a` element remains
- **AND** no `xlink:href` referencing the mailto URL is anywhere in the sanitised document

#### Scenario: ODT author placeholder removed

- **GIVEN** an ODT containing `<text:p>Opgesteld door <text:author-name>S. de Vries</text:author-name> op <text:date/></text:p>`
- **WHEN** sanitisation runs
- **THEN** the `<text:author-name>` element is gone (along with the cached "S. de Vries" content)
- **AND** the `<text:date>` element is preserved (it's not in the strip list)
- **AND** surrounding text "Opgesteld door" and "op" is preserved

### Requirement: Sanitised output MUST open cleanly in Microsoft Word and LibreOffice

For every sanitiser implementation, the output file MUST be openable in both:

- Microsoft Word (Office 365 current channel OR Office LTSC current branch)
- LibreOffice (24.x or newer)

without the application displaying a "found unreadable content / want to recover?" recovery dialog. Visible content MUST match the pre-sanitisation document minus the structures listed in this spec.

The validation MUST be performed against a test fixture document containing at minimum: 3 comments, 2 accepted-insert track-change groups, 2 dropped-delete track-change groups, 2 custom XML parts with bound content controls, 1 external hyperlink, 1 AUTHOR field, populated `dc:creator` and `dc:title` metadata.

The validation MUST be performed before a sanitiser implementation is marked done in tasks.md.

#### Scenario: Word opens the sanitised file without recovery prompt

- **GIVEN** a sanitised DOCX produced from the validation fixture
- **WHEN** the file is opened in Microsoft Word (current channel)
- **THEN** Word displays the document normally
- **AND** no recovery dialog appears
- **AND** no "compatibility mode" badge appears that did not also appear on the unsanitised fixture

#### Scenario: LibreOffice opens the sanitised file without recovery prompt

- **GIVEN** the same sanitised DOCX
- **WHEN** the file is opened in LibreOffice 24.x or newer
- **THEN** LibreOffice displays the document normally
- **AND** no recovery dialog appears

### Requirement: Sanitisation report MUST be persisted on the anonymisation log

After `DocumentProcessingHandler::anonymizeDocument` invokes `OfficeDocumentSanitizer::sanitize`, the resulting `SanitizationReport` (serialised as JSON) MUST be written to the `sanitization` column on the anonymisation log row that records the anonymisation operation. The column MUST be of JSON type, nullable, with `null` as the default.

Anonymisations performed without sanitisation (legacy rows OR non-Office formats) MUST have `sanitization = null`. Consumers (DocuDesk's report renderer) MUST interpret `null` as "no sanitisation data; pre-sanitisation run or non-applicable format" and MUST NOT raise an error.

#### Scenario: Office anonymisation populates the sanitization column

- **GIVEN** a DOCX file is anonymised via `POST /api/objects/{id}/anonymize`
- **WHEN** the request completes successfully
- **THEN** the corresponding `AnonymizationLog` row's `sanitization` column contains a JSON object with the keys: `commentsRemoved`, `trackedChangesAccepted`, `trackedChangesDropped`, `revisionAttributesStripped`, `hyperlinksFlattened`, `metadataFieldsScrubbed`, `customXmlPartsDropped`, `fieldCodesStripped`, `sentinelApplied`

#### Scenario: PDF anonymisation leaves sanitization column null

- **GIVEN** a PDF file is anonymised
- **WHEN** the operation completes
- **THEN** the `sanitization` column on the resulting log row is `null` (sanitisation does not apply to PDF in this change)

### Requirement: Sanitiser logs MUST NOT contain PII (ADR-005)

Per ADR-005, sanitiser log lines, exception messages, and debug output MUST NOT include:

- Comment text content
- Tracked-change text content (inserted or deleted)
- Metadata field values (Author, Title, Subject, etc. — pre-sanitisation values)
- Custom XML content
- Hyperlink URLs or display text
- Field-code cached result values

Permitted log content is restricted to: the Nextcloud file ID, the MIME type, the strategy class name, the per-category counts from `SanitizationReport`, and structural error details (e.g. "missing [Content_Types].xml entry for word/comments.xml", "ZipArchive::open returned error code 19").

`SanitizationException` messages MUST follow the same constraint — they MAY reference part paths and OOXML / ODT element names, but MUST NOT include content.

#### Scenario: Sanitiser failure log contains no document content

- **GIVEN** a DOCX with `dc:creator: Robert Zondervan` and a comment "Bespreken met Jan op vrijdag"
- **AND** the sanitiser fails partway with an internal exception
- **WHEN** the failure is logged
- **THEN** the log entry MUST NOT contain "Robert Zondervan", "Bespreken", or "Jan"
- **AND** the log entry MUST contain at most: the file ID, the MIME type, the strategy class name, the exception class name, and the structural detail

### Requirement: Encrypted DOCX / ODT MUST raise `SanitizationException`

Password-protected or otherwise encrypted DOCX / ODT files cannot be sanitised. When `ZipArchive::open` or its equivalent returns an error indicating encryption, the orchestrator MUST throw `SanitizationException` with a typed reason (e.g. enum value or message identifying "encrypted document"). The exception MUST be catchable by `DocumentProcessingHandler::anonymizeDocument` so the caller can produce an operator-facing error rather than crashing.

The exception MUST NOT contain the file's contents or filename in its message (per ADR-005).

#### Scenario: Encrypted DOCX raises SanitizationException

- **GIVEN** a password-protected `.docx` file in NC Files
- **WHEN** `OfficeDocumentSanitizer::sanitize($fileId)` is called
- **THEN** `SanitizationException` is thrown
- **AND** the exception's reason or message identifies the cause as encryption (NOT the filename or content)
- **AND** no temp file lingers after the exception propagates (or the orchestrator has cleaned it up)

