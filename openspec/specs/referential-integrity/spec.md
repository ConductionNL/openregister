---
status: partial
---

# referential-integrity Specification

## Purpose
Enforce referential integrity between register objects connected via `$ref` schema properties. When a referenced object is modified or deleted, the system MUST apply the configured integrity action (CASCADE, SET_NULL, SET_DEFAULT, RESTRICT, NO_ACTION) to maintain data consistency. This prevents orphaned references and ensures relational constraints across schemas.

**Source**: Core OpenRegister capability for data consistency across related objects.

## ADDED Requirements

### Requirement: Schema properties with $ref MUST support onDelete behavior
Properties that reference other schemas via `$ref` MUST define what happens when the referenced object is deleted.

#### Scenario: Configure CASCADE delete
- GIVEN schema `order` with property `assignee` referencing schema `person`
- WHEN the admin sets `onDelete: CASCADE` on the `assignee` property
- AND person `person-1` is deleted
- THEN all orders referencing `person-1` MUST also be deleted
- AND cascade deletions MUST be recursive (if orders have dependent objects, those cascade too)

#### Scenario: Configure SET_NULL
- GIVEN schema `order` with property `assignee` referencing schema `person` with `onDelete: SET_NULL`
- WHEN person `person-1` is deleted
- THEN all orders with `assignee: "person-1"` MUST have `assignee` set to `null`
- AND the orders themselves MUST NOT be deleted

#### Scenario: Configure SET_DEFAULT
- GIVEN schema `order` with property `assignee` referencing schema `person` with `onDelete: SET_DEFAULT`
- AND the property has `default: "system-user-uuid"`
- WHEN person `person-1` is deleted
- THEN all orders with `assignee: "person-1"` MUST have `assignee` set to `"system-user-uuid"`

#### Scenario: Configure RESTRICT
- GIVEN schema `order` with property `assignee` referencing schema `person` with `onDelete: RESTRICT`
- AND 3 orders reference person `person-1`
- WHEN deletion of person `person-1` is attempted
- THEN the deletion MUST be blocked
- AND the API MUST return HTTP 409 Conflict with message listing the 3 blocking orders

#### Scenario: Configure NO_ACTION (default)
- GIVEN no `onDelete` is specified (defaults to NO_ACTION)
- WHEN the referenced person is deleted
- THEN orders with the now-broken reference MUST NOT be modified
- AND the broken reference is the caller's responsibility

### Requirement: Referential integrity MUST apply within transactions
All integrity actions MUST be atomic -- either all changes succeed or none do.

#### Scenario: Atomic CASCADE
- GIVEN person `person-1` has 5 related orders
- WHEN person `person-1` is deleted
- THEN all 5 orders MUST be deleted in the same database transaction
- AND if any deletion fails, the entire operation (including the person deletion) MUST be rolled back

### Requirement: Circular references MUST be detected and handled
The system MUST detect circular reference chains and prevent infinite cascades.

#### Scenario: Circular CASCADE detection
- GIVEN schema A references schema B (CASCADE) and schema B references schema A (CASCADE)
- WHEN an object in schema A is deleted
- THEN the system MUST detect the circular chain
- AND process each object at most once
- AND log a warning about the circular reference

### Requirement: Reference validation MUST be configurable on save
Optionally, the system MUST validate that referenced objects exist when saving.

#### Scenario: Validate reference on save
- GIVEN property `assignee` with `validateReference: true`
- WHEN an order is created with `assignee: "nonexistent-uuid"`
- THEN the save MUST fail with validation error: `Referenced object not found: nonexistent-uuid`

#### Scenario: Skip validation on save
- GIVEN property `assignee` with `validateReference: false` (default)
- WHEN an order is created with `assignee: "nonexistent-uuid"`
- THEN the save MUST succeed (eventual consistency pattern)

### Requirement: Bulk operations MUST respect referential integrity
Bulk delete operations MUST process integrity rules for each affected object.

#### Scenario: Bulk delete with CASCADE
- GIVEN 10 persons are selected for bulk deletion
- AND each person has 2-5 related orders with CASCADE
- WHEN the bulk delete is executed
- THEN all persons AND their related orders MUST be deleted
- AND the total count of deleted objects MUST be reported to the user

### Requirement: Referential integrity actions MUST be audited
Each integrity action MUST produce an audit trail entry (see deletion-audit-trail spec for details).

#### Scenario: Audit CASCADE action
- GIVEN person deletion triggers CASCADE deletion of 3 orders
- THEN 4 audit trail entries MUST be created:
  - 1 for the person deletion (user-initiated)
  - 3 for the order deletions (referential_integrity.cascade_delete)

### Current Implementation Status

**Substantially implemented.** Core referential integrity logic exists:

