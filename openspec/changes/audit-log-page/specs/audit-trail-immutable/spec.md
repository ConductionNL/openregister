# audit-trail-immutable

## ADDED Requirements

### Requirement: An instance-wide audit list with filters

The system SHALL expose a cursor-paginated list over the whole audit trail
with filters on actor, period, action, register, schema and object, and
full-text on the change summary. A non-admin SHALL see only entries of
objects they may read; an admin SHALL see every entry, including those of
deleted objects. The query SHALL be index-backed and SHALL NOT count the
whole table on page load.

#### Scenario: an admin filters by actor and period

- **GIVEN** an admin and a trail with entries by user A yesterday and by user B today
- **WHEN** they list with actor A and a period of the last two days
- **THEN** only A's entries are returned, newest first
- @e2e exclude {proposal only; task 4.2 adds tests/e2e/ci/audit-log-page.spec.ts when the page ships}

#### Scenario: a handler sees only readable objects' entries

- **GIVEN** a user with read scope on register `cases` only and entries on objects in `cases` and `hr`
- **WHEN** they list the audit trail
- **THEN** the `hr` entries are absent
- @e2e exclude {the RBAC join is covered by unit tests on the mapper}

### Requirement: The filtered audit list exports with its hash chain

The system SHALL export the filtered list as CSV or JSON through the
compliance export path so that `hash` and `previousHash` are present on
every row, and SHALL run the export as a background job with a notification
when the result exceeds 10,000 rows.

#### Scenario: an export carries the chain fields

- **GIVEN** a filtered list of twelve entries
- **WHEN** the admin exports it as CSV
- **THEN** the file has twelve rows and the columns `hash` and `previousHash` are filled on each
- @e2e exclude {proposal only; task 4.2 adds tests/e2e/ci/audit-log-page.spec.ts when the export ships}

### Requirement: The audit list is a leaf surface

The audit leaf SHALL expose the instance-wide list as an `index` surface so
that a consuming app places it through its manifest and writes no list code.

#### Scenario: a manifest places the audit page

- **GIVEN** a consuming app whose manifest declares a page with `leaf: audit`, surface `index`
- **WHEN** a user opens that page
- **THEN** the instance-wide audit list renders with its filter bar
- @e2e exclude {leaf placement is asserted by the manifest parity gate and the consuming app's e2e}
