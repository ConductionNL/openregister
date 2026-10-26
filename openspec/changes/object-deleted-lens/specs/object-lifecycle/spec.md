# object-lifecycle Delta: object-deleted-lens

**Status**: draft

## ADDED Requirements

### Requirement: The object list MUST offer a deleted lens shaped like the archive lens

The object list, its count and its facets SHALL read `_deleted`: absent or false lists the working set (soft-deleted rows left out), `true` lists the soft-deleted rows alone, and `any` lists both. `_includeDeleted=true` SHALL keep meaning both. A value that is neither a spelling of true nor `any` SHALL list the working set. The lens SHALL NOT change who may read a row.

#### Scenario: A consumer lists the deleted rows of one schema

- **GIVEN** a schema with 300 live objects and 10 soft-deleted ones
- **WHEN** `GET /api/objects/{register}/{schema}?_deleted=true` is called by a user who could read them
- **THEN** the 10 soft-deleted objects are returned, each with its `@self.deleted` marker
- **AND** the total is 10

#### Scenario: Nobody asks

- **GIVEN** the same schema
- **WHEN** the list is called without `_deleted`
- **THEN** the 300 live objects are listed and no soft-deleted one

#### Scenario: A typo

- **WHEN** the list is called with `_deleted=yes please`
- **THEN** the working set is listed
