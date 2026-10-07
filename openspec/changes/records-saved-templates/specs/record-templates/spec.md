# record-templates

## ADDED Requirements

### Requirement: A user can save and share a named record template

The system SHALL store record templates with a name, a description, a register, a
schema, a set of values, an owner, and a private, group-shared or public visibility,
through `/api/record-templates`. Only the owner or an administrator MAY change or
delete a template. The list MUST return only templates the caller may use, which
requires create rights on the template's schema.

#### Scenario: A caseworker saves a melding as a template

- **GIVEN** a caseworker viewing a melding about water damage
- **WHEN** they choose Save as template, keep category, priority and omschrijving, name it "Melding wateroverlast" and share it with the group `kcc`
- **THEN** `GET /api/record-templates?schema=meldingen` returns the template for any `kcc` member who may create meldingen
- @e2e exclude {specified only; task 2.1 adds tests/e2e/record-templates.spec.ts}

#### Scenario: A user without create rights is not offered the template

- **GIVEN** the shared template and a `kcc` member with read-only access to meldingen
- **WHEN** that member lists record templates for the schema
- **THEN** the template is not in the list
- @e2e exclude {specified only; task 1.2 adds the API test}

### Requirement: A new record can start from a template

The create dialog SHALL offer the templates the user may use for the schema, SHALL
fill the form with the template's values, and SHALL drop any value whose property no
longer exists or no longer fits, telling the user which. The record MUST be saved
through the normal save path.

#### Scenario: A caseworker starts a melding from a template

- **GIVEN** the template "Melding wateroverlast"
- **WHEN** a caseworker opens New melding and chooses the template
- **THEN** the form shows the template's category, priority and omschrijving
- **AND** after the caseworker adds an address and saves, the melding is stored with those values
- @e2e exclude {specified only; task 2.2 adds the create path to tests/e2e/record-templates.spec.ts}

#### Scenario: A value that no longer fits is left out

- **GIVEN** a template whose `prioriteit` value `urgent` is no longer in the property's enum
- **WHEN** a caseworker starts a record from it
- **THEN** the form leaves `prioriteit` empty and says the template value was dropped
- @e2e exclude {specified only; task 2.2 adds the unit test}
