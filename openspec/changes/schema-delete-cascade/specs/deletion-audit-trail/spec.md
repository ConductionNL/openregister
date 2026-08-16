---
status: draft
---
# Capability: `deletion-audit-trail`

## Purpose

Bring schema-teardown cascade deletion under the deletion audit contract. When a schema
is deleted with `?deleteObjects=true`, its objects are **hard-deleted** and their magic
table is **dropped**. This delta states how those objects are audited, and resolves the
tension with Requirement 4 ("permanent deletion MUST require prior soft delete"), which
governs the per-object trash API and never contemplated the table itself being destroyed.

## ADDED Requirements

### Requirement: Schema-teardown cascade MUST audit every object before hard-deleting it

Each object of a schema deleted with the cascade disposition (`DELETE /api/schemas/{id}?deleteObjects=true`) SHALL produce its **own** AuditTrail entry, capturing the object's complete state, written **before** the row is removed.
A single summary entry for the whole cascade SHALL NOT be sufficient.

Audit entries MUST be written through `AuditTrailMapper` so that ADR-003's SHA-256
hash-chaining applies at insert time. Entries MUST be written **inside** the same transaction
that deletes the rows and the schema, so that a rollback discards the entries and a committed
entry always implies a genuinely deleted object.

Audit entries survive the teardown: they live in `openregister_audit_trail`, which is a
separate table and is **not** dropped with the magic table. The deleted objects therefore
remain reconstructible from the audit trail after the schema is gone.

#### Scenario: Each cascaded object produces its own audit entry
- **GIVEN** schema `Cow` has 3 objects
- **WHEN** the schema is deleted with `?deleteObjects=true`
- **THEN** 3 AuditTrail entries are created, one per object, each containing the full
  pre-deletion snapshot of that object
- **AND** each entry carries `action: schema.cascade_delete`, the object's `objectUuid`,
  `changed.triggeredBy: schema_deletion`, `changed.cascadeContext.triggerSchema` set to the
  deleted schema's slug, and `user` set to the actor who initiated the schema deletion

#### Scenario: Audit entries are hash-chained
- **WHEN** cascade audit entries are written
- **THEN** each entry's `previousHash` is computed from the chain head and its `hash` is set
  before persisting, per ADR-003
- **AND** `verifyChain()` reports the entries as verified, not skipped

#### Scenario: Rollback discards the audit entries with the objects
- **GIVEN** a cascade delete is in progress and audit entries have been written
- **WHEN** the row deletion or the schema deletion subsequently fails
- **THEN** the transaction rolls back and no audit entry for the cascade remains
- **AND** the objects and the schema are unchanged

#### Scenario: Objects remain reconstructible after the table is dropped
- **GIVEN** schema `Cow` was cascade-deleted and its magic table dropped
- **WHEN** the audit trail is queried for the cascade
- **THEN** the full pre-deletion snapshot of every deleted object is still retrievable

### Requirement: Schema teardown is exempt from the prior-soft-delete rule

Requirement 4 ("Permanent deletion (purge) MUST require prior soft delete and authorization")
governs the **per-object trash lifecycle** — `DeletedController::destroy()` purging an object
that is already in the bin. It SHALL NOT apply to schema teardown.

Schema teardown hard-deletes objects directly, without a preceding soft delete. This is
permitted because the schema's magic table is itself dropped as part of the same operation: a
soft-delete tombstone would be written into a table that is destroyed moments later, offering
zero recoverability while producing two audit events per object instead of one. The guarantee
Requirement 4 protects — that nothing disappears without a reconstructible record — is
preserved instead by the per-object audit snapshot, which is written to a table that survives.

Schema teardown MUST still be authorized: it SHALL be gated on `checkSchemaManagePermission()`
for the target schema.

#### Scenario: Cascade hard-deletes without a prior soft delete
- **GIVEN** schema `Cow` has 1 object that has never been soft-deleted
- **WHEN** the schema is deleted with `?deleteObjects=true`
- **THEN** the object is hard-deleted directly, without first being soft-deleted
- **AND** the request is NOT rejected with "Object is not deleted"
- **AND** exactly one audit entry is produced for that object

#### Scenario: Already soft-deleted objects are also removed by the cascade
- **GIVEN** schema `Cow` has 1 live object and 1 already-soft-deleted object
- **WHEN** the schema is deleted with `?deleteObjects=true`
- **THEN** both rows are hard-deleted and the magic table is dropped
- **AND** no row survives the teardown in any state

#### Scenario: Cascade without manage permission is refused
- **WHEN** a caller who fails `checkSchemaManagePermission()` requests a cascade delete
- **THEN** the response is HTTP 403 and no object is deleted and no audit entry is written

### Requirement: Bulk schema-wide object deletion MUST produce per-object audit entries

`ObjectService::deleteObjectsBySchema()` SHALL produce one AuditTrail entry per deleted object, per Requirement 9 — both when reached via `POST /api/bulk/{register}/{schema}/delete-objects` and when used by the schema-teardown cascade.
Because `MagicMapper::deleteObjectsBySchema()` returns only a count, the service MUST collect the
object UUIDs and snapshots **before** issuing the delete.

Snapshot collection and audit writes SHALL be chunked, and schema-derived values SHALL be
resolved once for the whole operation rather than once per object, per ADR-009.

#### Scenario: Bulk delete audits each object
- **GIVEN** a register/schema pair with 5 objects
- **WHEN** `/api/bulk/{register}/{schema}/delete-objects` is called
- **THEN** 5 AuditTrail entries are created, each with the deleted object's full snapshot
- **AND** the response's `deleted_uuids` lists all 5 UUIDs

#### Scenario: Snapshots are collected before deletion
- **WHEN** a schema-wide object delete runs
- **THEN** the objects are read via `findAllInRegisterSchemaTable()` before any row is removed
- **AND** the resulting audit entries contain the objects' pre-deletion state, not empty snapshots
