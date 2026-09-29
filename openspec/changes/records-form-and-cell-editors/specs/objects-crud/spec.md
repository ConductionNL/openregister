# objects-crud Specification (delta)

## ADDED Requirements

### Requirement: REQ-RFCE-001 The record form gives each declared field its own editor

The record form SHALL render a property with an `enum` (or a `oneOf` of constants) as a select of the declared values, a file property as a file picker, and a property of a register that declares languages as one input per language.

#### Scenario: an enum field is a choice list

- **GIVEN** a schema property `status` with enum `open`, `closed`
- **WHEN** a record editor opens the edit dialog of a record on /tables
- **THEN** the `status` field is a select offering `open` and `closed`, and saving sends the chosen value
- @e2e exclude {specified only; task 1 adds the test}

#### Scenario: a translatable field has a tab per language

- **GIVEN** a register with languages `nl` and `en` and a schema property `title`
- **WHEN** a record editor opens the edit dialog
- **THEN** the `title` field shows an input for `nl` and one for `en`, and saving stores both variants
- @e2e exclude {specified only; task 1 adds the test}

### Requirement: REQ-RFCE-002 A cell in the records list can be edited in place

A user with update rights on a record SHALL be able to edit a scalar field directly in its cell on the records list. The save SHALL use the same PATCH as the record form, and a refused save SHALL show the server message in the cell and keep the old value.

#### Scenario: a record editor fixes a value in the list

- **GIVEN** a records list on /tables showing a text column `reference`
- **WHEN** a record editor double clicks the cell, types a new value and presses Enter
- **THEN** the record is saved with the new value and the cell shows it
- @e2e exclude {specified only; task 2 adds the test}

#### Scenario: a reader cannot edit

- **GIVEN** a user with read rights only
- **WHEN** they double click a cell
- **THEN** the record modal opens as before and no inline editor appears
- @e2e exclude {specified only; task 2 adds the test}
