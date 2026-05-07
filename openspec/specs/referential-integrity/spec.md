---
status: implemented
---

# Referential Integrity

## Purpose
Enforce referential integrity between register objects connected via `$ref` schema properties so that modifications or deletions of referenced objects propagate correctly according to configurable integrity actions (CASCADE, SET_NULL, SET_DEFAULT, RESTRICT, NO_ACTION). The system MUST maintain data consistency across schemas, detect circular reference chains, support cross-register references, and provide auditable, transactional enforcement that prevents orphaned references while respecting performance constraints on deep reference graphs.

**Source**: Core OpenRegister capability for data consistency across related objects. Aligns with SQL standard referential integrity semantics adapted for a document-oriented register model with JSON Schema `$ref` relations.

**Cross-references**: reference-existence-validation (save-time validation), deletion-audit-trail (audit logging for integrity actions), content-versioning (version impact of cascade mutations).

## Requirements

### Requirement 1: Schema properties with $ref MUST support configurable onDelete behavior
Properties that reference other schemas via `$ref` MUST define what happens when the referenced object is deleted. The system MUST support five onDelete actions: `CASCADE`, `SET_NULL`, `SET_DEFAULT`, `RESTRICT`, and `NO_ACTION` (default). The `onDelete` value MUST be stored on the schema property definition alongside `$ref` and SHALL be validated against the `VALID_ON_DELETE_ACTIONS` constant in `ReferentialIntegrityService`.

#### Scenario: Configure CASCADE delete
- **GIVEN** schema `order` with property `assignee` referencing schema `person` via `$ref`
- **AND** the property has `onDelete: CASCADE`
- **WHEN** person `person-1` is deleted
- **THEN** all orders referencing `person-1` MUST also be soft-deleted
- **AND** cascade deletions MUST be recursive (if orders have dependent objects with CASCADE, those cascade too)
- **AND** each cascade-deleted object MUST appear in the `DeletionAnalysis.cascadeTargets` array

#### Scenario: Configure SET_NULL on a non-required property
- **GIVEN** schema `order` with property `assignee` referencing schema `person` with `onDelete: SET_NULL`
- **AND** `assignee` is NOT in the schema's `required` array
- **WHEN** person `person-1` is deleted
- **THEN** all orders with `assignee: "person-1"` MUST have `assignee` set to `null`
- **AND** the orders themselves MUST NOT be deleted
- **AND** `ReferentialIntegrityService::applySetNull()` MUST update via `MagicMapper::update()`

#### Scenario: SET_NULL falls back to RESTRICT on required property
- **GIVEN** schema `order` with property `assignee` referencing schema `person` with `onDelete: SET_NULL`
- **AND** `assignee` IS in the schema's `required` array
- **WHEN** person `person-1` deletion is analyzed via `canDelete()`
- **THEN** `ReferentialIntegrityService::isRequiredProperty()` MUST detect the required constraint
- **AND** the dependent orders MUST appear as blockers (not nullify targets)
- **AND** the chain path MUST include the annotation `(SET_NULL on required -> RESTRICT)`

#### Scenario: Configure SET_DEFAULT with a valid default value
- **GIVEN** schema `order` with property `assignee` referencing schema `person` with `onDelete: SET_DEFAULT`
- **AND** the property has `default: "system-user-uuid"`
- **WHEN** person `person-1` is deleted
- **THEN** all orders with `assignee: "person-1"` MUST have `assignee` set to `"system-user-uuid"`
- **AND** `ReferentialIntegrityService::getDefaultValue()` MUST resolve the default from the schema property definition

#### Scenario: SET_DEFAULT without a default falls back to SET_NULL or RESTRICT
- **GIVEN** schema `order` with property `assignee` with `onDelete: SET_DEFAULT` but no `default` defined
- **AND** `assignee` is NOT required
- **WHEN** person `person-1` is deleted
- **THEN** `getDefaultValue()` returns `null`, so the system MUST fall back to SET_NULL behavior
- **AND** `assignee` MUST be set to `null`

#### Scenario: Configure RESTRICT
- **GIVEN** schema `order` with property `assignee` referencing schema `person` with `onDelete: RESTRICT`
- **AND** 3 orders reference person `person-1`
- **WHEN** deletion of person `person-1` is attempted
- **THEN** `DeletionAnalysis.deletable` MUST be `false`
- **AND** `DeletionAnalysis.blockers` MUST contain 3 entries, each with `objectUuid`, `schema`, `property`, and `action: RESTRICT`
- **AND** `DeleteObject::deleteObject()` MUST throw `ReferentialIntegrityException`
- **AND** the API MUST return HTTP 409 Conflict with `ReferentialIntegrityException::toResponseBody()` containing the blocker list

