---
status: implemented
---
# Deletion Audit Trail


# Deletion Audit Trail
## Purpose

Provide a comprehensive audit and lifecycle management system for all deletion operations in OpenRegister, encompassing soft delete (marking objects as deleted without physical removal), configurable retention before permanent purge, restore from soft delete, cascade delete tracking, and full GDPR-compliant audit trail entries. The spec ensures that every deletion -- whether user-initiated, cascade-triggered, or system-scheduled -- is recorded with sufficient context to reconstruct what happened, why, and by whom, satisfying Dutch government compliance requirements (BIO, AVG/GDPR Article 30, Archiefwet 1995, NEN-ISO 16175-1:2020).

This spec builds on the existing soft-delete infrastructure (`ObjectEntity.deleted`, `DeleteObject`, `DeletedController`) and integrates tightly with the immutable audit trail (`audit-trail-immutable` spec), archiving/destruction lifecycle (`archivering-vernietiging` spec), and referential integrity enforcement (`referential-integrity` spec).

## Requirements

### Requirement 1: Deletions MUST use soft delete by default, marking objects as deleted without physical removal

All delete operations via the API MUST perform a soft delete by setting the `deleted` JSON field on `ObjectEntity` with metadata about the deletion. The object MUST remain in the database and be excluded from normal queries but retrievable through the trash/deleted objects API.

#### Scenario: User-initiated soft delete via API
- **GIVEN** object `melding-1` exists in schema `meldingen` within register `gemeente`
- **AND** user `behandelaar-1` is authenticated
- **WHEN** `DELETE /api/objects/{register}/{schema}/melding-1` is called
- **THEN** `DeleteObject::delete()` MUST set `ObjectEntity.deleted` to a JSON object containing:
  - `deletedBy`: `behandelaar-1`
  - `deletedAt`: ISO 8601 timestamp of the deletion
  - `objectId`: the UUID of `melding-1`
  - `organisation`: the active organisation of the deleting user (resolved via `OrganisationMapper::getActiveOrganisationWithFallback()`)
- **AND** the object MUST remain in the database (soft delete, not physical removal)
- **AND** `MagicMapper::update()` MUST persist the updated entity with register and schema context

#### Scenario: Soft-deleted object excluded from normal queries
- **GIVEN** object `melding-1` has been soft-deleted (its `deleted` field is non-null)
- **WHEN** a user queries `GET /api/objects/{register}/{schema}` without the `_deleted` parameter
- **THEN** `MagicMapper` MUST exclude `melding-1` from results via the `_deleted IS NULL` filter condition
- **AND** the object MUST NOT appear in search results, facet counts, or collection responses

#### Scenario: Soft-deleted object still accessible with includeDeleted flag
- **GIVEN** object `melding-1` has been soft-deleted
- **WHEN** `MagicMapper::find()` is called with `includeDeleted: true`
- **THEN** the object MUST be returned with its `deleted` metadata intact
- **AND** the `@self.deleted` field in the JSON response MUST contain the deletion metadata

#### Scenario: System user deletion when no user session exists
- **GIVEN** a background job or system process triggers a deletion without an active user session
- **WHEN** `DeleteObject::delete()` resolves the user context
- **THEN** `deletedBy` MUST be set to `system`
- **AND** `organisation` MUST be set to `null` (no active organisation can be resolved)

#### Scenario: Cache invalidation after soft delete
- **GIVEN** object `melding-1` is soft-deleted
- **WHEN** `CacheHandler::invalidateForObjectChange()` is called with `operation: 'soft_delete'`
- **THEN** collection caches and facet caches for the object's register and schema MUST be invalidated
- **AND** if cache invalidation fails (e.g., Solr not configured), the soft delete MUST still succeed

### Requirement 2: The system MUST support configurable retention periods before purge

Soft-deleted objects MUST have a configurable retention period after which they become eligible for permanent purge. The `ObjectEntity::delete()` method MUST calculate a `purgeDate` based on the configured retention period, and a background job MUST handle automated purging.

#### Scenario: Purge date calculated from retention period
- **GIVEN** the retention settings specify `objectDeleteRetention` of 30 days
- **AND** user `admin` deletes object `zaak-100` on 2026-03-19
- **WHEN** `ObjectEntity::delete()` is called with `retentionPeriod: 30`
- **THEN** the `deleted` field MUST include `purgeDate: "2026-04-18T..."` (creation date + 30 days)
- **AND** `retentionPeriod: 30` MUST be stored in the deletion metadata

#### Scenario: Schema-level retention override
- **GIVEN** the global `objectDeleteRetention` is 30 days
- **AND** schema `vertrouwelijk-dossier` has `archive.deleteRetention: 365` (1 year)
- **WHEN** an object in `vertrouwelijk-dossier` is deleted
- **THEN** the `purgeDate` MUST be calculated as deletion date + 365 days
- **AND** the schema-level setting MUST override the global default

