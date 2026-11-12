# object-validation: delta for validate-stored-null-as-absent

## ADDED Requirements

### Requirement: A stored null on an optional property validates as absent

When a non-required property holds null and its enum does not list null, validation SHALL treat the property as absent. This SHALL hold when the enum lists the empty string, which OpenRegister stores as null. A required property holding null SHALL still fail.

#### Scenario: a contract saved without a renewal outcome is edited

- GIVEN a schema with optional `renewalLeadOutcome` (string, enum "", won, lost)
- AND an object stored with `renewalLeadOutcome` null
- WHEN the object is validated for an update
- THEN validation succeeds

#### Scenario: a required property is null

- GIVEN a required string property with an enum
- WHEN it holds null
- THEN validation fails
