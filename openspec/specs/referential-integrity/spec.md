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
