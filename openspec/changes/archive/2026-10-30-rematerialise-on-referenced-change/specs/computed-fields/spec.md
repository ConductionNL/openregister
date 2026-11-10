# computed-fields Delta: rematerialise-on-referenced-change

## ADDED Requirements

### Requirement: A referenced change refreshes the materialised values that read it

When an object is updated, OpenRegister SHALL re-save the objects in the same register that point at it through a
declared `x-openregister-references` entry (mode `relatedObject`), when one of their enabled materialised calculations
reads `@ref.<name>.<field>` for a field whose value changed. It SHALL re-save at most 200 such objects per dependency
within the save and log the rest with the command that refreshes them, and SHALL NOT fail the save that triggered it.

#### Scenario: A case type starts listing flows

- **GIVEN** a case whose `hasStartableFlows` materialises `@ref.caseType.startableFlows` and is false
- **WHEN** its case type is saved with a flow in `startableFlows`
- **THEN** the case is re-saved and its `hasStartableFlows` is true

#### Scenario: A change no calculation reads

- **GIVEN** the same case
- **WHEN** its case type's title changes and `startableFlows` does not
- **THEN** no case is re-saved
