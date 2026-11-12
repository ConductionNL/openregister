# export-pdf-format

## ADDED Requirements

### Requirement: A PDF export carries the organisation's house style

When thematiq is installed and returns a document style profile for the
requesting user, a PDF export SHALL show the profile's logo in the header, use
its custom fonts where they can be embedded, colour the table header with its
primary colours, and print its footer lines and links on every page. Remote
loading SHALL stay off: every asset SHALL be embedded. Without a profile, the
export SHALL be the current layout, and a failure to read the profile SHALL
NOT fail the export.

#### Scenario: a municipality's export carries its logo and footer

- **GIVEN** a functional administrator who set a document logo and the footer line "Gemeente Hilversum, Dudokpark 1" in thematiq's Documents block
- **WHEN** a case handler exports the `vergunning` list as PDF through `GET /api/objects/{register}/vergunning/export?format=pdf`
- **THEN** every page of the file has the logo in the header and the footer line with the page number
- **AND** the table header uses the profile's primary colour
- @e2e exclude {specified only; task 3.1 adds tests/e2e/ci/export-pdf-house-style.spec.ts}

#### Scenario: without thematiq nothing changes

- **GIVEN** an instance without thematiq
- **WHEN** the same export runs
- **THEN** the file has the current layout and the export succeeds
- @e2e exclude {specified only; covered by the unchanged-output test in task 2.3}

### Requirement: A single record exports as a PDF

A user who may read a record SHALL be able to export that one record as a PDF from the record
page. The PDF SHALL list the record's fields with their labels and values, and SHALL leave out
every field the user may not read. It SHALL carry the house style when one is set, as a list
export does.

#### Scenario: A caseworker prints one case
- **GIVEN** a case `Z-2026-0042` with the fields `omschrijving`, `status` and `startdatum`
- **WHEN** a caseworker chooses "Export as PDF" on the record page
- **THEN** the browser saves a PDF that shows the three fields with their labels and values
- @e2e exclude {specified only; task 2.4 adds the record export test}

#### Scenario: A hidden field stays out of the PDF
- **GIVEN** a case whose `bsn` field the caseworker may not read
- **WHEN** the caseworker exports the case as a PDF
- **THEN** the PDF does not contain the `bsn` field or its value
- @e2e exclude {specified only; task 2.4 adds the record export test}
