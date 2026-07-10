## ADDED Requirements

### Requirement: Per-format text extraction is delegated to dedicated handlers

Text extraction SHALL delegate per-format parsing to dedicated single-
responsibility handlers rather than implementing every format inline in one
service. The orchestrating service coordinates; each format (PDF, Word,
spreadsheet, EML) and the chunking algorithm live in their own class, mirroring
the handler decomposition already used under `lib/Service/File/`.

#### Scenario: Extraction routes to a format handler

- **WHEN** a file of a supported type is extracted
- **THEN** the orchestrating service dispatches to the handler for that format
- **AND** the extraction output is unchanged from the pre-refactor behaviour

#### Scenario: Behaviour is preserved across the refactor

- **WHEN** the same file is extracted before and after the handler split
- **THEN** the extracted text and chunk boundaries are identical
