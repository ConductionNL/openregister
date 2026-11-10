---
status: done
---

# schema-migration Specification

## Purpose
Manages schema versioning and object migration by classifying every schema definition change as compatible or breaking, recording a typed changelog with the matching version bump, and stamping objects with the schema version they validated against. Provides non-mutating revalidation runs for impact analysis, declarative migration plans that transform an object population through the standard save pipeline with rollback via content versioning, and a gate that refuses breaking changes without explicit acknowledgement across every update path.

## Requirements
### Requirement: Schema definition changes MUST be classified and recorded as a changelog

The system MUST diff every schema definition update against the previous
definition and classify the resulting change set, regardless of entry
path (UI, `schemas#update`, `schemas#uploadUpdate`, the runtime schema
API, or configuration import). The classification MUST be one of:

- `compatible`: added optional property, relaxed constraint
  (e.g. lower `minLength`, wider enum), changed
  title/description/UI-only metadata. Version bump: minor for added
  properties, patch otherwise.
- `breaking`: removed property, renamed property, property type change,
  tightened constraint (e.g. higher `minLength`, narrowed enum, new
  `format`), property newly `required` without a `default`. Version
  bump: major.

The typed change list (property, kind of change, old/new fragment) MUST
be persisted per schema version and retrievable as the schema's
changelog. Updates that do not modify the definition (metadata-only
saves) MUST NOT create a changelog entry or version bump.

<!-- @e2e exclude API/backend flow verified via Newman (openregister-schema-migration.postman_collection.json) + PHPUnit; no UI in this change (Phase 6.2 deferred). -->
#### Scenario: Added optional property is compatible
- GIVEN a schema at version `1.2.0`
- WHEN an optional property `nickname` (string) is added
- THEN the change is classified `compatible`
- AND the version becomes `1.3.0`
- AND the changelog lists `added property nickname`

<!-- @e2e exclude API/backend flow verified via Newman (openregister-schema-migration.postman_collection.json) + PHPUnit; no UI in this change (Phase 6.2 deferred). -->
#### Scenario: Type change is breaking
- GIVEN a schema at version `1.3.0` with property `age` of type `string`
- WHEN `age` is changed to type `integer`
- THEN the change is classified `breaking`
- AND the version becomes `2.0.0`
- AND the changelog entry records the old and new type

<!-- @e2e exclude API/backend flow verified via Newman (openregister-schema-migration.postman_collection.json) + PHPUnit; no UI in this change (Phase 6.2 deferred). -->
#### Scenario: New required property without default is breaking
- GIVEN a schema where `email` is optional
- WHEN `email` is added to `required` with no `default`
- THEN the change is classified `breaking`

<!-- @e2e exclude API/backend flow verified via Newman (openregister-schema-migration.postman_collection.json) + PHPUnit; no UI in this change (Phase 6.2 deferred). -->
#### Scenario: Changelog is queryable per schema
- GIVEN a schema that has been updated three times
- WHEN the changelog is requested via the API
- THEN entries are returned newest-first, each with version, timestamp, actor, classification, and the typed change list

### Requirement: A schema's object population MUST be re-validatable without mutation (impact analysis)

The system MUST provide a revalidation run that validates every
non-deleted object of a schema against either the schema's current
definition or a supplied proposed definition (dry-run of an edit),
without modifying any object. Runs MUST execute batched in a background
job, expose progress (processed/total) and a final report (valid count,
invalid count, per-object validation errors capped per object), and be
listable per schema. Two concurrent runs on the same schema MUST be
refused.

<!-- @e2e exclude API/backend flow verified via Newman (openregister-schema-migration.postman_collection.json) + PHPUnit; no UI in this change (Phase 6.2 deferred). -->
#### Scenario: Dry-run against a proposed definition
- GIVEN a schema with 10,000 objects and a proposed definition that tightens `minLength` on `name`
- WHEN a revalidation run is started with the proposed definition
- THEN the run executes in background batches without modifying any object
- AND the report lists each object whose `name` violates the proposed constraint

<!-- @e2e exclude API/backend flow verified via Newman (openregister-schema-migration.postman_collection.json) + PHPUnit; no UI in this change (Phase 6.2 deferred). -->
#### Scenario: Progress and report are queryable
- GIVEN a running revalidation
- WHEN its status is requested
- THEN processed and total counts are returned
- AND once finished, the persisted report returns valid/invalid totals and per-object errors

