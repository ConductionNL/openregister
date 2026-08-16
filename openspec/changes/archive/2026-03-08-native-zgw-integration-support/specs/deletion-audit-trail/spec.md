# deletion-audit-trail Specification

## Purpose
Log all referential integrity actions (CASCADE delete, SET_NULL, SET_DEFAULT, RESTRICT block) in OpenRegister's existing AuditTrail system. When objects are modified or deleted as part of a cascade operation, each action produces an AuditTrail entry that records what happened, why, and which user initiated it.

## ADDED Requirements

### Requirement: CASCADE deletions MUST create AuditTrail entries
Each object deleted via CASCADE referential integrity MUST produce an AuditTrail entry.

#### Scenario: Single cascade deletion
- GIVEN schema `order` with property `assignee` referencing schema `person` with `onDelete: CASCADE`
- AND an order object `order-1` references person `person-1`
- WHEN person `person-1` is deleted
- THEN an AuditTrail entry MUST be created with:
  - `action`: `"referential_integrity.cascade_delete"`
  - `objectUuid`: UUID of `order-1`
  - `schemaUuid`: UUID of the `order` schema
  - `registerUuid`: UUID of the register containing the order
  - `changed`: `{"deletedBecause": "cascade", "triggerObject": "person-1", "triggerSchema": "person", "property": "assignee"}`
  - `user`: the user who initiated the original person deletion

#### Scenario: Chain cascade deletion
- GIVEN person → order (CASCADE) → order-line (CASCADE)
- WHEN person `person-1` is deleted
- THEN AuditTrail entries MUST be created for both the order deletion AND each order-line deletion
- AND each entry's `changed` field MUST trace back to the original trigger: `"triggerObject": "person-1"`

### Requirement: SET_NULL actions MUST create AuditTrail entries
Each property nullified via SET_NULL referential integrity MUST produce an AuditTrail entry.

#### Scenario: Set null on single property
- GIVEN schema `order` with property `assignee` referencing schema `person` with `onDelete: SET_NULL`
- AND order `order-1` has `assignee` = `"person-1"`
- WHEN person `person-1` is deleted
- THEN an AuditTrail entry MUST be created with:
  - `action`: `"referential_integrity.set_null"`
  - `objectUuid`: UUID of `order-1`
  - `changed`: `{"property": "assignee", "previousValue": "person-1", "newValue": null, "triggerObject": "person-1", "triggerSchema": "person"}`

### Requirement: SET_DEFAULT actions MUST create AuditTrail entries
Each property reset to default via SET_DEFAULT referential integrity MUST produce an AuditTrail entry.

#### Scenario: Set default on single property
- GIVEN schema `order` with property `assignee` referencing schema `person` with `onDelete: SET_DEFAULT`
- AND the property has `default: "system-user-uuid"`
- AND order `order-1` has `assignee` = `"person-1"`
- WHEN person `person-1` is deleted
- THEN an AuditTrail entry MUST be created with:
  - `action`: `"referential_integrity.set_default"`
  - `objectUuid`: UUID of `order-1`
  - `changed`: `{"property": "assignee", "previousValue": "person-1", "newValue": "system-user-uuid", "triggerObject": "person-1", "triggerSchema": "person"}`

### Requirement: RESTRICT blocks MUST create AuditTrail entries
When a deletion is blocked by RESTRICT, an AuditTrail entry MUST record the blocked attempt.

#### Scenario: Deletion blocked by RESTRICT
- GIVEN schema `order` with property `assignee` referencing schema `person` with `onDelete: RESTRICT`
- AND 3 orders reference person `person-1`
- WHEN deletion of person `person-1` is attempted
- THEN an AuditTrail entry MUST be created with:
  - `action`: `"referential_integrity.restrict_blocked"`
  - `objectUuid`: UUID of `person-1` (the object that was NOT deleted)
  - `changed`: `{"blockerCount": 3, "blockerSchema": "order", "blockerProperty": "assignee", "reason": "RESTRICT constraint prevents deletion"}`

### Requirement: AuditTrail entries MUST include the initiating user context
All referential integrity AuditTrail entries MUST capture who initiated the original deletion that triggered the cascade.

#### Scenario: User context propagation
- GIVEN user `admin` deletes person `person-1`
- WHEN cascade actions create AuditTrail entries for affected orders
- THEN each AuditTrail entry MUST have `user` = `"admin"`

#### Scenario: API consumer context
- GIVEN a JWT-authenticated consumer deletes an object
- WHEN cascade actions create AuditTrail entries
- THEN each AuditTrail entry MUST have `user` set to the consumer's mapped Nextcloud user ID

### Requirement: AuditTrail entries MUST be created within the same transaction scope
AuditTrail writes for referential integrity actions MUST be atomic with the integrity actions themselves.

#### Scenario: Cascade delete with audit trail
- GIVEN a cascade deletion that affects 5 objects
- WHEN the deletion is processed
- THEN all 5 AuditTrail entries MUST be created
- AND if any AuditTrail write fails, it MUST NOT block the deletion (log a warning instead)

### Requirement: AuditTrail entries MUST NOT be created for NO_ACTION
The NO_ACTION onDelete behavior means no referential integrity action is taken, so no audit entry is needed.

#### Scenario: No action produces no audit
- GIVEN schema `order` with property `assignee` referencing schema `person` with `onDelete: NO_ACTION`
- WHEN person `person-1` is deleted
- THEN NO AuditTrail entry MUST be created for referential integrity on `order-1`
