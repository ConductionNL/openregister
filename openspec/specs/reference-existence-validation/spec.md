# reference-existence-validation Specification

## Purpose
Add configurable validation that ensures objects referenced via `$ref` properties actually exist before saving. When a schema property has `$ref` pointing to another schema and `validateReference` is enabled, the save pipeline checks that the UUID stored in that property corresponds to an existing object in the target schema.

## ADDED Requirements

### Requirement: Schema properties MUST support a validateReference configuration
Schema property definitions MUST accept a `validateReference` boolean flag that controls whether referenced object existence is checked on save.

#### Scenario: Property with validateReference enabled
- GIVEN a schema `order` with property:
  ```json
  {
    "assignee": {
      "type": "string",
      "$ref": "person-schema-id",
      "validateReference": true
    }
  }
  ```
- WHEN an object is saved with `assignee` = `"existing-person-uuid"`
- AND a person object with UUID `"existing-person-uuid"` exists in the referenced schema
- THEN the save MUST succeed

#### Scenario: Property with validateReference disabled (default)
- GIVEN a schema `order` with property:
  ```json
  {
    "assignee": {
      "type": "string",
      "$ref": "person-schema-id"
    }
  }
  ```
- WHEN an object is saved with `assignee` = `"nonexistent-uuid"`
- THEN the save MUST succeed (no existence check performed)
- AND `validateReference` defaults to `false` when not specified

### Requirement: Save MUST reject objects with invalid references when validateReference is enabled
When `validateReference` is `true`, the save pipeline MUST verify that the referenced UUID exists in the target schema.

#### Scenario: Single-value reference to nonexistent object
- GIVEN a schema with `validateReference: true` on property `assignee` referencing schema `person`
- WHEN an object is saved with `assignee` = `"nonexistent-uuid"`
- AND no person object with UUID `"nonexistent-uuid"` exists
- THEN the save MUST fail with HTTP 422
- AND the error message MUST include the property name, the invalid UUID, and the target schema name
- AND the error message format MUST be: `"Referenced object 'nonexistent-uuid' not found in schema 'person' for property 'assignee'"`

#### Scenario: Array reference with one invalid UUID
- GIVEN a schema with property:
  ```json
  {
    "members": {
      "type": "array",
      "items": {
        "type": "string",
        "$ref": "person-schema-id"
      },
      "validateReference": true
    }
  }
  ```
- WHEN an object is saved with `members` = `["valid-uuid-1", "nonexistent-uuid", "valid-uuid-2"]`
- AND `valid-uuid-1` and `valid-uuid-2` exist but `nonexistent-uuid` does not
- THEN the save MUST fail with HTTP 422
- AND the error message MUST identify `nonexistent-uuid` as the invalid reference

#### Scenario: Array reference with all valid UUIDs
- GIVEN a schema with `validateReference: true` on an array property
- WHEN an object is saved with an array of UUIDs that all exist in the target schema
- THEN the save MUST succeed

#### Scenario: Null or empty reference value
- GIVEN a schema with `validateReference: true` on a non-required property
- WHEN an object is saved with the property set to `null` or `""`
- THEN the save MUST succeed (null/empty references are not validated)

### Requirement: Reference validation MUST resolve target schema via existing $ref resolution
The validation MUST use the same `resolveSchemaReference()` mechanism that SaveObject already uses for `$ref` resolution.

#### Scenario: $ref as schema ID
- GIVEN a property with `$ref: "42"` and `validateReference: true`
- WHEN validation resolves the target schema
- THEN it MUST use `resolveSchemaReference("42")` to find the schema by numeric ID

#### Scenario: $ref as schema UUID
- GIVEN a property with `$ref: "550e8400-e29b-41d4-a716-446655440000"` and `validateReference: true`
- WHEN validation resolves the target schema
- THEN it MUST use `resolveSchemaReference()` to find the schema by UUID

#### Scenario: $ref as schema slug
- GIVEN a property with `$ref: "person"` and `validateReference: true`
- WHEN validation resolves the target schema
- THEN it MUST resolve `"person"` to the schema by slug match

### Requirement: Reference validation MUST work with the object's register context
The existence check MUST look for the referenced object in the correct register.

#### Scenario: Same-register reference
- GIVEN an object in register `procest` with a `$ref` property pointing to schema `person`
- AND `person` schema exists in register `procest`
- WHEN the reference is validated
- THEN the existence check MUST query register `procest` for the person object

