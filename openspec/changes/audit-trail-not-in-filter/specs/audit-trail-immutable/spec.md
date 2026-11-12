# audit-trail-immutable Delta

**Status**: proposed
**Scope**: openregister
**OpenSpec changes**:

- [audit-trail-not-in-filter](../../)

## ADDED Requirements

### Requirement: A history read can leave out actions and actors in the query

The audit trail list SHALL accept `field[notIn]` on an allowlisted column and leave out, before paging, the rows whose
column is one of the named values, compared case-insensitively. A plain list value SHALL keep only the rows whose
column is one of the values. Any other operator SHALL be refused, never read as no filter.

#### Scenario: A busy object's history without reads and System

- **GIVEN** an object with 2,538 `read` rows, 67 `update` rows by `System` and one `file.downloaded` row by fatma.kaya
- **WHEN** a reader asks `/api/objects/{register}/{schema}/{id}/audit-trails?limit=10&action[notIn]=read&user[notIn]=System`
- **THEN** the first page holds the `file.downloaded` row
- **AND** `total` counts only the rows that are left

#### Scenario: An unknown operator

- **WHEN** a caller asks `action[like]=read`
- **THEN** the list is refused rather than answered unfiltered