- `lib/Service/Object/ReferentialIntegrityService.php` -- Main service class with:
  - All 5 `onDelete` actions supported: `CASCADE`, `RESTRICT`, `SET_NULL`, `SET_DEFAULT`, `NO_ACTION` (defined in `VALID_ON_DELETE_ACTIONS` constant)
  - `MAX_DEPTH = 10` for circular reference detection (prevents infinite recursion)
  - Graph-walking logic for recursive cascade operations
- `lib/Exception/ReferentialIntegrityException.php` -- Custom exception for integrity violations (used for RESTRICT blocks, returns HTTP 409)
- `lib/Service/Object/DeleteObject.php` -- Integrates with referential integrity on delete operations
- `lib/Service/Object/ValidateObject.php` -- Validates referential constraints
- `lib/Service/Object/SaveObject/RelationCascadeHandler.php` -- Handles cascade operations during save, includes `resolveSchemaReference()` for finding target schemas
- `lib/Service/Object/CascadingHandler.php` -- Additional cascading logic for relation resolution
- Schema property `onDelete` configuration supported in `lib/Db/Schema.php`
- `validateReference` on save is implemented in `SaveObject.php` (see reference-existence-validation spec)

**What is NOT yet implemented:**
- Full transactional atomicity (database transactions wrapping all cascade operations) -- partially implemented
- Audit trail integration specifically for referential integrity actions (cascade deletions logged as system-triggered)
- Bulk delete operations with referential integrity processing per object
- UI indication of referential integrity constraints (e.g., warning before deleting referenced objects)

### Standards & References
- SQL standard referential integrity actions (CASCADE, SET NULL, SET DEFAULT, RESTRICT, NO ACTION)
- HTTP 409 Conflict (RFC 9110) for RESTRICT violations
- Database transaction isolation levels (ACID principles)

### Specificity Assessment
- **Specific enough to implement?** Yes -- the scenarios clearly define each action and its expected behavior.
- **Missing/ambiguous:**
  - No specification for performance impact of deep cascade chains (MAX_DEPTH=10 is an implementation detail, not specified)
  - No specification for how referential integrity interacts with soft-delete (if objects have `deleted` flag vs hard delete)
  - No specification for cross-register referential integrity (what if referenced object is in a different register?)
- **Open questions:**
  - Should cascade operations trigger hooks/webhooks for each cascaded object?
  - How should RESTRICT interact with bulk delete (fail entire batch or skip restricted items)?

## Nextcloud Integration Analysis

**Status**: PARTIALLY IMPLEMENTED

**What Exists**: The core referential integrity service (`ReferentialIntegrityService.php`) is in place with all five `onDelete` behaviors (CASCADE, SET_NULL, SET_DEFAULT, RESTRICT, NO_ACTION) defined and functional. `EntityRelation` and `RelationHandler` track relationships between objects. `DeleteObject.php` integrates with the integrity service on delete operations. `RelationCascadeHandler.php` resolves schema references and handles cascade during save. Circular reference detection is implemented via `MAX_DEPTH = 10`. RESTRICT violations correctly return HTTP 409 via `ReferentialIntegrityException`.

**Gap Analysis**: CASCADE/SET_NULL/RESTRICT behaviors are not yet configurable per individual relation type through the schema property UI -- the `onDelete` attribute exists on schema properties but lacks full transactional atomicity wrapping all cascade operations in a single database transaction. Bulk delete operations do not yet process referential integrity rules per object. Audit trail entries for cascade-triggered deletions are not tagged with the triggering integrity action type.

**Nextcloud Core Integration Points**:
- **IDBConnection transaction management**: Wrap all cascade operations in `$this->db->beginTransaction()` / `commit()` / `rollBack()` to guarantee atomicity. Nextcloud's database abstraction layer (Doctrine DBAL) supports nested transactions via savepoints, which is ideal for recursive cascades.
- **IEventDispatcher**: Fire `BeforeObjectDeletedEvent` and `ObjectDeletedEvent` for each cascade-deleted object, allowing other apps (OpenCatalogi, OpenConnector) to react to cascade deletions. Use `GenericEvent` with context metadata indicating the deletion was triggered by referential integrity.
- **ILogger / LoggerInterface**: Log cascade chains and circular reference warnings via Nextcloud's PSR-3 logger, enabling admins to trace integrity operations in the Nextcloud log viewer.
- **Activity app integration**: Register cascade deletions as activity events so the Activity stream shows "Object X was deleted (cascade from Object Y deletion)".

**Recommendation**: Complete the transactional wrapper first -- this is the highest-risk gap since partial cascades can leave data in an inconsistent state. Use `IDBConnection::beginTransaction()` at the top-level delete call in `DeleteObject.php` and commit only after all cascaded operations succeed. Next, integrate with `IEventDispatcher` so cascade deletions are visible to the broader Nextcloud ecosystem. Bulk delete support can build on the single-delete transaction pattern by iterating within a shared transaction. Audit trail tagging is a metadata addition to the existing deletion audit entries.
