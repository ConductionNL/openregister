# scoped-file-content-search

## ADDED Requirements

### Requirement: An app can search the content of the files one user may read (REQ-SFCS-001)

OpenRegister SHALL offer `ScopedContentSearchService::searchFiles(words, fileIds, userId)`.
It SHALL search only the extracted text of the listed files that resolve in
the user's own folder and are readable, SHALL answer one hit per file with
its file id, its best rank and a snippet in which the text is HTML-escaped and
the searched words are wrapped in `<mark>`, ordered best rank first, and
SHALL list the readable listed files that have no extracted text under
`unextracted`. On PostgreSQL the rank SHALL be the full-text rank; on other
databases the match SHALL be an unranked substring match.

#### Scenario: a word in a readable file is found

- **GIVEN** a user who can read files 11 and 12, and file 11's extracted text contains "waterschapsheffing"
- **WHEN** an app searches "waterschapsheffing" over files 11 and 12 for that user
- **THEN** the answer holds one hit for file 11 whose snippet marks the word, and no hit for file 12
- @e2e exclude service contract for apps; covered by PHPUnit

#### Scenario: a file the user cannot read is never searched

- **GIVEN** file 13 does not resolve in the user's folder
- **WHEN** an app lists file 13 in the search
- **THEN** file 13 is neither searched nor named as unextracted
- @e2e exclude service contract for apps; covered by PHPUnit

#### Scenario: files without extracted text are named

- **GIVEN** a readable listed file with no chunks
- **WHEN** an app searches over it
- **THEN** its id is in `unextracted`
- @e2e exclude service contract for apps; covered by PHPUnit
