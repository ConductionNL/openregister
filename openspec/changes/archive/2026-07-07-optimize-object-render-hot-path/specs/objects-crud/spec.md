## ADDED Requirements

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
