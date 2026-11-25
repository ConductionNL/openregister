# text-extraction

## ADDED Requirements

### Requirement: Other apps read a file's text on demand through a published facade, as the person

OpenRegister SHALL publish `OCA\OpenRegister\Files\Service\FileTextFacade` with
`isAvailable(): bool` and `extractText(int $fileId, string $userId): ?string`.
`extractText()` SHALL resolve the file in that user's own folder and answer
null when the user cannot open it, when it is not a file, when its type has no
reader, or when reading fails. It SHALL read plain text, PDF, Word,
spreadsheet and mail files with the extraction readers and SHALL write
nothing: no chunk, index, entity or mail header row.

#### Scenario: hermiq hands an attached PDF to a model without PDF support

- **GIVEN** a person who attached `offerte-2026.pdf` from their own Files
- **WHEN** hermiq calls `extractText(<fileId>, <their uid>)`
- **THEN** the answer is the PDF's text, and no chunk of that file is stored
- @e2e exclude {PHP contract; covered by FileTextFacadeTest in task 1.1}

#### Scenario: another user's file stays closed

- **GIVEN** a file owned by another user and not shared with the person
- **WHEN** hermiq calls `extractText(<that fileId>, <their uid>)`
- **THEN** the answer is null
- @e2e exclude {PHP contract; covered by FileTextFacadeTest in task 1.1}