#### Scenario: Retention period configurable via settings API
- **GIVEN** an admin updates retention settings via `PUT /api/settings/retention`
- **WHEN** `objectDeleteRetention` is set to `7776000000` (90 days in milliseconds)
- **THEN** all subsequent deletions MUST use the new 90-day retention period for `purgeDate` calculation
- **AND** existing soft-deleted objects MUST retain their original `purgeDate`

#### Scenario: Government records enforce minimum retention
- **GIVEN** a register marked as `archive.governmentRecord: true`
- **WHEN** an admin attempts to set `objectDeleteRetention` below 10 years
- **THEN** the system MUST reject the setting with a validation error
- **AND** the minimum retention period for government records MUST be enforced per Archiefwet 1995

### Requirement 3: Soft-deleted objects MUST be restorable through the trash API

The `DeletedController` MUST provide endpoints for listing, restoring, and permanently deleting soft-deleted objects. Restoration MUST clear the `deleted` metadata and make the object visible in normal queries again.

#### Scenario: Restore a single soft-deleted object
- **GIVEN** object `melding-1` has been soft-deleted with `deleted.deletedBy: "admin"`
- **WHEN** `POST /api/deleted/melding-1/restore` is called
- **THEN** `DeletedController::restore()` MUST clear the `deleted` field by setting it to `null` via direct SQL update
- **AND** the object MUST become visible in normal queries (the `_deleted IS NULL` filter MUST match)
- **AND** the response MUST return `{"success": true, "message": "Object restored successfully"}`

#### Scenario: Restore multiple soft-deleted objects in bulk
- **GIVEN** objects `melding-1`, `melding-2`, and `melding-3` are soft-deleted
- **WHEN** `POST /api/deleted/restore` is called with body `{"ids": ["melding-1", "melding-2", "melding-3"]}`
- **THEN** `DeletedController::restoreMultiple()` MUST restore all three objects
- **AND** the response MUST include `{"restored": 3, "failed": 0, "notFound": 0}`

#### Scenario: Restore non-deleted object returns error
- **GIVEN** object `melding-4` exists but is NOT soft-deleted
- **WHEN** `POST /api/deleted/melding-4/restore` is called
- **THEN** the response MUST return HTTP 400 with `{"error": "Object is not deleted"}`

#### Scenario: Restore object not found returns error
- **GIVEN** no object with UUID `nonexistent-uuid` exists
- **WHEN** `POST /api/deleted/nonexistent-uuid/restore` is called
- **THEN** the response MUST return HTTP 500 with an appropriate error message

#### Scenario: Bulk restore with partial failures
- **GIVEN** 5 UUIDs are submitted for restoration, 3 are deleted, 1 is not deleted, 1 does not exist
- **WHEN** `POST /api/deleted/restore` is called with the 5 UUIDs
- **THEN** the response MUST include `{"restored": 3, "failed": 2, "notFound": 1}`

### Requirement 4: Permanent deletion (purge) MUST require prior soft delete and authorization

Objects MUST only be permanently deletable (hard delete) after they have been soft-deleted. The `DeletedController::destroy()` endpoint MUST verify the object is in soft-deleted state before allowing permanent removal. Admin-only access SHOULD be enforced for permanent deletion.

#### Scenario: Permanently delete a soft-deleted object
- **GIVEN** object `melding-1` is soft-deleted (has `deleted` metadata)
- **WHEN** `DELETE /api/deleted/melding-1` is called by an authenticated user
- **THEN** `DeletedController::destroy()` MUST verify that `$object->getDeleted()` is non-null
- **AND** `MagicMapper::delete()` MUST physically remove the object from the database
- **AND** the response MUST return `{"success": true, "message": "Object permanently deleted"}`

#### Scenario: Reject permanent deletion of non-deleted object
- **GIVEN** object `melding-2` exists but is NOT soft-deleted
- **WHEN** `DELETE /api/deleted/melding-2` is called
- **THEN** the response MUST return HTTP 400 with `{"error": "Object is not deleted"}`

#### Scenario: Permanently delete multiple objects in bulk
- **GIVEN** objects `melding-1`, `melding-2`, and `melding-3` are soft-deleted
- **WHEN** `DELETE /api/deleted` is called with body `{"ids": ["melding-1", "melding-2", "melding-3"]}`
- **THEN** `DeletedController::destroyMultiple()` MUST permanently delete all three
- **AND** the response MUST include `{"deleted": 3, "failed": 0, "notFound": 0}`

#### Scenario: Automated purge of expired soft-deleted objects
- **GIVEN** 10 soft-deleted objects have `purgeDate` before today's date
- **WHEN** the scheduled purge background job runs
- **THEN** all 10 objects MUST be permanently deleted from the database
- **AND** an audit trail entry MUST be created for each purged object with action `system.purge`

