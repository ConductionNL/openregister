# admin-list-views

## ADDED Requirements

### Requirement: Register cards mount their schema-row action menus on demand

A register card on the Registers page SHALL NOT mount an action menu per schema
row during its first render. Each schema row SHALL show a trigger labelled
"Actions" with `aria-haspopup="menu"`. The card SHALL mount the row menus when it
is first pointed at or when focus enters it. A trigger activated before that
SHALL mount the menus and open and focus the menu of its own row. The actions in
each menu SHALL be the same as before.

#### Scenario: the Registers page renders its cards
- **GIVEN** a register with three schemas
- **WHEN** its card renders
- **THEN** the card SHALL have one action menu (its header) and three row triggers
- @e2e exclude {covered by RegisterSchemaCard.render.spec.js; the row menu itself is unchanged}

#### Scenario: a user points at a card
- **GIVEN** a register card that has not been pointed at or focused
- **WHEN** the pointer enters it or focus moves into it
- **THEN** each schema row SHALL show its action menu

#### Scenario: a row trigger is activated directly
- **GIVEN** a register card whose row menus are not mounted
- **WHEN** a row's trigger is activated
- **THEN** that row's action menu SHALL open and receive focus
