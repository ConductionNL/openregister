# entity-management-modals (delta)

## ADDED Requirements

### Requirement: A schema's fields are built in a form (REQ-MSR-004)

A functional administrator SHALL be able to add, edit and remove a schema's properties from the schema page without writing JSON. The add property action SHALL open a property form offering the name, title, description, type, format, required flag, default value, relation target and concept scheme, and saving the form SHALL save the schema through the schema store. A property saved in the form SHALL be usable on the next object create, with no restart.

#### Scenario: A field is added in the form
- **GIVEN** a functional administrator on the schema page of `melding`
- **WHEN** they choose add property, enter the name `prioriteit`, pick type string with values `laag`, `normaal`, `hoog`, and save
- **THEN** the schema lists `prioriteit`
- **AND** a new `melding` can be created with `prioriteit: hoog`
- @e2e exclude {task 3.1 adds tests/e2e/ci/schema-property-form.spec.ts}

#### Scenario: An invalid property is not saved
- **GIVEN** the property form open
- **WHEN** the administrator saves without a name
- **THEN** the form shows that the name is required and the schema is not saved
- @e2e exclude {task 3.1}
