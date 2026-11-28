# frontend-app-bootstrap

## ADDED Requirements

### Requirement: A route sidebar SHALL load when its route shows it

The app MUST load the code of a route sidebar (dashboard, search, register,
registers, deleted, entities, audit trail, search trail) when a route that
shows it is first shown, not with every page. Which sidebar a route shows MUST
NOT change.

#### Scenario: A page loads only its own sidebar

- **GIVEN** the registers list route
- **WHEN** the page renders
- **THEN** the registers sidebar SHALL be loaded and shown
- **AND** the other route sidebars SHALL NOT be part of the code the page loads

#### Scenario: A route without a sidebar loads none

- **GIVEN** the schemas list route
- **WHEN** the page renders
- **THEN** no route sidebar SHALL be loaded
