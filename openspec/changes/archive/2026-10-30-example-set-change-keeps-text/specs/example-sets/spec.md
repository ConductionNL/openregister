# example-sets Delta: example-set-change-keeps-text

## ADDED Requirements

### Requirement: A change sends a text property as text

When a change, a references refresh or a remove writes an object back, a top-level property its schema types as text only (`string`, optionally nullable) whose value the store read back as a structure SHALL be validated and written as JSON text, whether or not the change names it in `jsonFields`.

#### Scenario: A case with a JSON-text geometry
- **GIVEN** a case whose `geometry` is declared as JSON text and read back as a structure
- **WHEN** a change sets another field of it, the set is checked again on the kept case, and the set is removed
- **THEN** the check SHALL pass, the change SHALL be created, and remove SHALL report no failure