#### Scenario: Configure NO_ACTION (default)
- **GIVEN** no `onDelete` is specified on the property (defaults to NO_ACTION)
- **WHEN** the referenced person is deleted
- **THEN** `ReferentialIntegrityService::extractOnDelete()` returns `null` or `NO_ACTION`
- **AND** the property is skipped during relation indexing
- **AND** orders with the now-broken reference MUST NOT be modified
- **AND** the broken reference is the caller's responsibility (eventual consistency)

### Requirement 2: Referential integrity MUST apply within database transactions
All integrity actions (CASCADE, SET_NULL, SET_DEFAULT) and the root deletion MUST be atomic. `DeleteObject::executeIntegrityTransaction()` MUST wrap all operations in `IDBConnection::beginTransaction()` / `commit()` / `rollBack()`.

#### Scenario: Atomic CASCADE with rollback on failure
- **GIVEN** person `person-1` has 5 related orders with CASCADE
- **WHEN** person `person-1` is deleted
- **AND** the 4th order cascade-delete fails (e.g., database error)
- **THEN** `IDBConnection::rollBack()` MUST be called
- **AND** ALL 5 orders, plus the person, MUST remain unchanged in the database
- **AND** the error MUST be logged via `LoggerInterface::error()` with context including UUID and error message

#### Scenario: Mixed actions in a single transaction
- **GIVEN** person `person-1` is referenced by 2 orders (CASCADE) and 3 tasks (SET_NULL)
- **WHEN** person `person-1` is deleted
- **THEN** `applyDeletionActions()` MUST process SET_NULL first, then SET_DEFAULT, then CASCADE (deepest first)
- **AND** all 5 mutations plus the root delete MUST succeed or all MUST roll back
- **AND** `DeleteObject::getLastCascadeCount()` MUST return 5 (2 cascade + 3 nullify)

#### Scenario: Nested transaction via Doctrine savepoints
- **GIVEN** a CASCADE chain: person -> order -> line-item (all CASCADE)
- **WHEN** person is deleted
- **THEN** Nextcloud's database abstraction (Doctrine DBAL) MUST handle nested transactions via savepoints
- **AND** the graph walk in `walkDeletionGraph()` MUST recurse to depth 2 and collect all targets before mutations begin

### Requirement 3: Circular references MUST be detected and handled safely
The system MUST detect circular reference chains and prevent infinite cascades. `ReferentialIntegrityService` MUST enforce two safeguards: visited-UUID tracking (cycle detection) and `MAX_DEPTH = 10` (depth limiting).

#### Scenario: Circular CASCADE detection via visited set
- **GIVEN** schema A references schema B (CASCADE) and schema B references schema A (CASCADE)
- **AND** object `a-1` references `b-1`, and `b-1` references `a-1`
- **WHEN** object `a-1` is deleted
- **THEN** `walkDeletionGraph()` MUST add `a-1` to the `$visited` array
- **AND** when recursion reaches `a-1` again, `in_array($uuid, $visited)` MUST return `true`
- **AND** the recursion MUST return `DeletionAnalysis::empty()` for that branch
- **AND** each object MUST be processed at most once

#### Scenario: Depth limit prevents pathological chains
- **GIVEN** a chain of 15 schemas each referencing the next with CASCADE
- **WHEN** the root object is deleted
- **THEN** `walkDeletionGraph()` MUST stop at `$depth >= MAX_DEPTH` (10)
- **AND** a warning MUST be logged: `[ReferentialIntegrity] Max depth reached during graph walk`
- **AND** objects beyond depth 10 MUST NOT be cascade-deleted (treated as NO_ACTION)

#### Scenario: Self-referencing schema
- **GIVEN** schema `category` has property `parentCategory` referencing itself with `onDelete: CASCADE`
- **AND** a tree: root -> child-1 -> child-2 -> child-3
- **WHEN** `root` is deleted
- **THEN** `child-1`, `child-2`, and `child-3` MUST all be cascade-deleted
- **AND** the visited set MUST prevent re-processing if any child also references another in the chain

### Requirement 4: Reference validation MUST be configurable on save
The system MUST support a `validateReference` boolean on schema properties. When enabled, the save pipeline SHALL verify that the UUID stored in a `$ref` property corresponds to an existing object in the target schema before persisting. See the reference-existence-validation spec for full details.

