## ADDED Requirements

### Requirement: A seeded view keeps its uuid

A view an example set writes SHALL have a uuid derived from the fragment's app and the view's key. A refresh SHALL update a view the record holds in place, keeping its uuid. A held view that no longer exists SHALL be written again under its derived uuid.

#### Scenario: a refresh
- **GIVEN** an installed view "Termijn deze week"
- **WHEN** the set is refreshed with a changed name
- **THEN** the view has the same uuid and the new name, and a menu entry that names it still opens it

#### Scenario: remove and install
- **WHEN** the set is removed and installed again
- **THEN** the view has the uuid it had before