### Requirement 5: Full object snapshot MUST be preserved in the audit trail before deletion

When an object is deleted (soft or hard), the audit trail entry MUST capture the complete state of the object at the time of deletion, ensuring the data can be reconstructed for compliance, investigation, or recovery purposes.

#### Scenario: Audit trail entry for user-initiated deletion
- **GIVEN** object `melding-1` with title `Overlast`, status `afgehandeld`, and 5 custom properties
- **AND** audit trails are enabled (`isAuditTrailsEnabled()` returns `true`)
- **WHEN** the object is soft-deleted
- **THEN** `AuditTrailMapper::createAuditTrail(old: $objectEntity, new: null, action: 'delete')` MUST be called
- **AND** the resulting `AuditTrail` entry MUST contain:
  - `action`: `delete`
  - `object`: the internal ID of the deleted object
  - `objectUuid`: the UUID of the deleted object
  - `schema`: the internal ID of the schema
  - `register`: the internal ID of the register
  - `user`: the UID of the deleting user (or `System` for automated deletions)
  - `userName`: the display name of the deleting user
  - `session`: the PHP session ID
  - `request`: the Nextcloud request ID
  - `ipAddress`: the client's remote address
  - `size`: the byte size of the serialized object (via `strlen(serialize($objectEntity->jsonSerialize()))`, minimum 14 bytes)
  - `expires`: 30 days from creation (default)
- **AND** the full object state MUST be recoverable from the audit trail entry's reference to the old object

#### Scenario: Audit trail entry includes cascade context metadata
- **GIVEN** object `order-1` is deleted as part of a CASCADE operation triggered by deletion of `person-1`
- **WHEN** `DeleteObject::delete()` is called with `cascadeContext` metadata
- **THEN** the audit trail entry MUST have `action`: the cascade context's `action_type` (e.g., `referential_integrity.cascade_delete`)
- **AND** the `changed` field MUST include:
  - `triggeredBy`: `referential_integrity`
  - `cascadeContext.triggerObject`: UUID of `person-1`
  - `cascadeContext.triggerSchema`: slug of the person schema
  - `cascadeContext.action_type`: `referential_integrity.cascade_delete`
  - `cascadeContext.property`: the property name that created the reference

#### Scenario: Audit trail for root deletion with referential integrity summary
- **GIVEN** deleting `person-1` triggers CASCADE on 3 orders, SET_NULL on 2 tasks, and SET_DEFAULT on 1 contract
- **WHEN** `DeleteObject::delete()` is called with cascade context for the root object
- **THEN** the root deletion audit entry MUST have `action_type`: `referential_integrity.root_delete`
- **AND** the cascade context MUST include:
  - `cascadeDeleteCount`: 3
  - `setNullCount`: 2
  - `setDefaultCount`: 1

#### Scenario: No audit trail when audit trails are disabled
- **GIVEN** `auditTrailsEnabled` is set to `false` in retention settings
- **WHEN** an object is deleted
- **THEN** `isAuditTrailsEnabled()` MUST return `false`
- **AND** `createAuditTrail()` MUST NOT be called
- **AND** the deletion MUST still succeed (audit trail is not a prerequisite for deletion)

### Requirement 6: CASCADE deletions MUST create individual AuditTrail entries with trigger context

Each object deleted via CASCADE referential integrity MUST produce its own AuditTrail entry that traces back to the original trigger object, enabling full reconstruction of the cascade chain.

#### Scenario: Single cascade deletion
- **GIVEN** schema `order` with property `assignee` referencing schema `person` with `onDelete: CASCADE`
- **AND** order `order-1` references person `person-1`
- **WHEN** person `person-1` is deleted
- **THEN** an AuditTrail entry MUST be created for `order-1` with:
  - `action`: `referential_integrity.cascade_delete`
  - `objectUuid`: UUID of `order-1`
  - `changed.triggeredBy`: `referential_integrity`
  - `changed.cascadeContext.triggerObject`: UUID of `person-1`
  - `changed.cascadeContext.triggerSchema`: slug of the `person` schema
  - `changed.cascadeContext.property`: `assignee`
  - `user`: the user who initiated the original person deletion

#### Scenario: Chain cascade deletion across multiple levels
- **GIVEN** person -> order (CASCADE) -> order-line (CASCADE)
- **WHEN** person `person-1` is deleted
- **THEN** AuditTrail entries MUST be created for both the order deletion AND each order-line deletion
- **AND** each entry's `cascadeContext.triggerObject` MUST trace back to the root trigger: `person-1`
- **AND** `DeleteObject::getLastCascadeCount()` MUST return the total count of cascade-affected objects

