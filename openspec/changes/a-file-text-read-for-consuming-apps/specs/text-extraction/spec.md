## ADDED Requirements

### Requirement: A consuming app reads one file's text without storing anything (REQ-005)

OpenRegister SHALL publish `OCA\OpenRegister\Contract\FileTextReaderInterface`,
whose `readFileText()` answers the text of a Nextcloud file node the caller
already opened as its user: PDF, Word (docx, and legacy doc where an office
server converts it), OpenDocument text and plain-text types. It SHALL answer
null for a type it has no reader for or a file without text, and SHALL store
no chunk, no extraction status and dispatch no event.

#### Scenario: Buildiq reads a Word brief

- **GIVEN** a maker's file `brief.docx` that Buildiq opened through the maker's user folder
- **WHEN** Buildiq calls `readFileText()` with that file
- **THEN** it receives the document's text
- **AND** no chunk or extraction row exists for the file afterwards

#### Scenario: An image has no text

- **GIVEN** a file `logo.png`
- **WHEN** a consuming app calls `readFileText()` with it
- **THEN** it receives null
