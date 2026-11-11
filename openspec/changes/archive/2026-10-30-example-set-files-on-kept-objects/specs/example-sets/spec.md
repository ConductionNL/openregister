# example-sets Delta

## ADDED Requirements

### Requirement: A later install attaches the files an object gained

When an install keeps an object the set wrote earlier and the record holds no files for it, the install SHALL attach the files the object's declaration names and SHALL record them. A later install SHALL attach nothing more.

#### Scenario: Files added to a case after it was installed
- **GIVEN** a case written by an earlier install, with no files recorded
- **AND** its declaration now names seven files
- **WHEN** the set is installed again
- **THEN** the seven files SHALL be attached to that case and recorded, and the case SHALL not be written again
