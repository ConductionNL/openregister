# apphost-store-plane

## ADDED Requirements

### Requirement: The store reports where each item is installed

The engine SHALL record, per app, every store item that was installed whole and
the target it went into, the empty target for an install without one. A store
search SHALL answer with `installed`: each recorded item slug and its targets.
An install that failed or was refused in part SHALL NOT be recorded. Each app
SHALL read only its own record, and only ids SHALL be recorded.

#### Scenario: a skill tree installed into one world is reported there

- **GIVEN** a store declaring an install target
- **WHEN** an administrator installs `fantasy-skills` with target `world-1` and every component installs
- **THEN** the next search answers `installed` with `fantasy-skills` and `world-1`

#### Scenario: an item installed into two worlds is reported in both

- **GIVEN** `fantasy-skills` installed into `world-1`
- **WHEN** it is installed again into `world-2`
- **THEN** `installed` lists `world-1` and `world-2` for it, once each

#### Scenario: a partial install is not reported

- **WHEN** an install reports `success: false`
- **THEN** nothing is recorded and no card can claim the item is installed

#### Scenario: one app does not see another's installs

- **GIVEN** larpinq installed `a-set`
- **WHEN** dossiq's store is searched
- **THEN** its `installed` does not list `a-set`
