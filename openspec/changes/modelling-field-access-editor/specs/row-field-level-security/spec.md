# row-field-level-security

## ADDED Requirements

### Requirement: Field access is edited in the property editor

The property editor SHALL offer a table of groups with a Read and a Change switch per
group that writes the property's `authorization` block in the shape
`PropertyRbacHandler` reads. An empty table MUST leave the property without an
`authorization` block. A rule that carries a `match` condition MUST be shown read-only
and MUST NOT be changed by the editor.

#### Scenario: An HR administrator hides the salary field

- **GIVEN** a functional administrator editing the property `salaris` of the schema `medewerker`
- **WHEN** they tick Read and Change for the group `hr` only and save
- **THEN** the schema's `salaris` property carries `authorization.read` and `authorization.update` naming `hr`
- **AND** a user outside `hr` opening a medewerker in the object detail page does not see `salaris`
- @e2e exclude {specified only; task 2.1 adds tests/e2e/field-access-editor.spec.ts}

#### Scenario: A rule with a condition is not overwritten

- **GIVEN** a property whose `authorization.read` carries a `match` on `_organisation`
- **WHEN** an administrator opens the property editor
- **THEN** the rule is shown read-only with a note that it is edited as JSON
- **AND** saving the property keeps the rule unchanged
- @e2e exclude {specified only; task 1.1 adds the component test}

### Requirement: The editor warns about a required field a creator cannot fill

The editor SHALL warn when a property is required and a group that may create objects
may not change it.

#### Scenario: A required field locked for the intake group

- **GIVEN** a required property `besluitdatum` and a group `intake` that may create objects
- **WHEN** the administrator leaves Change off for `intake`
- **THEN** the editor shows a warning that `intake` cannot save a new object
- @e2e exclude {specified only; task 1.2 adds the component test}

### Requirement: The client knows which fields it may not change

The object render SHALL carry `@self.readOnlyProperties` listing the properties the
current user may read but not update. The server MUST still refuse a write to them,
with 403 and the names of the refused properties.

#### Scenario: A caseworker sees a locked field and cannot change it through the API

- **GIVEN** a property `besluitdatum` that only the group `teamleiders` may change
- **WHEN** a caseworker outside that group opens the object and sends a PATCH changing `besluitdatum`
- **THEN** the form shows `besluitdatum` disabled
- **AND** the PATCH is refused with 403 naming `besluitdatum`
- @e2e exclude {specified only; task 2.3 adds the disabled check and task 2.2 the API refusal}