<!-- @e2e exclude API/backend flow verified via Newman (openregister-schema-migration.postman_collection.json) + PHPUnit; no UI in this change (Phase 6.2 deferred). -->
#### Scenario: Concurrent run refused
- GIVEN a revalidation run in state `running` for a schema
- WHEN a second run is started for the same schema
- THEN the request is refused with HTTP 409 referencing the active run

<!-- @e2e exclude API/backend flow verified via Newman (openregister-schema-migration.postman_collection.json) + PHPUnit; no UI in this change (Phase 6.2 deferred). -->
#### Scenario: Dry-run never mutates
- GIVEN any revalidation run
- WHEN it completes
- THEN no object's data, version history, or audit trail gained an entry from the run itself

### Requirement: Objects MUST carry the schema version they validated against and a queryable validity status

Every successful object write MUST stamp the object with the schema
version it validated against (`schemaVersion` in the object metadata).
A revalidation run against the **current** definition MUST update each
object's validity status (`valid` | `invalid`, with the failing
version) without altering object data. Consumers MUST be able to filter
a schema's objects by validity status and by `schemaVersion` (e.g. "all
objects last validated before 2.0.0", "all currently invalid objects").

<!-- @e2e exclude API/backend flow verified via Newman (openregister-schema-migration.postman_collection.json) + PHPUnit; no UI in this change (Phase 6.2 deferred). -->
#### Scenario: Write stamps the current schema version
- GIVEN a schema at version `2.0.0`
- WHEN an object is created or updated successfully
- THEN the stored object metadata records `schemaVersion: "2.0.0"`

<!-- @e2e exclude API/backend flow verified via Newman (openregister-schema-migration.postman_collection.json) + PHPUnit; no UI in this change (Phase 6.2 deferred). -->
#### Scenario: Revalidation marks invalid objects
- GIVEN objects written under version `1.x` and a breaking update to `2.0.0`
- WHEN a revalidation run against the current definition completes
- THEN objects failing the new definition are queryable via the validity-status filter
- AND their data is unchanged

<!-- @e2e exclude API/backend flow verified via Newman (openregister-schema-migration.postman_collection.json) + PHPUnit; no UI in this change (Phase 6.2 deferred). -->
#### Scenario: Filter by schema version
- GIVEN a mixed population stamped `1.3.0` and `2.0.0`
- WHEN objects are listed with a `schemaVersion` filter of `1.3.0`
- THEN only objects last validated under `1.3.0` are returned

### Requirement: Declarative migration runs MUST transform a population through the standard save pipeline

The system MUST execute migration plans: an ordered list of declarative
transforms over one schema's population, supporting at minimum:

- `rename` — move a property's value to a new name.
- `setDefault` — set a value where the property is missing/null.
- `cast` — convert a value's type (string↔number, string↔boolean,
  string→date via format); uncastable values are recorded as per-object
  failures.
- `drop` — remove a property.
- `compute` — set a property from a template over the object's own
  fields (Twig, consistent with the mapping/notification engines).

A plan MUST be previewable: applied to a bounded sample (default 10
objects) returning before/after pairs without persisting. Execution
MUST run batched in a background job, write every modified object
through the standard object save pipeline — so audit trail entries,
content versions, events/webhooks (bulk-suppression rules applying),
and system-context attribution all behave as for any other write — and
finish with a per-object report (migrated, skipped-unchanged, failed
with reason). A failed object MUST NOT abort the run by default;
`stopOnError: true` MUST halt at the first failure. Objects modified by
a migration MUST be re-stamped with the schema version they now
validate against.

<!-- @e2e exclude API/backend flow verified via Newman (openregister-schema-migration.postman_collection.json) + PHPUnit; no UI in this change (Phase 6.2 deferred). -->
#### Scenario: Preview shows before/after without persisting
- GIVEN a plan `[{rename: fullname → name}, {setDefault: status = "active"}]`
- WHEN the plan is previewed
- THEN up to 10 sample objects are returned as before/after pairs
- AND no object is modified

<!-- @e2e exclude API/backend flow verified via Newman (openregister-schema-migration.postman_collection.json) + PHPUnit; no UI in this change (Phase 6.2 deferred). -->
#### Scenario: Executed run migrates through the save pipeline
- GIVEN the same plan executed over 5,000 objects
- WHEN the run completes
- THEN every modified object has a new content version and an audit trail entry attributing the migration run
- AND the run report counts migrated, unchanged, and failed objects