#### Scenario: Cross-register reference with explicit register
- GIVEN a property with:
  ```json
  {
    "owner": {
      "type": "string",
      "$ref": "person-schema-id",
      "register": "shared-register-id",
      "validateReference": true
    }
  }
  ```
- WHEN the reference is validated
- THEN the existence check MUST query the register specified in `register` config, not the object's own register

### Requirement: Reference validation MUST NOT impact update operations for unchanged references
On updates (PUT/PATCH), properties whose values have not changed MUST NOT be re-validated.

#### Scenario: Update with unchanged reference
- GIVEN an existing object with `assignee` = `"person-uuid"` and `validateReference: true`
- AND the referenced person has since been deleted
- WHEN the object is updated with `assignee` = `"person-uuid"` (same value)
- THEN the save MUST succeed (unchanged values are not re-validated)

#### Scenario: Update with changed reference
- GIVEN an existing object with `assignee` = `"old-person-uuid"`
- WHEN the object is updated with `assignee` = `"new-person-uuid"`
- AND `new-person-uuid` does not exist
- THEN the save MUST fail with HTTP 422

### Current Implementation Status

**Fully implemented.** All core requirements are in place:

- `lib/Service/Object/SaveObject.php`:
  - `validateReferences()` (line ~3335) -- iterates schema properties, finds those with `$ref` and `validateReference: true`, checks existence
  - `validateReferenceExists()` (line ~3416) -- validates individual UUID against target schema using `resolveSchemaReference()`
  - `resolveSchemaReference()` (line ~326) -- resolves `$ref` by numeric ID, UUID, or slug
  - Called in both `createObject()` (line ~3160) and `updateObject()` (line ~3238)
  - On updates, unchanged references are skipped (line ~3239: compares old vs new data)
- Array references are validated (each UUID in array checked individually)
- Null/empty values are skipped (not validated)
- Cross-register reference support via `register` property config
- Returns HTTP 422 with descriptive error messages including property name, UUID, and target schema name

**What is NOT yet implemented:**
- All requirements appear to be implemented as specified

### Standards & References
- JSON Schema `$ref` keyword (RFC draft-bhutton-json-schema-01)
- OpenRegister internal schema property format (custom `validateReference` extension to JSON Schema)
- HTTP 422 Unprocessable Entity (RFC 4918)

### Specificity Assessment
- **Specific enough to implement?** Yes -- this spec is fully implemented and the scenarios match the code behavior.
- **Missing/ambiguous:** Nothing significant -- the spec is well-defined and matches the implementation.
- **Open questions:** None -- this spec is complete.

## Nextcloud Integration Analysis

**Status**: Implemented

**Existing Implementation**: SaveObject.php contains validateReferences() which iterates schema properties to find those with $ref and validateReference: true, then checks existence via validateReferenceExists(). The resolveSchemaReference() method resolves $ref by numeric ID, UUID, or slug. Validation is called in both createObject() and updateObject() flows. On updates, unchanged references are skipped by comparing old vs new data. Array references are validated individually per UUID. Null/empty values are skipped. Cross-register reference support is available via the register property configuration. HTTP 422 responses include descriptive error messages with property name, UUID, and target schema name. RelationHandler and EntityRelation entity manage the relation graph with contracts/uses/used endpoints.

**Nextcloud Core Integration**: The reference validation is integrated into the object save pipeline which runs within Nextcloud's request lifecycle. Validation occurs during the save transaction, ensuring referential integrity before data is committed to the database via Nextcloud's IDBConnection. Events are fired on relation changes through Nextcloud's IEventDispatcher, allowing other apps or listeners to react to changes in the object dependency graph. The EntityRelation entity is stored in Nextcloud's database using standard OCP\AppFramework\Db\Entity patterns, making relation data queryable alongside other OpenRegister entities.

**Recommendation**: The reference existence validation is fully implemented and well-integrated with Nextcloud's database and event infrastructure. The implementation correctly validates during object save, fires events on relation changes, and supports cross-register references. No significant Nextcloud integration gaps exist. Minor enhancements could include: caching resolved schema references in Nextcloud's ICache (OCP\ICache) to avoid repeated database lookups during bulk operations with many cross-references, and exposing relation graph data through Nextcloud's search providers for discoverability of connected objects.
