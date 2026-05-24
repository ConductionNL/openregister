# Referential Integrity on Delete Specification

## Purpose
Enforces referential integrity when objects are deleted in OpenRegister. Schema relation properties (`$ref`) gain an `onDelete` configuration that determines what happens to dependent objects. A pre-flight analysis walks the full relation graph — including chained cascades — to detect blockers before any mutation occurs.

## Terminology
- **Source object**: The object being deleted
- **Dependent object**: An object whose schema has a `$ref` property pointing to the source object's schema
- **Relation property**: A schema property with `$ref` that references another schema
- **Deletion graph**: The tree of all objects affected by deleting the source object, built by recursively following `onDelete` configurations

## ADDED Requirements

### Requirement: onDelete Configuration on Schema Properties
Schema properties with `$ref` MUST support an `onDelete` field that defines behavior when the referenced object is deleted.

#### Scenario: Property with onDelete configuration
- GIVEN a schema "ContactDetail" with a property `person` that has `"$ref": "person-schema"` and `"onDelete": "CASCADE"`
- WHEN the schema is saved
- THEN the `onDelete` value is persisted as part of the property configuration
- AND it is retrievable via the schema's properties

#### Scenario: Valid onDelete values
- GIVEN a schema property with `$ref`
- WHEN `onDelete` is set
- THEN it MUST be one of: `CASCADE`, `RESTRICT`, `SET_NULL`, `SET_DEFAULT`, `NO_ACTION`
- AND the value MUST be case-insensitive on input but stored uppercase

#### Scenario: Default onDelete value
- GIVEN a schema property with `$ref` but no `onDelete` field
- THEN the effective behavior MUST be `NO_ACTION`
- AND no referential integrity checks are performed for this relation

#### Scenario: onDelete on array relation
- GIVEN a schema property with `"type": "array"` and `"items": {"$ref": "other-schema"}`
- WHEN `onDelete` is set on the property (NOT inside items)
- THEN it applies to each element in the array individually

#### Scenario: Invalid onDelete value rejected
- GIVEN a schema property with `"onDelete": "DESTROY"`
- WHEN the schema is saved
- THEN validation MUST reject the schema with an error indicating the invalid onDelete value

### Requirement: CASCADE Action
When the referenced object is deleted, all dependent objects with `onDelete: CASCADE` MUST also be soft-deleted.

#### Scenario: Simple cascade delete
- GIVEN schema "ContactDetail" has property `person` with `"$ref": "person-schema", "onDelete": "CASCADE"`
- AND object A is of schema "person-schema"
- AND objects B1 and B2 are of schema "ContactDetail" with `person` referencing A's UUID
- WHEN object A is deleted
- THEN objects B1 and B2 MUST be soft-deleted
- AND their `deleted` metadata MUST include `"deletedBy": "cascade"` and `"cascadeSource": "<A's UUID>"`

