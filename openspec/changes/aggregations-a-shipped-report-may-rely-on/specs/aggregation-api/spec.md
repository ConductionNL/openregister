## ADDED Requirements

### Requirement: A shipped report SHALL be able to rely on a stable count-by aggregation

OpenRegister SHALL keep a stable report contract on the ad-hoc aggregation primitive: metric `count`, an optional
`groupBy` on one field, a `filter` in the `findAll()` vocabulary, and optional `from`/`to` bounds on a named date
field. Objects with no value for the group field SHALL be one group with key `null`.

#### Scenario: open cases per handler
- **GIVEN** register `dossiq`, schema `case`, with 3 open cases for `anna`, 1 for `bart` and 2 without an assignee
- **WHEN** a consuming app runs `count` grouped by `assignee` with filter `{ isFinalStatus: false }`
- **THEN** the groups SHALL be `anna: 3` and `bart: 1`
- **AND** the 2 cases without an assignee SHALL be one group with key `null`, not dropped

#### Scenario: cases per area in a period
- **GIVEN** register `dossiq`, schema `case`, with `district` and `startDate`
- **WHEN** a consuming app runs `count` grouped by `district` with `from` and `to` on `startDate`
- **THEN** only cases whose `startDate` falls in `[from, to)` SHALL be counted
- **AND** cases without a `district` SHALL be one group with key `null`

### Requirement: The report contract SHALL keep its shape across releases

The report contract SHALL be reachable in-process through `AggregationRunner::runAdhocByRef()` and over REST through
`GET /api/objects/aggregations/{register}/{schema}/timeseries`, both answering `{ groups: [{ key, value }], backend,
cached }`. A change to its request or response shape SHALL follow openregister's deprecation rule for a breaking change.

#### Scenario: a renamed response key
- **GIVEN** a release that would rename `groups[].value`
- **WHEN** the change is proposed
- **THEN** it SHALL be marked breaking and keep the old key through the deprecation period

### Requirement: The report contract SHALL apply the caller's access before counting

A count under the report contract SHALL apply the schema's `list` RBAC verdict for the active user and the active
organisation's tenancy predicate before any SQL runs, exactly as `findAll()`, so a report never counts an object the
caller could not list.

#### Scenario: a handler without access to a case type
- **GIVEN** a user who may list only the cases of their own organisation
- **WHEN** a report counts cases grouped by `assignee`
- **THEN** the counts SHALL include only that organisation's cases
