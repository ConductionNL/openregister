## Purpose

Reads a Word document into its structure, so a consuming app can turn one document into one lesson or chapter draft with a block per heading section. The title, the headings with their level, and the paragraphs, lists, tables and image references under each heading survive, which flat search text loses. The flat text comes along unchanged.

@e2e exclude Backend PHP document reader (OOXML package parsing for headings, paragraphs, lists, tables, images, flat text and input bounds) with no OpenRegister UI surface; exercised by PHPUnit on documents built inside the test. Covered by PHPUnit.

## ADDED Requirements

### Requirement: Content comes back in sections under their heading (REQ-DOCX-001)

The extractor SHALL return the document body as a list of sections in document order. Each heading paragraph SHALL start a new section that carries the heading text and its level from 1 to 9. The level SHALL come from the paragraph's outline level, else from its paragraph style (the style's outline level, or a style named `heading 1` to `heading 9`), following the style's `basedOn` chain. Content before the first heading SHALL sit in a first section with an empty heading and level 0. Each section SHALL carry its content as an ordered list of blocks.

#### Scenario: Two headings of different levels each open a section

- **GIVEN** a document with the heading "Fotosynthese" at level 1, the paragraph "Planten maken voedsel", the heading "Proef" at level 2 and the paragraph "Zet de plant in het licht"
- **WHEN** the document is extracted
- **THEN** the sections are "Fotosynthese" with level 1 and "Proef" with level 2
- **AND** the paragraph "Planten maken voedsel" is the only block of the first section

#### Scenario: A localised heading style is recognised by its name

- **GIVEN** a document whose styles part defines the style id `Kop1` with the name `heading 1`, and a paragraph "Inleiding" in that style
- **WHEN** the document is extracted
- **THEN** "Inleiding" opens a section with level 1

#### Scenario: Text before the first heading is kept

- **GIVEN** a document that starts with the paragraph "Groep 6, week 12" before its first heading
- **WHEN** the document is extracted
- **THEN** the first section has an empty heading and level 0 and holds "Groep 6, week 12"

### Requirement: The document carries a title (REQ-DOCX-002)

The result SHALL carry `title`: the text of the first paragraph in the `Title` style, else the title in the document's core properties, else an empty string. The paragraph used as the title SHALL NOT also appear as a block.

#### Scenario: A title paragraph names the document

- **GIVEN** a document whose first paragraph "Water in de klas" is in the `Title` style
- **WHEN** the document is extracted
- **THEN** `title` is "Water in de klas"
- **AND** no block holds "Water in de klas"

#### Scenario: The core properties title is the fallback

- **GIVEN** a document with no `Title` paragraph whose core properties title is "Les 4"
- **WHEN** the document is extracted
- **THEN** `title` is "Les 4"

### Requirement: Paragraph text is read once, with runs joined (REQ-DOCX-003)

Each non-empty paragraph that is not a heading, a title or a list item SHALL become a `paragraph` block. Runs within one paragraph SHALL be joined into one string, tabs and line breaks SHALL become spaces, and whitespace SHALL be collapsed. Deleted text of tracked changes SHALL NOT be read. Text in a text box SHALL be read exactly once, as blocks that follow the paragraph holding the text box, even when the document stores the text box twice for compatibility.

#### Scenario: A paragraph split into runs is one string

- **GIVEN** a paragraph made of the runs "Water " and "kookt"
- **WHEN** the document is extracted
- **THEN** the section holds the single paragraph block "Water kookt"

#### Scenario: A text box stored twice is read once

- **GIVEN** a paragraph holding a text box with the text "Let op" in both the modern shape and its compatibility fallback
- **WHEN** the document is extracted
- **THEN** exactly one paragraph block holds "Let op"

#### Scenario: Deleted text is left out

- **GIVEN** a paragraph with the text "Nu" and a tracked deletion "Straks"
- **WHEN** the document is extracted
- **THEN** the paragraph block is "Nu"

### Requirement: Lists keep their items, levels and kind (REQ-DOCX-004)

Consecutive numbered or bulleted paragraphs of the same list SHALL become one `list` block. Each item SHALL carry its text, its level (1 for the outer level) and whether it is `ordered` (numbered) or bulleted, from the document's numbering definitions. Numbering SHALL be found on the paragraph itself or through its style. A heading that carries outline numbering, as LibreOffice writes chapter numbering, SHALL stay a heading and SHALL NOT become a list item.

#### Scenario: A bulleted list with a nested item

- **GIVEN** the bulleted items "Licht" and "Water" at the outer level and "Uit de grond" one level deeper
- **WHEN** the document is extracted
- **THEN** one list block holds the items "Licht" (level 1), "Water" (level 1) and "Uit de grond" (level 2), all with `ordered` false

#### Scenario: A numbered list is ordered

- **GIVEN** the numbered items "Eerst kijken" and "Dan meten"
- **WHEN** the document is extracted
- **THEN** one list block holds both items with `ordered` true