#### Scenario: Validate reference on save (enabled)
- **GIVEN** property `assignee` with `$ref: "person"` and `validateReference: true`
- **WHEN** an order is created with `assignee: "nonexistent-uuid"`
- **THEN** `SaveObject::validateReferences()` MUST reject the save with HTTP 422
- **AND** the error message MUST include: `"Referenced object 'nonexistent-uuid' not found in schema 'person' for property 'assignee'"`

#### Scenario: Validate reference on save (disabled, default)
- **GIVEN** property `assignee` with `$ref: "person"` and no `validateReference` set
- **WHEN** an order is created with `assignee: "nonexistent-uuid"`
- **THEN** the save MUST succeed (eventual consistency pattern)
- **AND** the reference MAY become broken if the UUID never exists

#### Scenario: Array reference with partial invalid UUIDs
- **GIVEN** property `members` is `type: array` with `items.$ref: "person"` and `validateReference: true`
- **WHEN** an object is saved with `members: ["valid-uuid", "nonexistent-uuid"]`
- **THEN** the save MUST fail with HTTP 422 identifying `nonexistent-uuid` as invalid

#### Scenario: Update with unchanged reference skips validation
- **GIVEN** an existing object with `assignee: "person-uuid"` and `validateReference: true`
- **AND** the referenced person has since been soft-deleted
- **WHEN** the object is updated with `assignee: "person-uuid"` (same value)
- **THEN** `SaveObject::validateReferences()` MUST skip validation for unchanged values
- **AND** the save MUST succeed

### Requirement 5: Orphan detection and cleanup MUST be supported for inversedBy relations
When a parent object is updated and sub-objects are removed from an `inversedBy` array property, the system MUST detect and soft-delete orphaned sub-objects. `SaveObject::deleteOrphanedRelatedObjects()` handles this cleanup.

#### Scenario: Sub-objects removed during update are soft-deleted
- **GIVEN** an `incident` object with property `notes` (array, `inversedBy: "incident"`, `cascade: true`)
- **AND** the incident has 3 notes: `[note-1, note-2, note-3]`
- **WHEN** the incident is updated with `notes: [note-1, note-3]`
- **THEN** `note-2` MUST be detected as orphaned via `array_diff($oldUuids, $newUuids)`
- **AND** `note-2` MUST be soft-deleted with deletion metadata `reason: "orphaned-related-object"`

#### Scenario: Orphan removal respects writeBack configuration
- **GIVEN** a property with `inversedBy` and `writeBack: true`
- **WHEN** the parent object is updated and sub-objects are removed
- **THEN** `SaveObject` MUST skip orphan cleanup for writeBack-enabled properties (handled by the write-back method instead)

#### Scenario: No orphan removal for properties without cascade
- **GIVEN** a property with `$ref` but without `cascade: true` or `inversedBy`
- **WHEN** the parent object is updated and referenced UUIDs are removed
- **THEN** no orphan cleanup SHALL occur (the references are plain pointers, not owned sub-objects)

### Requirement 6: Bidirectional reference consistency via inversedBy and writeBack
When a schema property has `inversedBy` configuration, the system MUST maintain bidirectional consistency. Creating or deleting a child object MUST update the parent's reference array, and vice versa. The `CascadingHandler` and `RelationCascadeHandler` coordinate this.

#### Scenario: Cascade create populates inverse reference
- **GIVEN** schema `incident` has property `notes` with `type: array`, `items.$ref: "note"`, `items.inversedBy: "incident"`
- **WHEN** an incident is created with inline note objects in the `notes` array
- **THEN** `CascadingHandler::handlePreValidationCascading()` MUST create each note via `SaveObject::saveObject()`
- **AND** each created note MUST have `incident: "{parent-uuid}"` set automatically
- **AND** the incident's `notes` array MUST be replaced with the created note UUIDs

#### Scenario: WriteBack updates the inverse side
- **GIVEN** a property with `inversedBy: "incident"` and `writeBack: true`
- **WHEN** a note is saved referencing `incident: "incident-uuid"`
- **THEN** the incident's `notes` array MUST be updated to include the note's UUID
- **AND** if the note is removed from the incident, the note's `incident` field MUST be cleared

#### Scenario: Resolve schema reference via multiple formats
- **GIVEN** `RelationCascadeHandler::resolveSchemaReference()` accepts references in multiple formats
- **WHEN** a `$ref` is provided as numeric ID, UUID, slug, JSON Schema path (`#/components/schemas/Note`), or URL
- **THEN** the system MUST resolve to the correct schema ID using case-insensitive slug matching

### Requirement 7: Cross-register references MUST be supported and enforced
When a `$ref` property includes a `register` configuration pointing to a different register, referential integrity MUST apply across register boundaries. `ReferentialIntegrityService::buildSchemaRegisterMap()` maps schemas to registers via magic table naming conventions.