#### Scenario: Cascade deletion within database transaction
- **GIVEN** person `person-1` has 5 related orders with CASCADE
- **WHEN** person `person-1` is deleted
- **THEN** `DeleteObject::executeIntegrityTransaction()` MUST wrap all operations in `IDBConnection::beginTransaction()` / `commit()`
- **AND** if any cascade operation fails, `IDBConnection::rollBack()` MUST be called
- **AND** ALL objects (including the root) MUST remain unchanged on failure

#### Scenario: Skip already soft-deleted objects during cascade
- **GIVEN** order `order-2` is already soft-deleted (has non-null `deleted` field)
- **AND** person `person-1` has CASCADE referencing `order-2`
- **WHEN** person `person-1` is deleted
- **THEN** `ReferentialIntegrityService` MUST skip `order-2` during cascade processing
- **AND** no duplicate audit trail entry MUST be created for `order-2`

### Requirement 7: SET_NULL and SET_DEFAULT actions MUST create AuditTrail entries

Each property modification via SET_NULL or SET_DEFAULT referential integrity MUST produce an AuditTrail entry recording the previous value, new value, trigger context, and affected property.

#### Scenario: SET_NULL on single property
- **GIVEN** schema `order` with property `assignee` referencing schema `person` with `onDelete: SET_NULL`
- **AND** order `order-1` has `assignee` = `person-1`
- **WHEN** person `person-1` is deleted
- **THEN** an AuditTrail entry MUST be created with:
  - `action`: `referential_integrity.set_null`
  - `objectUuid`: UUID of `order-1`
  - `changed`: containing `property: "assignee"`, `previousValue: "person-1"`, `newValue: null`, `triggerObject: "person-1"`, `triggerSchema: "person"`

#### Scenario: SET_DEFAULT on single property
- **GIVEN** schema `order` with property `assignee` referencing schema `person` with `onDelete: SET_DEFAULT`
- **AND** the property has `default: "system-user-uuid"`
- **AND** order `order-1` has `assignee` = `person-1`
- **WHEN** person `person-1` is deleted
- **THEN** an AuditTrail entry MUST be created with:
  - `action`: `referential_integrity.set_default`
  - `objectUuid`: UUID of `order-1`
  - `changed`: containing `property: "assignee"`, `previousValue: "person-1"`, `newValue: "system-user-uuid"`, `triggerObject: "person-1"`, `triggerSchema: "person"`

#### Scenario: SET_NULL on array property removes specific UUID
- **GIVEN** schema `team` with property `members` (array type, `items.$ref: "person"`, `onDelete: SET_NULL`)
- **AND** team `team-1` has `members: ["person-1", "person-2", "person-3"]`
- **WHEN** person `person-2` is deleted
- **THEN** `members` MUST be updated to `["person-1", "person-3"]` (UUID removed from array, not entire property nullified)
- **AND** the audit entry MUST record `previousValue: ["person-1", "person-2", "person-3"]` and `newValue: ["person-1", "person-3"]`

### Requirement 8: RESTRICT blocks MUST create AuditTrail entries and return structured errors

When a deletion is blocked by RESTRICT, an AuditTrail entry MUST record the blocked attempt, and the API MUST return HTTP 409 Conflict with a structured error body listing the blocking references.

#### Scenario: Deletion blocked by RESTRICT
- **GIVEN** schema `order` with property `assignee` referencing schema `person` with `onDelete: RESTRICT`
- **AND** 3 orders reference person `person-1`
- **WHEN** deletion of person `person-1` is attempted
- **THEN** `ReferentialIntegrityService::logRestrictBlock()` MUST create an AuditTrail entry with:
  - `action`: `referential_integrity.restrict_blocked`
  - `objectUuid`: UUID of `person-1` (the object that was NOT deleted)
  - `changed`: containing `blockerCount: 3`, `blockerSchema: "order"`, `blockerProperty: "assignee"`, `reason: "RESTRICT constraint prevents deletion"`
- **AND** `DeleteObject::deleteObject()` MUST throw `ReferentialIntegrityException`
- **AND** the API response MUST be HTTP 409 with `ReferentialIntegrityException::toResponseBody()` listing each blocker's UUID, schema, and property

#### Scenario: RESTRICT block with multiple blocking schemas
- **GIVEN** person `person-1` is referenced by 2 orders (RESTRICT) and 1 task (RESTRICT)
- **WHEN** deletion of person `person-1` is attempted
- **THEN** the `DeletionAnalysis.blockers` MUST contain entries from both schemas
- **AND** the RESTRICT audit entry MUST record all blocking schemas and their counts

#### Scenario: Pre-flight deletion analysis
- **GIVEN** person `person-1` has complex referential integrity dependencies
- **WHEN** `DeleteObject::canDelete($object)` is called (without actually deleting)
- **THEN** `ReferentialIntegrityService::canDelete()` MUST return a `DeletionAnalysis` DTO with:
  - `deletable`: `true` or `false`
  - `cascadeTargets`: array of objects that would be cascade-deleted
  - `nullifyTargets`: array of objects that would have properties nullified
  - `defaultTargets`: array of objects that would have properties set to default
  - `blockers`: array of RESTRICT blockers (if any)
  - `chainPaths`: the full graph traversal paths
