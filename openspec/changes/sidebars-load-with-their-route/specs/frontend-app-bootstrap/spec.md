# frontend-app-bootstrap

## ADDED Requirements

### Requirement: A route sidebar SHALL load when its route shows it

The app MUST load the code of the search, deleted, entities, audit trail and
search trail sidebars when a route that shows one is first shown, not with
every page. The dashboard, registers and register sidebars MAY load with the
app, since they show with the first render of the most used pages. Which
sidebar a route shows MUST NOT change.

#### Scenario: A page does not load the sidebars of other routes

- **GIVEN** the registers list route
- **WHEN** the page renders
- **THEN** the registers sidebar SHALL be shown
- **AND** the search, deleted, entities, audit trail and search trail sidebars
  SHALL NOT be part of the code the page loads

#### Scenario: A route without a sidebar loads none

- **GIVEN** the schemas list route
- **WHEN** the page renders
- **THEN** no route sidebar SHALL be shown
