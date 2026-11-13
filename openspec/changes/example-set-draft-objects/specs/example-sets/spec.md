# example-sets Delta

## ADDED Requirements

### Requirement: An object may be a draft of a portal subject

An object's `data` MAY declare `@self` with exactly two keys: `status` with the value `draft`, and an `owner` of the form `<scope>:<reference>`, placeholders allowed. Install SHALL write such an object in status `draft`, owned by that owner. The check SHALL refuse any other `@self` key, any other status, and an owner that a scoped draft owner may not have. Nothing SHALL be written when the check refuses. The `@self` declaration SHALL NOT be validated against the object's schema.

#### Scenario: Sanne's saved Woo request
- **GIVEN** portaliq's fragment declares a Woo request case with `@self: {status: draft, owner: "portal:{{cast:sanne.uid}}"}`
- **WHEN** the set is installed
- **THEN** the case SHALL exist in status `draft`, owned by `portal:sanne.devries`, so the portal offers Sanne to continue it

#### Scenario: A published object cannot be declared this way
- **GIVEN** an object with `@self: {status: "published"}`
- **WHEN** the set is checked
- **THEN** the check SHALL refuse it and nothing SHALL be written
