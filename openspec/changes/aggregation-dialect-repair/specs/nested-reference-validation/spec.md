## Purpose

Ensures a `$ref` reference nested inside an array-of-objects property validates a valid UUID on
direct object writes, closing a gap between the existing support for top-level `$ref` properties and
array items that are themselves `$ref` strings.

## ADDED Requirements

### Requirement: A nested `$ref` property inside an array-of-objects item MUST validate a valid UUID
When a schema property is an array whose items are objects, and one of those item objects' own
properties is declared with `$ref` (referencing another schema), a direct object write supplying a
valid UUID for that nested property MUST validate successfully. This MUST hold identically to how a
top-level scalar `$ref` property and an array item that is itself a `$ref` string already validate.

#### Scenario: A valid UUID in a nested array-of-objects $ref property is accepted
- **GIVEN** a schema `voordracht` with property `candidates` (array of objects), where each item
  object has a property `person` declared `{"type": "string", "$ref": "person-schema-slug",
  "format": "uuid"}`
- **AND** a `person` object with a valid UUID exists
- **WHEN** an object is directly written with `candidates: [{"person": "<valid-person-uuid>", ...}]`
- **THEN** the write MUST succeed
- **AND** MUST NOT raise `"Unresolved reference: schema:///Person#"`

#### Scenario: The same nested shape continues to work for a second-level array (regression guard)
- **GIVEN** the same schema `voordracht`, with `candidates` containing two items each with a
  `person` UUID
- **WHEN** the object is written
- **THEN** both nested `person` references MUST validate independently
- **AND** an invalid (non-UUID, non-existent) value on either MUST be rejected without affecting
  validation of the other

#### Scenario: Existing single-level $ref support is unaffected
- **GIVEN** a schema with a top-level scalar `$ref` property, and a separate schema with an array
  property whose items are themselves `$ref` strings (not objects)
- **WHEN** either is validated
- **THEN** both MUST continue to validate exactly as before this change