- **AND** no mutations MUST occur during the pre-flight analysis

### Requirement 9: Bulk delete operations MUST produce per-object audit trail entries

When multiple objects are deleted in a single bulk operation, each object MUST receive its own audit trail entry, and the response MUST include aggregate counts of all affected objects (including cascades).

#### Scenario: Bulk delete with CASCADE
- **GIVEN** 10 persons are selected for bulk deletion via `DELETE /api/objects/{register}/{schema}`
- **AND** each person has 2 related orders with `onDelete: CASCADE`
- **WHEN** the bulk delete is executed
- **THEN** `ObjectService::deleteObjects()` MUST call `DeleteObject::deleteObject()` for each person individually
- **AND** 30 audit trail entries MUST be created (10 root deletions + 20 cascade deletions)
- **AND** the response MUST include `cascade_count: 20` and `total_affected: 30`

#### Scenario: Bulk delete with RESTRICT-blocked items
- **GIVEN** 5 persons are selected for bulk deletion
- **AND** 2 persons have RESTRICT-constrained references
- **WHEN** the bulk delete is executed
- **THEN** the 3 unrestricted persons MUST be deleted with their cascades
- **AND** the 2 restricted persons MUST be skipped
- **AND** the response MUST include `skipped_uuids: ["uuid-4", "uuid-5"]` with the restriction reason
- **AND** RESTRICT audit entries MUST be created for the 2 blocked attempts

#### Scenario: Bulk delete transaction isolation
- **GIVEN** 100 objects are selected for bulk deletion
- **WHEN** the bulk delete is executed
- **THEN** each object's integrity check and cascade MUST run within its own transaction scope (via `executeIntegrityTransaction()`)
- **AND** a failure on object #50 MUST NOT roll back deletions of objects #1-#49
- **AND** the response MUST report partial success with counts of successful and failed deletions

### Requirement 10: The delete API response MUST include audit trail reference information

The API response for successful deletion operations MUST provide sufficient information for the caller to reference the audit trail entry, enabling downstream systems to correlate the deletion with its audit record.

#### Scenario: Delete response with audit reference
- **GIVEN** object `melding-1` is deleted successfully with audit trails enabled
- **WHEN** the delete API returns
- **THEN** the response SHOULD include the cascade count via `DeleteObject::getLastCascadeCount()`
- **AND** the last audit log entry MUST be attached to the object via `$savedEntity->setLastLog($log->jsonSerialize())`

#### Scenario: Delete response without audit (disabled)
- **GIVEN** audit trails are disabled
- **WHEN** an object is deleted
- **THEN** the response MUST still confirm successful deletion
- **AND** no audit reference MUST be included

#### Scenario: Cascade delete response includes affected count
- **GIVEN** deleting `person-1` triggers CASCADE on 5 orders
- **WHEN** the delete operation completes
- **THEN** `DeleteObject::getLastCascadeCount()` MUST return 5
- **AND** the API response SHOULD include the cascade count for client-side display

### Requirement 11: The trash/recycle bin API MUST support listing, filtering, and statistics for deleted objects

The `DeletedController` MUST provide a full API for managing soft-deleted objects including paginated listing, filtering by schema/register, deletion statistics, and top deleter analytics.

#### Scenario: List all soft-deleted objects with pagination
- **GIVEN** 50 soft-deleted objects exist across multiple schemas
- **WHEN** `GET /api/deleted?_limit=20&_page=1` is called
- **THEN** the response MUST include:
  - `results`: array of 20 soft-deleted objects (serialized with `@self.deleted` metadata)
  - `total`: 50
  - `page`: 1
  - `pages`: 3
  - `limit`: 20
  - `offset`: 0
- **AND** results MUST be sorted by `updated DESC` by default (most recently deleted first)

#### Scenario: Filter deleted objects by schema
- **GIVEN** 30 deleted objects in schema `meldingen` and 20 in schema `taken`
- **WHEN** `GET /api/deleted?schema={schemaId}` is called with the `meldingen` schema ID
- **THEN** only the 30 deleted `meldingen` objects MUST be returned

#### Scenario: Admin sees all deleted objects across organisations
- **GIVEN** the current user is an admin (verified via `isCurrentUserAdmin()`)
- **WHEN** `GET /api/deleted` is called
- **THEN** multitenancy filtering MUST be disabled for admins
- **AND** deleted objects from all organisations MUST be returned

#### Scenario: Deletion statistics
- **GIVEN** various objects have been deleted over time
- **WHEN** `GET /api/deleted/statistics` is called
- **THEN** the response MUST include:
  - `totalDeleted`: total count of soft-deleted objects
  - `deletedToday`: count of objects deleted today
  - `deletedThisWeek`: count of objects deleted in the last 7 days
  - `oldestDays`: age in days of the oldest soft-deleted object

