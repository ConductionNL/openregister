## ADDED Requirements

### Requirement: Bulk saves record audit trails and lifecycle events with batched writes and real diffs

Bulk object saves MUST classify every persisted row using the bulk mapper's
`object_status` contract (raw magic-table rows with underscore-prefixed
metadata columns), and MUST emit the same side effects as the single-object
save path: an audit-trail row per created/updated object and — when events
are enabled — an `ObjectCreatedEvent`/`ObjectUpdatedEvent` per object. Audit
rows for a chunk MUST be persisted with batched multi-row INSERTs (not one
INSERT per object), and update audit rows and `ObjectUpdatedEvent` MUST carry
the REAL pre-update object state (reconstructed from the pre-upsert row read
the mapper already performs for classification), not the post-update entity
passed twice. Internal bookkeeping fields (`object_status`,
`_pre_update_row`, `operation_start_time`) MUST NOT leak into API responses.

#### Scenario: Bulk create writes batched audits and correct buckets

- **GIVEN** a bulk save of N new objects
- **WHEN** the chunk is persisted via the bulk upsert path
- **THEN** the response's `saved` bucket and `statistics.saved` count all N objects
- **AND** N audit rows with action `create` are written through one batched insert call (chunked multi-row INSERTs)
- **AND** with events enabled, one `ObjectCreatedEvent` is dispatched per object

#### Scenario: Bulk update audits record a real changeset

- **GIVEN** a bulk save that updates an existing object's `title`
- **WHEN** the audit row is written
- **THEN** its `changed` diff records the pre-update value as `old` and the persisted value as `new`
- **AND** the dispatched `ObjectUpdatedEvent`'s `oldObject` carries the pre-update state

#### Scenario: Unchanged rows produce no side effects

- **WHEN** a bulk upsert classifies a row as `unchanged`
- **THEN** no audit row is written and no lifecycle event is dispatched for it

#### Scenario: Events disabled still writes audits

- **WHEN** a bulk save runs with events disabled (the bulk API default)
- **THEN** audit rows are still written for created/updated objects
- **AND** no lifecycle events are dispatched

## MODIFIED Requirements

### Requirement: Schema-derived and request-invariant values are computed once

Schema-derived and request-invariant values SHALL be computed once per
schema/request and reused across all objects processed in that pass. This
includes property-authorization presence, computed-property presence, the cleaned
validation schema, the compiled validator, and the current user's group ids and
admin status. The prepared validation schema SHALL be cached keyed by schema id
AND schema version, so a schema update (version bump) always produces a fresh
preparation and stale prepared schemas are never served.

#### Scenario: Wide-schema list does per-schema work once

- **WHEN** a page of N objects of one schema is rendered
- **THEN** each schema-derived value is computed once, not N times
- **AND** the current user's group ids are resolved once for the request

#### Scenario: Bulk validation reuses one validator

- **WHEN** many objects of one schema are validated in a bulk operation
- **THEN** the cleaned schema and the validator/format resolvers are constructed
  once, not per object

#### Scenario: Schema version bump invalidates the prepared-schema cache

- **GIVEN** objects were validated against schema version 1.0.0 in this request
- **WHEN** the same schema id is presented at version 2.0.0 (e.g. a new required property)
- **THEN** the preparation pipeline runs again for the new version
- **AND** validation enforces the new version's rules