#### Scenario: Cross-register CASCADE delete
- **GIVEN** schema `order` in register `commerce` references schema `person` in register `crm` with `onDelete: CASCADE`
- **WHEN** person `person-1` in register `crm` is deleted
- **THEN** `findReferencingInMagicTable()` MUST query the magic table `oc_openregister_table_{commerceId}_{orderId}`
- **AND** all orders referencing `person-1` MUST be cascade-deleted

#### Scenario: Cross-register RESTRICT block
- **GIVEN** schema `contract` in register `legal` references schema `organisation` in register `crm` with `onDelete: RESTRICT`
- **WHEN** organisation deletion is attempted
- **THEN** the RESTRICT block MUST apply even though the blocker is in a different register
- **AND** the blocker info MUST include the source schema ID from the `legal` register

#### Scenario: Schema-register map built from magic table names
- **GIVEN** magic tables exist with naming convention `oc_openregister_table_{registerId}_{schemaId}`
- **WHEN** `buildSchemaRegisterMap()` runs
- **THEN** it MUST query `information_schema.tables` for tables matching the pattern
- **AND** populate `$schemaRegisterMap` mapping schema IDs to Register entities
- **AND** this map MUST be cached for the duration of the request

### Requirement 8: Reference type validation MUST enforce correct structure
References stored in object data MUST be valid UUIDs (or resolvable reference formats). `RelationCascadeHandler::isReference()` and `looksLikeObjectReference()` define what constitutes a valid reference.

#### Scenario: UUID reference (with dashes)
- **GIVEN** a property with `$ref` pointing to another schema
- **WHEN** the value `"550e8400-e29b-41d4-a716-446655440000"` is stored
- **THEN** `isReference()` MUST return `true`
- **AND** the value MUST be accepted as a valid reference

#### Scenario: UUID reference (without dashes)
- **GIVEN** a `$ref` property
- **WHEN** the value `"550e8400e29b41d4a716446655440000"` is stored
- **THEN** `isReference()` MUST return `true` (32 hex chars pattern)

#### Scenario: URL reference with /objects/ path
- **GIVEN** a `$ref` property
- **WHEN** the value `"https://example.com/api/objects/550e8400-e29b-41d4-a716-446655440000"` is stored
- **THEN** `isReference()` MUST return `true`
- **AND** `extractUuidFromReference()` MUST extract the UUID from the URL path

#### Scenario: Invalid reference format rejected
- **GIVEN** a `$ref` property with `validateReference: true`
- **WHEN** the value `"not-a-valid-reference-format"` is stored
- **THEN** `isReference()` MUST return `false`
- **AND** if validateReference is enabled, the save MUST fail with HTTP 422

### Requirement 9: Bulk operations MUST respect referential integrity per object
Bulk delete operations via `ObjectService::deleteObjects()` MUST process integrity rules for each affected object individually. Objects blocked by RESTRICT MUST be skipped, and the response MUST include aggregate counts.

#### Scenario: Bulk delete with CASCADE
- **GIVEN** 10 persons are selected for bulk deletion
- **AND** each person has 2 related orders with CASCADE
- **WHEN** the bulk delete is executed
- **THEN** `deleteObjects()` MUST call `DeleteObject::deleteObject()` for each person
- **AND** all persons AND their 20 related orders MUST be soft-deleted
- **AND** the response MUST include `cascade_count: 20` and `total_affected: 30`

#### Scenario: Bulk delete with RESTRICT-blocked items
- **GIVEN** 5 persons are selected for bulk deletion
- **AND** 2 persons have RESTRICT-constrained references
- **WHEN** the bulk delete is executed
- **THEN** the 3 unrestricted persons MUST be deleted with their cascades
- **AND** the 2 restricted persons MUST be skipped
- **AND** the response MUST include `skipped_uuids: ["uuid-4", "uuid-5"]` with the reason

#### Scenario: Bulk delete transaction isolation
- **GIVEN** 100 objects are selected for bulk deletion
- **WHEN** the bulk delete is executed
- **THEN** each object's integrity check and cascade MUST run within its own transaction scope
- **AND** a failure on object #50 MUST NOT roll back deletions of objects #1-#49

### Requirement 10: Referential integrity actions MUST be audited
Each integrity action MUST produce an audit trail entry via `ReferentialIntegrityService::logIntegrityAction()` and `AuditTrailMapper::createAuditTrail()`. The audit trail MUST distinguish user-initiated deletions from system-triggered integrity actions.