### Requirement 12: Search and listing MUST exclude soft-deleted objects by default

All normal object queries (list, search, faceted search) MUST exclude soft-deleted objects unless the caller explicitly requests their inclusion. This ensures deleted objects do not appear in user-facing search results.

#### Scenario: Standard object listing excludes deleted objects
- **GIVEN** register `gemeente` contains 100 active objects and 10 soft-deleted objects
- **WHEN** `GET /api/objects/{register}/{schema}` is called
- **THEN** `MagicMapper` MUST apply the `_deleted IS NULL` filter (or `_deleted IS NULL OR _deleted = 'null'::jsonb` for PostgreSQL)
- **AND** only the 100 active objects MUST be returned
- **AND** the `total` count MUST be 100 (excluding deleted)

#### Scenario: Search excludes deleted objects
- **GIVEN** a soft-deleted object `melding-1` with title `Geluidsoverlast`
- **WHEN** `GET /api/objects/{register}/{schema}?_search=Geluidsoverlast` is called
- **THEN** `melding-1` MUST NOT appear in search results

#### Scenario: Facet counts exclude deleted objects
- **GIVEN** 5 objects with status `afgehandeld`, 2 of which are soft-deleted
- **WHEN** faceted search returns aggregation counts
- **THEN** the count for `afgehandeld` MUST be 3 (not 5)

#### Scenario: Count queries exclude deleted objects
- **GIVEN** 100 total objects, 10 of which are soft-deleted
- **WHEN** `MagicMapper::countAll()` is called without explicit deleted inclusion
- **THEN** the count MUST return 90

### Requirement 13: AuditTrail entries for all referential integrity actions MUST include the initiating user context

All referential integrity AuditTrail entries (CASCADE, SET_NULL, SET_DEFAULT, RESTRICT) MUST carry the identity of the user who initiated the original deletion that triggered the cascade chain, ensuring accountability even for system-triggered mutations.

#### Scenario: User context propagation through cascade chain
- **GIVEN** user `admin` deletes person `person-1`
- **WHEN** CASCADE actions create AuditTrail entries for affected orders and order-lines
- **THEN** each AuditTrail entry MUST have `user: "admin"` and `userName` set to admin's display name
- **AND** the user context MUST be consistent across all entries in the cascade chain

#### Scenario: API consumer context via JWT
- **GIVEN** a JWT-authenticated external consumer deletes an object
- **WHEN** cascade actions create AuditTrail entries
- **THEN** each entry MUST have `user` set to the consumer's mapped Nextcloud user ID (resolved via `IUserSession`)

#### Scenario: Session and request context propagation
- **GIVEN** a delete request with session ID `abc123` and Nextcloud request ID `req-456`
- **WHEN** cascade AuditTrail entries are created
- **THEN** each entry MUST carry `session: "abc123"` and `request: "req-456"`
- **AND** the `ipAddress` MUST be the IP of the original requesting client

### Requirement 14: GDPR right to erasure MUST be reconciled with audit trail retention for deletion records

When a data subject exercises their right to erasure (AVG Article 17), deletion audit trail entries MUST balance the obligation to erase personal data with the legal obligation to retain audit records. Audit records are exempt from erasure under AVG Article 17(3)(b) (legal claims) and Article 17(3)(e) (archival in public interest).

#### Scenario: Erasure request for personal data referenced in deletion audit trail
- **GIVEN** a data subject requests erasure of all their personal data
- **AND** deletion audit trail entries exist that reference this person's data in the `changed` field
- **WHEN** the erasure is processed
- **THEN** personal data within the `changed` field of relevant audit entries MUST be pseudonymized (replaced with hashed identifiers)
- **AND** the `user` field MUST NOT be pseudonymized if it refers to the acting official (not the data subject)
- **AND** the audit entry MUST remain in the chain to preserve integrity
- **AND** a new audit entry with action `gdpr.pseudonymized` MUST record the pseudonymization operation

#### Scenario: Distinguish data subject from deleting actor
- **GIVEN** user `medewerker-1` deletes an object containing personal data of citizen `burger-123`
- **WHEN** `burger-123` requests erasure
- **THEN** `medewerker-1` in the `user` field MUST NOT be erased (they are the actor)
- **AND** personal data of `burger-123` in the `changed` field MUST be pseudonymized

#### Scenario: Deletion audit retained during legal hold
- **GIVEN** deletion audit trail entries are subject to a legal hold (per `archivering-vernietiging` spec)
- **WHEN** an erasure request conflicts with the legal hold
- **THEN** pseudonymization MUST still proceed (data minimization)
- **BUT** the audit entry itself MUST NOT be deleted until the legal hold is lifted

