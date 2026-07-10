# objects-crud Specification

## Purpose
TBD - created by archiving change clamp-list-limit-and-optional-count. Update Purpose after archive.
## Requirements
### Requirement: List page size is bounded by a hard maximum

Every object list/search endpoint SHALL clamp the effective page size to a hard
maximum. A client-supplied `_limit` above the maximum SHALL be reduced to the
maximum; it SHALL NOT cause the server to load an arbitrarily large result set.

#### Scenario: Oversized limit is clamped

- **WHEN** a client requests a list with `_limit` far above the maximum (e.g.
  `_limit=1000000`)
- **THEN** at most `MAX_PAGE_SIZE` rows are loaded and returned

### Requirement: The total-count query is optional

A client SHALL be able to request a list without the total count. When the total
is opted out, the endpoint SHALL NOT execute the COUNT query and SHALL return
`total: null`.

#### Scenario: Count can be skipped

- **WHEN** a client requests a list with `_count=false`
- **THEN** no COUNT query is executed
- **AND** the response reports `total: null`

#### Scenario: Default behaviour includes the total

- **WHEN** a client requests a list without the count flag
- **THEN** the total is computed and returned as before

### Requirement: Partial object updates are protected against lost updates

A partial update (PATCH) of an object SHALL apply optimistic concurrency
control. The object's version captured at read time SHALL be asserted at write
time; if the persisted object changed since it was read, the update SHALL be
rejected with HTTP 409 Conflict rather than overwriting the concurrent change.

#### Scenario: Concurrent PATCHes do not lose data

- **WHEN** two clients read the same object version and each PATCHes a different
  field
- **AND** the first PATCH commits successfully
- **THEN** the second PATCH is rejected with HTTP 409
- **AND** the first client's field is not overwritten or lost

#### Scenario: Conditional update via If-Match

- **WHEN** a client sends a PATCH with an `If-Match` value that no longer matches
  the object's current version
- **THEN** the request is rejected with HTTP 409 and the current version is
  reported

#### Scenario: Non-conflicting PATCH succeeds

- **WHEN** a client PATCHes an object whose version has not changed since it was
  read
- **THEN** the update is applied and a new version/etag is returned

### Requirement: List/search relation resolution is batched, not per-row

When rendering a page of objects with `_extend`, related objects SHALL be
preloaded in a bounded number of queries for the whole page, not fetched
per row. The list/search render path SHALL use the same batch-preload used by
`renderEntities()`.

#### Scenario: Extended list does not N+1

- **WHEN** a client requests a page of objects with `_extend` on a relation
- **THEN** the related objects are fetched in O(1) batched queries for the page
- **AND** the number of relation queries does not grow with the page size

### Requirement: Schema-derived and request-invariant values are computed once

Schema-derived and request-invariant values SHALL be computed once per
schema/request and reused across all objects processed in that pass. This
includes property-authorization presence, computed-property presence, the cleaned
validation schema, the compiled validator, and the current user's group ids and
admin status.

#### Scenario: Wide-schema list does per-schema work once

- **WHEN** a page of N objects of one schema is rendered
- **THEN** each schema-derived value is computed once, not N times
- **AND** the current user's group ids are resolved once for the request

#### Scenario: Bulk validation reuses one validator

- **WHEN** many objects of one schema are validated in a bulk operation
- **THEN** the cleaned schema and the validator/format resolvers are constructed
  once, not per object

### Requirement: Field selection narrows the SQL projection

When a request specifies `_fields`, the database query SHALL select only those
columns plus the metadata columns required for hydration, rather than selecting
all property columns and trimming in PHP.

#### Scenario: Narrow field request transfers few columns

- **WHEN** a client requests `_fields=id,name`
- **THEN** the generated SQL selects only those fields plus required metadata
  columns