#### Scenario: Audit CASCADE action
- **GIVEN** person deletion triggers CASCADE deletion of 3 orders
- **THEN** at least 4 audit trail entries MUST be created:
  - 1 for the person deletion with `action_type: referential_integrity.root_delete` and cascade counts
  - 3 for the order deletions with `action: referential_integrity.cascade_delete`
- **AND** each cascade entry MUST include `triggeredBy: referential_integrity`, `triggerObject`, `triggerSchema`, and `property` in the `changed` metadata

#### Scenario: Audit RESTRICT block
- **GIVEN** person deletion is blocked by RESTRICT
- **THEN** `logRestrictBlock()` MUST create an audit entry with `action: referential_integrity.restrict_blocked`
- **AND** the entry MUST include `blockerCount`, `blockerSchema`, `blockerProperty`, and `reason`

#### Scenario: Audit SET_NULL and SET_DEFAULT actions
- **GIVEN** person deletion triggers SET_NULL on 2 tasks and SET_DEFAULT on 1 contract
- **THEN** 3 audit entries MUST be created:
  - 2 with `action: referential_integrity.set_null` including `property`, `previousValue`, `newValue: null`
  - 1 with `action: referential_integrity.set_default` including `property`, `previousValue`, `defaultValue`

#### Scenario: Audit trail expiry
- **GIVEN** an integrity action audit entry is created
- **THEN** the entry MUST have `expires` set to 30 days from creation
- **AND** expired entries SHALL be eligible for cleanup per the deletion-audit-trail spec

### Requirement 11: API _extend parameter MUST support lazy and eager reference resolution
The API MUST support an `_extend` query parameter that controls whether referenced objects are resolved inline (eager) or returned as UUIDs (lazy, default). `RelationHandler::extractAllRelationshipIds()` and `bulkLoadRelationshipsBatched()` handle bulk resolution.

#### Scenario: Lazy resolution (default)
- **GIVEN** an order object with `assignee: "person-uuid"`
- **WHEN** `GET /api/objects/{register}/{schema}/{uuid}` is called without `_extend`
- **THEN** the response MUST return `assignee: "person-uuid"` (UUID only)

#### Scenario: Eager resolution with _extend
- **GIVEN** an order object with `assignee: "person-uuid"`
- **WHEN** `GET /api/objects/{register}/{schema}/{uuid}?_extend=assignee` is called
- **THEN** `RelationHandler::bulkLoadRelationshipsBatched()` MUST resolve the UUID
- **AND** the response MUST return the full person object inline under `assignee`

#### Scenario: Performance circuit breaker on relationship loading
- **GIVEN** an object with 500 relationship IDs across multiple properties
- **WHEN** `_extend` is requested
- **THEN** `extractAllRelationshipIds()` MUST cap extraction at `$maxIds = 200`
- **AND** `bulkLoadRelationshipsBatched()` MUST process in batches of 50
- **AND** array relationships per object MUST be limited to 10 entries

#### Scenario: _extend across registers
- **GIVEN** an order with `customer` referencing a person in a different register
- **WHEN** `_extend=customer` is requested
- **THEN** `getUses()` MUST search across all magic tables (register+schema pairs) to find the referenced object
- **AND** RBAC filtering MUST be applied to extended objects via `filterByRbac()`

### Requirement 12: Relation graph MUST support bidirectional traversal (uses/usedBy)
The system MUST provide API endpoints to traverse the relation graph in both directions: outgoing references (uses) and incoming references (usedBy). `RelationHandler::getUses()` and `RelationHandler::getUsedBy()` implement this.

#### Scenario: Get outgoing references (uses)
- **GIVEN** an order object that references person `p-1` and product `prod-1`
- **WHEN** `GET /api/objects/{register}/{schema}/{uuid}/uses` is called
- **THEN** `RelationHandler::getUses()` MUST extract UUIDs from `getRelations()` on the object
- **AND** MUST search across all magic tables to resolve the referenced objects
- **AND** MUST return paginated results with `total`, `limit`, `offset`

#### Scenario: Get incoming references (usedBy)
- **GIVEN** person `p-1` is referenced by 5 orders and 3 tasks
- **WHEN** `GET /api/objects/{register}/{schema}/{uuid}/used` is called
- **THEN** `RelationHandler::getUsedBy()` MUST search `_relations_contains` across all magic tables
- **AND** MUST return 8 results (paginated)
- **AND** the object itself MUST be excluded from results (no self-references)

#### Scenario: Self-reference filtered from uses
- **GIVEN** an object whose `_relations` array includes its own UUID
- **WHEN** `getUses()` is called
- **THEN** the object's own UUID MUST be filtered out before loading related objects

