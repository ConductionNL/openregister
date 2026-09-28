# data-import-export

## ADDED Requirements

### Requirement: Objects export as tab-separated values

`GET /api/objects/{register}/{schema}/export` SHALL accept `format=tsv` and SHALL return the same rows and columns as `format=csv`, separated by tabs, UTF-8 with a byte order mark, with a value quoted when it contains a tab, a line break or a double quote. The response SHALL be `text/tab-separated-values` with a file name ending in `.tsv`. The export dialog SHALL offer TSV, JSON and XML beside Excel and CSV.

#### Scenario: a data steward downloads TSV

- **GIVEN** schema `meldingen` with 45 records whose `status` is `afgehandeld`, one of them with a description containing a tab
- **WHEN** a signed-in data steward opens the export dialog on the register page, chooses TSV and exports with that filter
- **THEN** the browser saves `{register}_meldingen_{datetime}.tsv` with a header row and 45 data rows
- **AND** the record with the tab reads back as one row with the tab inside its quoted value
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/public-export.spec.ts}

### Requirement: The public downloads what a schema grants it for export

The export route SHALL be reachable without a session. An anonymous caller SHALL be allowed to export a schema only when the schema's effective export grant includes the `public` principal: its `export` list when it declares one, otherwise its `read` list. Otherwise the answer SHALL be 401 with rule `not-public`. An anonymous export SHALL contain only the rows and columns the `public` principal may read, SHALL omit administration metadata columns, SHALL accept only `csv`, `tsv`, `json` and `xml`, and SHALL answer 406 for another format. Every anonymous export SHALL write an export audit row with actor `anonymous`.

#### Scenario: a visitor downloads published decisions as XML

- **GIVEN** schema `besluit` in register `publicaties` grants `read` and `export` to `public`, and 300 of its records are readable by the public
- **WHEN** an anonymous visitor calls `GET /api/objects/publicaties/besluit/export?format=xml&thema=milieu`
- **THEN** the response is 200 with `application/xml` holding only the public `milieu` decisions
- **AND** no property restricted to a group appears as an element
- **AND** the audit trail holds an export row with actor `anonymous`, format `xml` and the row count
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/public-export.spec.ts}

#### Scenario: a schema that is readable but not exportable stays in place

- **GIVEN** schema `besluit` grants `read` to `public` and declares `export` as `["redactie"]`
- **WHEN** an anonymous visitor calls `GET /api/objects/publicaties/besluit/export?format=csv`
- **THEN** the response is 401 with rule `not-public` and no file
- @e2e exclude {specified only; task 4.2 adds tests/e2e/ci/public-export.spec.ts}

#### Scenario: a rendered document is not an open format

- **GIVEN** the public schema `besluit`
- **WHEN** an anonymous visitor asks for `format=pdf`
- **THEN** the response is 406 naming `csv`, `tsv`, `json` and `xml`
- @e2e exclude {specified only; task 2.2 covers it in tests/newman/openregister-public-export.postman_collection.json}

### Requirement: A public export is paged and rate limited

An anonymous export SHALL return at most 10,000 rows per response, SHALL honour `_page`, and SHALL send `X-Total-Count`, `X-Page`, `X-Pages` and, while more pages exist, a `Link` header with `rel="next"`. The route SHALL limit anonymous callers to 10 exports per minute per address. A signed-in export SHALL keep its current limits.

#### Scenario: a harvester walks a large table

- **GIVEN** 23,500 public records in schema `subsidie`
- **WHEN** a harvester calls `GET /api/objects/publicaties/subsidie/export?format=csv` without a session
- **THEN** the response holds 10,000 rows with `X-Total-Count: 23500`, `X-Page: 1`, `X-Pages: 3` and a `Link` to `_page=2`
- **AND** following the links returns the remaining 13,500 rows over two more responses
- @e2e exclude {specified only; task 2.2 covers it in tests/Unit/Service/ExportServiceAnonymousPagingTest.php}
