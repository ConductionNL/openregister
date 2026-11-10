# zoeken-filteren

## ADDED Requirements

### Requirement: The Tables page searches inside attached files on request (ZKN-CONTENT-004)

The Tables page's search SHALL offer a switch to also search inside attached files. When it is on, the list query SHALL carry `_content_search=true`, and a row that matched only through a file SHALL show the name of that file. The list response SHALL carry that name as `@self.matchedFile` and SHALL carry no text from the file.

#### Scenario: a word that is only in the PDF

- **GIVEN** a record whose attached PDF contains "funderingsherstel" and whose fields do not
- **WHEN** the user searches the Tables page for "funderingsherstel" with the switch on
- **THEN** the record is in the list with "found in" and the PDF's name
- **AND** with the switch off the record is not in the list
- @e2e exclude {specified only; task 2.4 adds tests/e2e/ci/tables-search-in-files.spec.ts}

#### Scenario: no file text reaches the row

- **GIVEN** the same record
- **WHEN** it is listed through a content hit
- **THEN** the row carries the file name in `@self.matchedFile` and no excerpt of the file
- @e2e exclude {response shape; covered by a unit test next to ContentSearchHandlerTest}
