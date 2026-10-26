# example-sets Delta: example-set-refresh-leaves-unset-fields

## ADDED Requirements

### Requirement: A refresh does not send fields the declaration leaves unset

On `--refresh` a field of the stored object that reads back as null and that the declaration does not name SHALL NOT be sent, so the refresh writes what the check validated.

#### Scenario: A string field the declaration does not name
- **GIVEN** a kept object whose schema refuses null for `intakeChannel`, which the declaration does not name and which reads back as null
- **WHEN** the set is refreshed
- **THEN** the object SHALL be refreshed and the install SHALL report ok