### Requirement 13: Performance MUST be bounded for deep reference chains
The system MUST enforce performance boundaries to prevent timeout on complex reference graphs. This includes depth limits, batch sizes, and circuit breakers.

#### Scenario: Relation index cached per request
- **GIVEN** 50 schemas exist in the system
- **WHEN** multiple objects are deleted in a single request
- **THEN** `ensureRelationIndex()` MUST build the index only once (cached in `$relationIndex`)
- **AND** subsequent `canDelete()` calls MUST reuse the cached index

#### Scenario: Magic table direct query for referencing objects
- **GIVEN** a schema has a known register+schema mapping in `$schemaRegisterMap`
- **WHEN** `findReferencingObjects()` looks for objects referencing a deleted UUID
- **THEN** it MUST use `findReferencingInMagicTable()` to query the specific magic table column directly
- **AND** for scalar properties, it MUST use an exact `=` match
- **AND** for array properties on PostgreSQL, it MUST use `::jsonb @> to_jsonb(?::text)`
- **AND** for array properties on MySQL, it MUST use `JSON_CONTAINS()`
- **AND** results MUST be limited to 100 rows per query

#### Scenario: Fallback to findByRelation when no magic table mapping exists
- **GIVEN** a schema without a register mapping in `$schemaRegisterMap`
- **WHEN** `findReferencingObjects()` is called
- **THEN** it MUST fall back to `MagicMapper::findByRelation()` for broad search
- **AND** MUST filter results by schema and property name in PHP

#### Scenario: Batch CASCADE delete grouped by register+schema
- **GIVEN** 20 objects need to be cascade-deleted, spread across 3 schemas
- **WHEN** `applyBatchCascadeDelete()` is called
- **THEN** targets MUST be grouped by `registerId::schemaId`
- **AND** each group MUST be deleted via a single `MagicMapper::deleteObjects()` call
- **AND** audit trail entries MUST still be created individually per object

### Requirement 14: Array-type reference properties MUST be handled correctly
Properties with `type: array` and `items.$ref` MUST be handled differently from scalar `$ref` properties for all integrity actions (SET_NULL removes the UUID from the array rather than nullifying the whole property).

#### Scenario: SET_NULL on array property removes specific UUID
- **GIVEN** schema `team` has property `members` with `type: array`, `items.$ref: "person"`, `onDelete: SET_NULL`
- **AND** a team has `members: ["p-1", "p-2", "p-3"]`
- **WHEN** person `p-2` is deleted
- **THEN** `applySetNull()` MUST detect `isArray: true` from the target metadata
- **AND** MUST filter `p-2` from the array: `members: ["p-1", "p-3"]`
- **AND** MUST NOT set the entire `members` property to `null`

#### Scenario: CASCADE on array property applies to each referenced object
- **GIVEN** schema `department` has property `employees` with `type: array`, `items.$ref: "person"`, `onDelete: CASCADE`
- **WHEN** a person referenced in the employees array is deleted
- **THEN** the department itself MUST be cascade-deleted (the department references the person, so the department is the dependent)

#### Scenario: Relation index correctly identifies array properties
- **GIVEN** a schema property with `type: array` and `items.$ref`
- **WHEN** `indexRelationsForSchema()` builds the relation index
- **THEN** the index entry MUST have `isArray: true`
- **AND** `extractTargetRef()` MUST extract the `$ref` from `items.$ref`

### Requirement 15: Multi-tenancy and RBAC MUST be respected during integrity enforcement
Referential integrity operations MUST bypass RBAC and multi-tenancy filters when scanning for dependent objects (system-level enforcement), but MUST respect them when loading schemas and registers for user-facing operations.

#### Scenario: Integrity scan bypasses RBAC
- **GIVEN** a user deletes object X which triggers CASCADE on objects owned by other users
- **WHEN** `ReferentialIntegrityService::ensureRelationIndex()` loads all schemas
- **THEN** it MUST pass `_rbac: false` and `_multitenancy: false` to `SchemaMapper::findAll()` and `RegisterMapper::findAll()`
- **AND** ALL schemas MUST be indexed regardless of user permissions

#### Scenario: Cascade delete applies to all matching objects regardless of ownership
- **GIVEN** person `p-1` is referenced by orders owned by 3 different users
- **AND** the deleting user only has access to their own orders
- **WHEN** person `p-1` is deleted with CASCADE
- **THEN** ALL 3 users' orders MUST be cascade-deleted (integrity enforcement is system-level)
- **AND** `MagicMapper::deleteObjects()` MUST operate without RBAC filtering