### Requirement 15: NO_ACTION deletions MUST NOT create referential integrity audit entries

The NO_ACTION `onDelete` behavior means no referential integrity action is taken, so no integrity-specific audit entry is needed. The standard `delete` audit entry for the root object MUST still be created.

#### Scenario: No action produces no integrity audit
- **GIVEN** schema `order` with property `assignee` referencing schema `person` with `onDelete: NO_ACTION`
- **WHEN** person `person-1` is deleted
- **THEN** NO AuditTrail entry with action prefix `referential_integrity.*` MUST be created for any order
- **AND** the standard `delete` audit entry for `person-1` MUST still be created

#### Scenario: Mixed actions include NO_ACTION properties
- **GIVEN** person `person-1` is referenced by orders (CASCADE) and by tasks (NO_ACTION)
- **WHEN** person `person-1` is deleted
- **THEN** CASCADE audit entries MUST be created for the orders
- **AND** NO integrity audit entries MUST be created for the tasks
- **AND** the tasks MUST retain their now-broken references (eventual consistency)

## Current Implementation Status
- **Fully implemented:**
  - `DeleteObject` (`lib/Service/Object/DeleteObject.php`) implements soft delete with:
    - `delete()`: Sets `ObjectEntity.deleted` with `deletedBy`, `deletedAt`, `objectId`, `organisation` metadata; creates audit trail with cascade context tagging; invalidates collection and facet caches via `CacheHandler::invalidateForObjectChange(operation: 'soft_delete')`
    - `deleteObject()`: Orchestrates referential integrity checks via `handleIntegrityDeletion()`, manages cascade count tracking, wraps integrity operations in database transactions via `executeIntegrityTransaction()`
    - `canDelete()`: Pre-flight deletion analysis via `ReferentialIntegrityService::canDelete()` returning `DeletionAnalysis` DTO
    - `getLastCascadeCount()`: Returns count of cascade-affected objects from last deletion
  - `ObjectEntity` (`lib/Db/ObjectEntity.php`) with `deleted` JSON field storing deletion metadata (`deletedBy`, `deletedAt`, `purgeDate`, `retentionPeriod`, `deletedReason`); `delete()` method calculates purge date (currently hardcoded to 31 days, `@todo` at line 927 to use actual `retentionPeriod` parameter)
  - `DeletedController` (`lib/Controller/DeletedController.php`) with complete trash/recycle bin API:
    - `GET /api/deleted` -- list soft-deleted objects with pagination, sorting, filtering
    - `GET /api/deleted/statistics` -- deletion statistics (total, today, this week)
    - `GET /api/deleted/top-deleters` -- top deleters analytics (stub)
    - `POST /api/deleted/{id}/restore` -- restore single object (clears `deleted` via direct SQL)
    - `POST /api/deleted/restore` -- restore multiple objects
    - `DELETE /api/deleted/{id}` -- permanently delete single object
    - `DELETE /api/deleted` -- permanently delete multiple objects
  - `ReferentialIntegrityService` (`lib/Service/Object/ReferentialIntegrityService.php`) creates AuditTrail entries for all integrity actions:
    - `referential_integrity.cascade_delete` -- logged when objects are cascade-deleted
    - `referential_integrity.set_null` -- logged when properties are nullified
    - `referential_integrity.set_default` -- logged when properties are reset to default
    - `referential_integrity.restrict_blocked` -- logged when deletion is blocked by RESTRICT
    - `referential_integrity.root_delete` -- logged for root object with cascade summary counts
  - `AuditTrailMapper::createAuditTrail()` (`lib/Db/AuditTrailMapper.php`) records full deletion context: user, userName, session, request ID, IP address, object size, schema/register IDs, default 30-day expiry
  - `AuditHandler` (`lib/Service/Object/AuditHandler.php`) orchestrates audit trail creation
  - `MagicMapper` (`lib/Db/MagicMapper.php`) excludes soft-deleted objects from normal queries via `_deleted IS NULL` filter; supports `includeDeleted` flag for trash access; PostgreSQL-compatible with `_deleted = 'null'::jsonb` handling
  - Chain cascade deletions tracked with trigger object context via `cascadeContext` parameter
  - User context propagated through cascade chains via `resolveUserContext()`
  - Transaction atomicity via `IDBConnection::beginTransaction()` / `commit()` / `rollBack()` in `executeIntegrityTransaction()`
  - Circular reference detection via visited-set and `MAX_DEPTH = 10` in `ReferentialIntegrityService`