#### Scenario: Cascade triggers further cascade (chained)
- GIVEN schema "ContactDetail" has `person` with `"$ref": "person-schema", "onDelete": "CASCADE"`
- AND schema "PhoneNumber" has `contact` with `"$ref": "contact-detail-schema", "onDelete": "CASCADE"`
- AND Person A → ContactDetail B → PhoneNumber C
- WHEN Person A is deleted
- THEN ContactDetail B is cascade-deleted
- AND PhoneNumber C is cascade-deleted (triggered by B's deletion)
- AND PhoneNumber C's `cascadeSource` is A's UUID (the root cause)

#### Scenario: Cascade on array relation
- GIVEN schema "Team" has property `members` with `"type": "array", "items": {"$ref": "person-schema"}, "onDelete": "CASCADE"`
- AND Team T has `members: ["uuid-1", "uuid-2"]`
- WHEN person with uuid-1 is deleted
- THEN Team T is cascade-deleted (because one of its referenced members was deleted)

### Requirement: RESTRICT Action
When the referenced object is deleted, the deletion MUST be blocked if any dependent object has `onDelete: RESTRICT`.

#### Scenario: Simple restrict
- GIVEN schema "Service" has property `serviceType` with `"$ref": "service-type-schema", "onDelete": "RESTRICT"`
- AND object S is of schema "Service" with `serviceType` referencing ServiceType T
- WHEN ServiceType T is deleted
- THEN the deletion MUST be blocked
- AND the API MUST return HTTP 409 Conflict
- AND the response body MUST include the blocking object's UUID, schema, and the property name causing the block

#### Scenario: Restrict found through cascade chain
- GIVEN schema "ContactDetail" has `person` with `"$ref": "person-schema", "onDelete": "CASCADE"`
- AND schema "Audit" has `contact` with `"$ref": "contact-detail-schema", "onDelete": "RESTRICT"`
- AND Person A → ContactDetail B → Audit C (RESTRICT)
- WHEN Person A is deleted
- THEN the deletion MUST be blocked because cascading through B would require deleting C, but C has RESTRICT
- AND the API MUST return HTTP 409 with details showing the full chain: A → B (CASCADE) → C (RESTRICT)

#### Scenario: Restrict with no actual dependents
- GIVEN schema "Service" has property `serviceType` with `"$ref": "service-type-schema", "onDelete": "RESTRICT"`
- AND no objects of schema "Service" currently reference ServiceType T
- WHEN ServiceType T is deleted
- THEN the deletion MUST proceed (RESTRICT only blocks when actual dependent objects exist)

### Requirement: SET_NULL Action
When the referenced object is deleted, the reference in dependent objects MUST be set to `null`.

#### Scenario: Set null on single relation
- GIVEN schema "Order" has property `coupon` with `"$ref": "coupon-schema", "onDelete": "SET_NULL"`
- AND Order O has `coupon: "coupon-uuid-1"`
- WHEN the coupon object is deleted
- THEN Order O's `coupon` property MUST be set to `null`
- AND Order O MUST NOT be deleted

#### Scenario: Set null on array relation
- GIVEN schema "Project" has property `contributors` with `"type": "array", "items": {"$ref": "person-schema"}, "onDelete": "SET_NULL"`
- AND Project P has `contributors: ["uuid-1", "uuid-2", "uuid-3"]`
- WHEN person with uuid-2 is deleted
- THEN Project P's `contributors` MUST become `["uuid-1", "uuid-3"]` (the reference is removed from the array)
- AND Project P MUST NOT be deleted

#### Scenario: Set null respects required fields
- GIVEN schema "Order" has property `coupon` with `"$ref": "coupon-schema", "onDelete": "SET_NULL"` AND `"required": true`
- WHEN the coupon is deleted
- THEN the behavior MUST fall back to RESTRICT (cannot set a required field to null)
- AND the API MUST return HTTP 409 with a message explaining SET_NULL cannot be applied to a required property

### Requirement: SET_DEFAULT Action
When the referenced object is deleted, the reference in dependent objects MUST be set to the property's `default` value.

#### Scenario: Set default on single relation
- GIVEN schema "Task" has property `assignee` with `"$ref": "person-schema", "onDelete": "SET_DEFAULT", "default": "unassigned-uuid"`
- AND Task T has `assignee: "person-uuid-1"`
- WHEN person-uuid-1 is deleted
- THEN Task T's `assignee` MUST be set to `"unassigned-uuid"`

#### Scenario: Set default with no default value defined
- GIVEN schema "Task" has property `assignee` with `"$ref": "person-schema", "onDelete": "SET_DEFAULT"` but no `default` field
- WHEN the referenced person is deleted
- THEN the behavior MUST fall back to SET_NULL
- AND if the property is required, it MUST fall back to RESTRICT

### Requirement: NO_ACTION (Default)
When no `onDelete` is configured, deletion proceeds without any referential integrity checks for that relation.

#### Scenario: No onDelete configured
- GIVEN schema "Log" has property `user` with `"$ref": "person-schema"` and no `onDelete` field
- AND Log L references Person P
- WHEN Person P is deleted
- THEN Log L is NOT modified, NOT deleted, NOT checked
- AND Log L's `user` property retains the now-orphaned UUID

### Requirement: Pre-Flight Deletion Analysis (canDelete)
A `canDelete()` method MUST be available that analyzes the full deletion graph without performing any mutations.

#### Scenario: canDelete returns analysis for deletable object
- GIVEN Person A with ContactDetails B1, B2 (CASCADE) and no RESTRICT dependents
- WHEN `canDelete(A)` is called
- THEN it MUST return a DeletionAnalysis object with:
  - `deletable: true`
  - `cascadeTargets: [B1, B2]` (objects that would be cascade-deleted)
  - `nullifyTargets: []` (objects that would have references nullified)
  - `defaultTargets: []` (objects that would have references set to default)
  - `blockers: []` (empty — nothing blocks)

#### Scenario: canDelete detects RESTRICT blocker
- GIVEN ServiceType T referenced by Service S (RESTRICT)
- WHEN `canDelete(T)` is called
- THEN it MUST return:
  - `deletable: false`
  - `blockers: [{objectUuid: S.uuid, schema: "service-schema", property: "serviceType", action: "RESTRICT"}]`

#### Scenario: canDelete detects chained RESTRICT
- GIVEN Person A → ContactDetail B (CASCADE) → Audit C (RESTRICT)
- WHEN `canDelete(A)` is called
- THEN it MUST return:
  - `deletable: false`
  - `blockers: [{objectUuid: C.uuid, schema: "audit-schema", property: "contact", action: "RESTRICT", chain: ["A → B (CASCADE)", "B → C (RESTRICT)"]}]`

#### Scenario: canDelete with circular references
- GIVEN schema A references schema B (CASCADE) and schema B references schema A (CASCADE)
- AND object A1 → B1 → A1 (circular)
- WHEN `canDelete(A1)` is called
- THEN the graph walker MUST detect the cycle and NOT enter infinite recursion
- AND the analysis MUST include B1 in cascadeTargets but NOT revisit A1

#### Scenario: canDelete exposed via API
- GIVEN an authenticated user with delete permissions
- WHEN `GET /api/objects/{register}/{schema}/{id}/can-delete` is called
- THEN the API MUST return the DeletionAnalysis as JSON
- AND HTTP 200 with `{deletable: true, ...}` or `{deletable: false, blockers: [...]}`

### Requirement: Efficient Schema Analysis
The system MUST minimize unnecessary schema parsing and object lookups during deletion.

#### Scenario: Skip schemas without onDelete config
- GIVEN 20 schemas in a register, but only 2 have properties with `onDelete` configured
- WHEN any object is deleted
- THEN only the 2 schemas with `onDelete` config are analyzed
- AND the other 18 schemas are not queried for dependent objects

#### Scenario: Schema relation index
- GIVEN a register with schemas
- WHEN the referential integrity system initializes (per-request)
- THEN it MUST build a reverse index: for each schema, which other schemas have `onDelete`-configured properties referencing it
- AND this index MUST be cached for the duration of the request (or batch operation)
- AND schemas with NO incoming `onDelete` references can skip all referential integrity checks on their objects

#### Scenario: Batch deletion efficiency
- GIVEN a bulk delete operation deleting 100 objects of the same schema
- WHEN the schema relation index is built
- THEN it is built ONCE and reused for all 100 deletions
- AND dependent object lookups are batched where possible (single query per dependent schema)

### Requirement: API Error Response Format
When deletion is blocked, the API MUST return structured error details.

#### Scenario: RESTRICT blocks deletion
- GIVEN ServiceType T is referenced by Services S1 and S2 (RESTRICT)
- WHEN `DELETE /api/objects/{register}/service-type/{T.uuid}` is called
- THEN the API MUST return HTTP 409 Conflict with body:
```json
{
  "error": "DELETION_BLOCKED",
  "message": "Cannot delete object: 2 dependent object(s) block deletion",
  "blockers": [
    {
      "objectUuid": "s1-uuid",
      "objectTitle": "Web Service",
      "schema": "service-schema",
      "property": "serviceType",
      "action": "RESTRICT"
    },
    {
      "objectUuid": "s2-uuid",
      "objectTitle": "API Service",
      "schema": "service-schema",
      "property": "serviceType",
      "action": "RESTRICT"
    }
  ]
}
```

#### Scenario: Chained RESTRICT blocks deletion
- WHEN deletion is blocked by a RESTRICT found through a cascade chain
- THEN the response MUST include the full chain path so the user understands why
- AND the `chain` field shows the path from the deleted object to the blocker

### Requirement: Integration with Soft Delete
Referential integrity MUST work with OpenRegister's existing soft-delete mechanism.

#### Scenario: Cascade respects soft delete
- GIVEN cascade deletion is triggered
- WHEN dependent objects are deleted
- THEN they MUST be soft-deleted (not hard-deleted)
- AND their `deleted` metadata includes cascade information

#### Scenario: Already soft-deleted objects are skipped
- GIVEN object B references object A (CASCADE)
- AND object B is already soft-deleted
- WHEN object A is deleted
- THEN object B is NOT processed again (already deleted)

#### Scenario: Restore does not reverse cascade
- GIVEN object A was deleted, triggering cascade delete of B
- WHEN object A is restored (deleted field cleared)
- THEN object B is NOT automatically restored
- AND restoring cascaded objects is a separate manual operation