#### Scenario: A numbered heading stays a heading

- **GIVEN** a LibreOffice document whose heading style carries outline numbering with the number format `none`
- **WHEN** the document is extracted
- **THEN** each heading opens a section and no list block holds a heading text

### Requirement: Tables come back as rows of cell text (REQ-DOCX-005)

Each table SHALL become a `table` block in its place, holding its rows in order, each row a list of cell texts in order. A cell's text SHALL be its paragraphs joined by a newline, including the text of a table nested in that cell.

#### Scenario: A two by two table

- **GIVEN** a table with the rows "Stof", "Rol" and "CO2", "Bouwstof"
- **WHEN** the document is extracted
- **THEN** the section holds a table block with the rows `[["Stof", "Rol"], ["CO2", "Bouwstof"]]`

### Requirement: Image references come back in document order (REQ-DOCX-006)

Each picture SHALL become an `image` block, in document order after the paragraph or table that holds it. Each image block SHALL give `target` (the image's path inside the package, resolved from the document's relationships, or the URL for a linked image), `external` (true for a linked image), `name` and `description` (the picture's alt text, or an empty string). The extractor SHALL NOT return the image bytes.

#### Scenario: An embedded picture is referenced by its package path and alt text

- **GIVEN** a paragraph with a picture named "Blad" whose alt text is "Een blad in de zon", embedded as `media/image1.png`
- **WHEN** the document is extracted
- **THEN** the section holds the image block `{target: "word/media/image1.png", external: false, name: "Blad", description: "Een blad in de zon"}`

#### Scenario: A linked picture is flagged external

- **GIVEN** a picture linked to `https://example.org/blad.png`
- **WHEN** the document is extracted
- **THEN** its image block has `target` "https://example.org/blad.png" and `external` true

### Requirement: The flat text comes along unchanged (REQ-DOCX-007)

The result SHALL carry `text`: the same string the Word extractor returns for the same file, so search indexing and structure agree. When the Word extractor returns nothing for a document whose structure holds text, `text` SHALL be built from the structure instead: the title, headings, paragraphs, list items and table rows, one per line.

#### Scenario: The flat text equals what search indexes

- **GIVEN** a readable document
- **WHEN** the document is extracted
- **THEN** `text` equals the Word extractor's result for the same file

#### Scenario: The structure fills in when the flat text is empty

- **GIVEN** a readable document for which the Word extractor returns nothing
- **WHEN** the document is extracted
- **THEN** `text` holds the document's headings and paragraphs, one per line

### Requirement: A document that cannot be read degrades to no result (REQ-DOCX-008)

The extractor SHALL return `null`, not throw, when the input is not a readable document: corrupt or non-zip bytes, a package without a document part, a document with no text and no pictures, or a format it does not read (legacy binary `.doc`, `.odt`). It SHALL log the failure with the file id and MIME type, plus the exception class when one was thrown, and SHALL NOT log any document content. A missing zip extension on the server is a deployment error and SHALL throw.

#### Scenario: Garbage bytes return null without leaking content

- **GIVEN** a file with a docx MIME type whose bytes are not a zip package
- **WHEN** it is extracted
- **THEN** the result is `null`
- **AND** the logged error carries no part of the file's bytes

#### Scenario: A legacy binary document is not read

- **GIVEN** a file with MIME type `application/msword`
- **WHEN** it is extracted
- **THEN** the result is `null`

### Requirement: Hostile input is bounded (REQ-DOCX-009)

The extractor SHALL refuse any XML part that declares a DOCTYPE. It SHALL read each part only up to a fixed size cap and treat a larger part as unreadable. It SHALL stop after a fixed maximum number of blocks and then set `truncated` to true on the result. It SHALL stop descending nested content controls, tables and text boxes past a fixed depth.

#### Scenario: A DOCTYPE in the document part is refused

- **GIVEN** a document whose document part declares a DOCTYPE with an entity
- **WHEN** the document is extracted
- **THEN** the result is `null` and no entity is expanded

#### Scenario: A document within the limits is not truncated

- **GIVEN** a document with three paragraphs
- **WHEN** the document is extracted
- **THEN** `truncated` is false

### Requirement: The supported formats can be asked for (REQ-DOCX-010)

A caller SHALL be able to ask, before extracting, whether a file is a format the extractor reads, by MIME type or, when the MIME type is generic, by file extension. The supported formats are `.docx`, `.docm`, `.dotx` and `.dotm`.

#### Scenario: A docx with a generic MIME type is recognised by extension

- **GIVEN** MIME type `application/octet-stream` and file name `les-3.docx`
- **WHEN** support is asked for
- **THEN** the answer is yes

#### Scenario: An OpenDocument text is not supported

- **GIVEN** MIME type `application/vnd.oasis.opendocument.text` and file name `les-3.odt`
- **WHEN** support is asked for
- **THEN** the answer is no
