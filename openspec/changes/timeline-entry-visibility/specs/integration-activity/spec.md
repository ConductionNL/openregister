# integration-activity

## ADDED Requirements

### Requirement: Every merged feed row carries a visibility and the feed filters on it

Each row of the merged feed SHALL carry `visibility`. Audit rows and NC
Activity rows SHALL be `internal`; notes SHALL carry their own flag; file and
mail rows SHALL carry the visibility their source declares, `internal` when
it declares none. The feed SHALL accept `visibility=public` and SHALL serve
only public rows to a caller without `update` on the object, whatever the
caller asks for.

#### Scenario: a citizen's view holds only public rows

- **GIVEN** an object with two internal notes, one public note and four audit rows
- **WHEN** a caller with `read` only requests the feed without a filter
- **THEN** the feed holds the one public note
- @e2e exclude {enforced filter, covered by ActivityProvider unit tests}

#### Scenario: a handler filters to what the citizen sees

- **GIVEN** the same object and a caller with `update`
- **WHEN** the caller requests `visibility=public`
- **THEN** the feed holds the one public note, and without the filter it holds all seven rows
- @e2e exclude {filter chip, covered by the e2e spec of the notes task}