<!-- @e2e exclude API/backend flow verified via Newman (openregister-schema-migration.postman_collection.json) + PHPUnit; no UI in this change (Phase 6.2 deferred). -->
#### Scenario: Uncastable value is reported, run continues
- GIVEN a plan with `cast: age → integer` and one object with `age: "unknown"`
- WHEN the run executes with default error policy
- THEN that object is recorded as failed with the cast error
- AND all other objects are migrated

<!-- @e2e exclude API/backend flow verified via Newman (openregister-schema-migration.postman_collection.json) + PHPUnit; no UI in this change (Phase 6.2 deferred). -->
#### Scenario: stopOnError halts the run
- GIVEN the same plan with `stopOnError: true`
- WHEN the first uncastable value is hit
- THEN the run stops in state `failed`
- AND the report identifies the failing object and how many objects were already migrated

### Requirement: Migration runs MUST be rollback-capable via content versioning

For every object a migration run modifies, the run MUST record the
object's pre-migration content-version identifier. Rolling back a
completed or failed run MUST restore each touched object to its
recorded pre-migration version, again through the standard save
pipeline (producing new audit/version entries — rollback is a forward
operation, not history erasure). Objects modified by *other* writers
after the migration MUST be skipped by rollback and listed in the
rollback report as conflicts. A rolled-back run enters state
`rolled-back` and cannot be rolled back twice.

<!-- @e2e exclude API/backend flow verified via Newman (openregister-schema-migration.postman_collection.json) + PHPUnit; no UI in this change (Phase 6.2 deferred). -->
#### Scenario: Rollback restores pre-migration versions
- GIVEN a completed migration run that modified 100 objects
- WHEN the run is rolled back
- THEN each of the 100 objects is restored to its recorded pre-migration version through the save pipeline
- AND the run state becomes `rolled-back`

<!-- @e2e exclude API/backend flow verified via Newman (openregister-schema-migration.postman_collection.json) + PHPUnit; no UI in this change (Phase 6.2 deferred). -->
#### Scenario: Post-migration edits are conflict-skipped
- GIVEN one of the migrated objects was edited by a user after the run
- WHEN the run is rolled back
- THEN that object is skipped
- AND the rollback report lists it as a conflict with its current version id

<!-- @e2e exclude API/backend flow verified via Newman (openregister-schema-migration.postman_collection.json) + PHPUnit; no UI in this change (Phase 6.2 deferred). -->
#### Scenario: Double rollback refused
- GIVEN a run in state `rolled-back`
- WHEN rollback is requested again
- THEN the request is refused with HTTP 409

### Requirement: Breaking schema changes MUST require explicit acknowledgement

A schema definition update classified `breaking` MUST be refused with
HTTP 409 and a structured response carrying the classification, the
typed change list, and (when available) the invalid-object count from
the most recent revalidation — unless the request carries
`acknowledgeBreaking: true`. This gate MUST apply uniformly to every
update path, including the runtime schema API used by openbuild and
virtual apps. Compatible changes MUST NOT require acknowledgement. The
acknowledgement (actor, timestamp) MUST be recorded on the changelog
entry.

<!-- @e2e exclude API/backend flow verified via Newman (openregister-schema-migration.postman_collection.json) + PHPUnit; no UI in this change (Phase 6.2 deferred). -->
#### Scenario: Unacknowledged breaking change refused
- GIVEN a schema update that removes property `email`
- WHEN the update is submitted without `acknowledgeBreaking`
- THEN the response is HTTP 409
- AND the body lists the classification `breaking` and the change `removed property email`

<!-- @e2e exclude API/backend flow verified via Newman (openregister-schema-migration.postman_collection.json) + PHPUnit; no UI in this change (Phase 6.2 deferred). -->
#### Scenario: Acknowledged breaking change proceeds
- GIVEN the same update submitted with `acknowledgeBreaking: true`
- WHEN it is processed
- THEN the schema is updated with a major version bump
- AND the changelog entry records the acknowledging actor

<!-- @e2e exclude API/backend flow verified via Newman (openregister-schema-migration.postman_collection.json) + PHPUnit; no UI in this change (Phase 6.2 deferred). -->
#### Scenario: Runtime schema API is gated identically
- GIVEN a breaking definition update arriving via the runtime schema API
- WHEN it lacks `acknowledgeBreaking`
- THEN it is refused with the same HTTP 409 contract as the direct API

<!-- @e2e exclude API/backend flow verified via Newman (openregister-schema-migration.postman_collection.json) + PHPUnit; no UI in this change (Phase 6.2 deferred). -->
#### Scenario: Compatible change needs no acknowledgement
- GIVEN an update adding an optional property
- WHEN it is submitted without `acknowledgeBreaking`
- THEN it succeeds