#### Scenario: usedBy and uses endpoints respect RBAC for display
- **GIVEN** person `p-1` is referenced by 5 orders, but the current user only has RBAC access to 3
- **WHEN** `getUses()` is called with `_rbac: true`
- **THEN** `filterByRbac()` MUST check schema authorization for each result
- **AND** only the 3 accessible orders MUST be returned

## Current Implementation Status

**Substantially implemented.** Core referential integrity logic exists:

- `lib/Service/Object/ReferentialIntegrityService.php` -- Main service class with:
  - All 5 `onDelete` actions supported: `CASCADE`, `RESTRICT`, `SET_NULL`, `SET_DEFAULT`, `NO_ACTION` (defined in `VALID_ON_DELETE_ACTIONS` constant)
  - `MAX_DEPTH = 10` for circular reference detection (prevents infinite recursion)
  - Graph-walking logic (`walkDeletionGraph()`) for recursive cascade operations with visited-set cycle detection
  - Relation index built once per request from all schemas (`ensureRelationIndex()`)
  - Direct magic table queries via `findReferencingInMagicTable()` for PostgreSQL and MySQL with JSON containment support
  - Batch cascade delete grouped by register+schema (`applyBatchCascadeDelete()`)
  - Audit trail logging for all integrity actions (`logIntegrityAction()`, `logRestrictBlock()`)
- `lib/Dto/DeletionAnalysis.php` -- Immutable value object with `cascadeTargets`, `nullifyTargets`, `defaultTargets`, `blockers`, `chainPaths`
- `lib/Exception/ReferentialIntegrityException.php` -- Custom exception for RESTRICT blocks, returns HTTP 409 with structured `toResponseBody()`
- `lib/Service/Object/DeleteObject.php` -- Integrates with referential integrity:
  - `handleIntegrityDeletion()` orchestrates the analysis-then-apply flow
  - `executeIntegrityTransaction()` wraps all actions in `IDBConnection::beginTransaction()`/`commit()`/`rollBack()`
  - `cascadeDeleteObjects()` handles legacy `cascade: true` property behavior
  - `getLastCascadeCount()` returns total affected count
- `lib/Service/Object/SaveObject.php` -- Save-time integrity:
  - `validateReferences()` validates `$ref` properties with `validateReference: true`
  - `deleteOrphanedRelatedObjects()` cleans up orphaned sub-objects on update
- `lib/Service/Object/SaveObject/RelationCascadeHandler.php` -- Handles:
  - `resolveSchemaReference()` -- multi-format schema resolution (ID, UUID, slug, path, URL)
  - `resolveRegisterReference()` -- multi-format register resolution
  - `scanForRelations()` -- recursive relation detection in object data
  - `cascadeObjects()` -- pre-validation cascade creation for `inversedBy` properties
- `lib/Service/Object/CascadingHandler.php` -- Handles `inversedBy` cascade creation with `writeBack` support
- `lib/Service/Object/RelationHandler.php` -- Relation graph traversal:
  - `getUses()` -- outgoing references with cross-register magic table search
  - `getUsedBy()` -- incoming references via `_relations_contains` search
  - `extractAllRelationshipIds()` with circuit breaker (200 max IDs)
  - `bulkLoadRelationshipsBatched()` with 50-object batch size
  - `filterByRbac()` for RBAC-filtered relation results
- `lib/Db/Schema.php` -- Schema property `onDelete`, `validateReference`, `inversedBy`, `writeBack`, `cascade` configuration
- Schema property `onDelete` configuration supported and validated

**What is NOT yet implemented:**
- UI indication of referential integrity constraints (warning before deleting referenced objects, schema editor for `onDelete` configuration)
- `RelationCascadeHandler::cascadeSingleObject()` returns null (TODO: needs event system to avoid circular dependency with ObjectService)
- `RelationCascadeHandler::handleInverseRelationsWriteBack()` returns data unchanged (TODO: needs refactoring)

**Recently implemented:**
- Full transactional atomicity: `DeleteObject::executeIntegrityTransaction()` wraps all cascade operations + root deletion in `IDBConnection::beginTransaction()`/`commit()`/`rollBack()`
- Audit trail tagging: root deletions get `action_type: referential_integrity.root_delete` with cascade counts; cascade deletions get `referential_integrity.cascade_delete` with trigger metadata
- Bulk delete with referential integrity: `ObjectService::deleteObjects()` processes each object through `DeleteObject::deleteObject()`, skipping RESTRICT-blocked objects, returning `cascade_count`, `total_affected`, `skipped_uuids`
- Direct magic table queries for performance: `findReferencingInMagicTable()` queries specific columns instead of scanning `_relations` JSONB
- SET_NULL fallback to RESTRICT for required properties, SET_DEFAULT fallback chain
- Orphan detection and cleanup in `SaveObject::deleteOrphanedRelatedObjects()`