- **NOT fully implemented:**
  - `ObjectEntity::delete()` purge date calculation is hardcoded to 31 days (the `$retentionPeriod` parameter is accepted but not used; see `@todo` at line 927)
  - Automated purge background job for expired soft-deleted objects (no `PurgeExpiredJob` exists)
  - Schema-level delete retention override (retention is global only via `ObjectRetentionHandler`)
  - Restore audit trail entries (restoring an object does not currently create an audit entry)
  - `DeletedController::topDeleters()` returns mock data (aggregation query not implemented)
  - `DeletedController::restoreMultiple()` and `destroyMultiple()` lack register/schema filtering (noted as TODO: "unsafe")
  - GDPR pseudonymization of deletion audit trail entries
  - Delete notification/webhook integration (no `INotifier` notification on deletion)
  - Permanent delete audit trail entry (hard delete via `DeletedController::destroy()` does not create an audit entry)

## Standards & References
- **SQL Standard** -- Referential integrity actions (CASCADE, SET NULL, SET DEFAULT, RESTRICT, NO ACTION) per ISO/IEC 9075
- **AVG / GDPR** -- Article 17 right to erasure with exceptions under Article 17(3)(b) and (e); Article 30 processing records requirement
- **BIO (Baseline Informatiebeveiliging Overheid)** -- Dutch government information security baseline; controls A.12.4.1 (event logging), A.12.4.2 (protection of log information)
- **BIO2** -- Updated BIO framework with enhanced logging requirements
- **Archiefwet 1995** -- Dutch archival law; minimum retention periods for government records
- **NEN-ISO 16175-1:2020** -- Records management standard; audit trail requirements for record-keeping systems
- **NEN 2082** -- Records management audit trail requirements (predecessor to NEN-ISO 16175-1:2020)
- **HTTP 409 Conflict** (RFC 9110) -- For RESTRICT violations preventing deletion
- **HTTP 204 No Content** (RFC 9110) -- Standard response for successful deletion

## Cross-Referenced Specs
- **audit-trail-immutable** -- Defines the immutable audit trail system that deletion audit entries are part of; hash chaining, retention, immutability enforcement, and export all apply to deletion audit entries
- **archivering-vernietiging** -- Archival destruction workflows use the deletion infrastructure; `archiefactiedatum`-based destruction interacts with soft delete and purge; legal holds block deletion
- **referential-integrity** -- Defines CASCADE, SET_NULL, SET_DEFAULT, RESTRICT, NO_ACTION behaviors; `ReferentialIntegrityService` drives the cascade deletion logic; `DeletionAnalysis` DTO captures the full dependency graph
- **content-versioning** -- Version history built on audit trail entries; deletion creates a terminal version entry; reversion from audit trail can restore deleted objects

## Specificity Assessment
- The spec is comprehensive and largely implemented. Soft delete, audit trail creation, cascade tracking, trash API, and referential integrity auditing are all production-ready.
- Key gaps: (1) the `ObjectEntity::delete()` purge date hardcoding needs fixing, (2) no automated purge background job, (3) restore operations do not create audit entries, (4) permanent delete does not create audit entries, (5) GDPR pseudonymization is not implemented.
- Open questions:
  - Should the automated purge job run as a `TimedJob` (hourly/daily) or as a `QueuedJob` triggered by the existing `LogCleanUpTask`?
  - Should restore operations be restricted by RBAC (only the original deleter or an admin can restore)?
  - How should permanent delete of objects with active legal holds be handled (block entirely, or require explicit override)?
  - Should the trash API support filtering by `purgeDate` range to identify objects approaching permanent deletion?

## Nextcloud Integration Analysis

- **Status**: Substantially implemented in OpenRegister. Soft delete, cascade audit trail, trash API, and referential integrity auditing are production-ready. Purge automation and GDPR pseudonymization are documented enhancements.
- **Existing Implementation**: `DeleteObject` handles soft delete with full audit trail creation including cascade context tagging. `DeletedController` provides a complete trash management API (list, restore, permanent delete, statistics). `ReferentialIntegrityService` logs all integrity actions with dedicated action types. `MagicMapper` excludes soft-deleted objects from normal queries. `AuditTrailMapper::createAuditTrail()` captures full object context on deletion.
- **Nextcloud Core Integration**: Uses NC's `Entity`/`QBMapper` patterns for object persistence. `IDBConnection` for transaction management (`beginTransaction`/`commit`/`rollBack`). `IUserSession` for user context resolution. `IRequest` for session and IP context. `ObjectDeletedEvent` fired via `IEventDispatcher` for other NC apps to listen to. `INotifier` integration pending for deletion notifications. Background purge job should use `TimedJob` (`OCP\BackgroundJob\TimedJob`).
- **Recommendation**: Priority enhancements: (1) Fix `ObjectEntity::delete()` to use actual `retentionPeriod` parameter instead of hardcoded 31 days, (2) Create `PurgeExpiredObjectsJob` background job to automatically hard-delete objects past their `purgeDate`, (3) Add audit trail entries for restore and permanent delete operations in `DeletedController`, (4) Add register/schema filtering to `restoreMultiple()` and `destroyMultiple()` (security fix), (5) Implement `INotifier` notifications when objects are deleted or approaching purge date.
