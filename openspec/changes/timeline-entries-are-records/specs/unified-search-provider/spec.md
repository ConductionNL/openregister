# unified-search-provider

## ADDED Requirements

### Requirement: Timeline entries are searched across objects (REQ-TER-006)

The provider SHALL search timeline entries as their own result kind,
indexed with their object, kind, author, time, visibility and declared
fields. Results SHALL be resolved with the same access as the object and
SHALL honour the entry's internal or public visibility. A hit SHALL name
both the object and the entry.

#### Scenario: a Woo request finds every mention of a subject

- **GIVEN** entries on three objects mentioning one subject
- **WHEN** a user who may read all three searches for it
- **THEN** three entry hits are returned, each naming its object

#### Scenario: an internal entry stays out of a public reader's results

- **GIVEN** an internal entry matching a term
- **WHEN** a subject-scoped portal reader searches for it
- **THEN** it is not returned

#### Scenario: an entry on an unreadable object is absent

- **GIVEN** an entry matching a term on an object the searcher may not read
- **WHEN** they search
- **THEN** it is not returned
- @e2e exclude {access resolution, covered by unit tests}