## Standards & References
- SQL standard referential integrity actions (CASCADE, SET NULL, SET DEFAULT, RESTRICT, NO ACTION) -- ISO/IEC 9075
- HTTP 409 Conflict (RFC 9110) for RESTRICT violations
- HTTP 422 Unprocessable Entity (RFC 4918) for invalid reference validation
- Database transaction isolation levels (ACID principles)
- JSON Schema `$ref` keyword (RFC draft-bhutton-json-schema-01)
- Competitor analysis: Directus uses database-level foreign keys with 7 relationship types (M2O, O2M, M2M, M2A); Strapi uses 10 relation types with Document Service API; OpenRegister uses application-level integrity enforcement over JSON Schema `$ref` for maximum flexibility across database backends

## Specificity Assessment
- **Specific enough to implement?** Yes -- the scenarios clearly define each action, fallback chains, transaction boundaries, and performance constraints with concrete references to implementation classes.
- **Missing/ambiguous:**
  - No specification for how referential integrity interacts with soft-delete vs hard delete (currently all operations use soft-delete)
  - No specification for webhooks/event dispatching for each cascaded object (should `IEventDispatcher` fire `BeforeObjectDeletedEvent`/`ObjectDeletedEvent` for cascade-deleted objects?)
  - Schema migration impact: when a schema's `$ref` target changes, existing objects with old references are not automatically migrated
- **Resolved questions:**
  - RESTRICT + bulk delete: skip restricted items and continue with the rest (implemented)
  - SET_NULL on required property: falls back to RESTRICT (implemented)
  - SET_DEFAULT without default: falls back to SET_NULL -> RESTRICT chain (implemented)
  - Circular reference handling: visited-set + MAX_DEPTH=10 (implemented)
  - Cross-register integrity: schema-register map from magic table names (implemented)

## Nextcloud Integration Analysis

**Status**: IMPLEMENTED (backend complete, UI pending)

**What Exists**: The core referential integrity service (`ReferentialIntegrityService.php`) is in place with all five `onDelete` behaviors functional. `DeletionAnalysis` DTO encapsulates the graph-walk results. `DeleteObject.php` integrates with the integrity service, wrapping operations in `IDBConnection` transactions. `RelationHandler` provides bidirectional graph traversal (uses/usedBy) across all magic tables. `RelationCascadeHandler` resolves schema references in multiple formats and manages cascade creation for `inversedBy` properties. `CascadingHandler` handles pre-validation cascade creation. `SaveObject` handles reference validation on save and orphan cleanup on update.

**Gap Analysis**: The `onDelete` attribute exists on schema properties but the UI does not yet expose a way to configure it visually. `cascadeSingleObject()` and `handleInverseRelationsWriteBack()` in `RelationCascadeHandler` are not yet functional (TODO: needs event system refactor). `IEventDispatcher` events are not yet fired for cascade-deleted objects, limiting visibility for other Nextcloud apps.

**Nextcloud Core Integration Points**:
- **IDBConnection transaction management**: `DeleteObject::executeIntegrityTransaction()` uses `beginTransaction()` / `commit()` / `rollBack()` via Nextcloud's database abstraction layer (Doctrine DBAL), which supports nested transactions via savepoints for recursive cascades.
- **IEventDispatcher** (pending): Fire `BeforeObjectDeletedEvent` and `ObjectDeletedEvent` for each cascade-deleted object, allowing other apps (OpenCatalogi, OpenConnector) to react. Use `GenericEvent` with context metadata indicating referential integrity trigger.
- **LoggerInterface (PSR-3)**: All integrity operations log warnings and errors via Nextcloud's logger, visible in the Nextcloud log viewer.
- **ICache (OCP\ICache)**: Consider caching resolved schema references to avoid repeated lookups during bulk operations with many cross-references.
- **Activity app integration** (pending): Register cascade deletions as activity events so the Activity stream shows "Object X was deleted (cascade from Object Y deletion)".

**Recommendation**: Remaining work priorities: (1) integrate `IEventDispatcher` for cascade-deleted objects; (2) add UI for `onDelete` configuration in schema editor; (3) add deletion confirmation dialog showing `DeletionAnalysis` preview (cascade count, affected objects); (4) complete `cascadeSingleObject()` and `handleInverseRelationsWriteBack()` via event system to break circular dependency with ObjectService.
